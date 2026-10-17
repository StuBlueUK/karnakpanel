<?php
declare(strict_types=1);
class EmailController extends Controller {
    public function list(): void {
        Auth::requireLogin();
        $u = Auth::user();
        $q = substr(trim((string)($_GET['q'] ?? '')), 0, 64);
        $like = ($q !== '') ? '%'.str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], strtolower($q)).'%' : null;
        $p = Helpers::paginate((int)($like === null
            ? Database::query("SELECT COUNT(*) c FROM email_accounts WHERE user_id=?", [$u['id']])->fetch()['c']
            : Database::query("SELECT COUNT(*) c FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE e.user_id=? AND (e.localpart LIKE ? OR d.domain LIKE ?)", [$u['id'], $like, $like])->fetch()['c']), max(1, (int)($_GET['page'] ?? 1)));
        $pg = $p['pg']; $pages = $p['pages']; $total = $p['total'];
        $emails = ($like === null)
            ? Database::query("SELECT e.*, d.domain FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE e.user_id=? ORDER BY d.domain, e.localpart LIMIT ? OFFSET ?", [$u['id'], (int)$p['limit'], (int)$p['offset']])->fetchAll()
            : Database::query("SELECT e.*, d.domain FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE e.user_id=? AND (e.localpart LIKE ? OR d.domain LIKE ?) ORDER BY d.domain, e.localpart LIMIT ? OFFSET ?", [$u['id'], $like, $like, (int)$p['limit'], (int)$p['offset']])->fetchAll();
        // live usage per mailbox (Dovecot accounting; failures show as —, never fatal)
        foreach ($emails as &$e) {
            $e['used_mb'] = null;
            try {
                $r = ServiceManager::run('mail-usage.sh', [$e['localpart'].'@'.$e['domain']]);
                if (($r['code'] ?? 1) === 0 && preg_match('/^[\d.]+$/', trim($r['out']))) $e['used_mb'] = trim($r['out']);
            } catch (Throwable $t) {}
        }
        unset($e);
        $forwards = [];
        try { $forwards = Database::query("SELECT f.*, d.domain FROM email_forwards f JOIN domains d ON d.id=f.domain_id WHERE f.user_id=?", [$u['id']])->fetchAll(); } catch (Throwable $e) {}
        $domains = DomainModel::forUser((int)$u['id']);
        $scores = [];
        try { foreach (Database::query("SELECT s.*, d.domain FROM mail_scores s JOIN domains d ON d.id=s.domain_id WHERE d.user_id=?", [$u['id']])->fetchAll() as $s) $scores[$s['domain']] = $s; } catch (Throwable $e) {}
        $filters = [];
        try { $filters = Database::query("SELECT f.*, d.domain FROM email_filters f JOIN domains d ON d.id=f.domain_id WHERE f.user_id=? ORDER BY d.domain, f.localpart, f.id", [$u['id']])->fetchAll(); } catch (Throwable $e) {}
        $lists = [];
        try {
            $lists = Database::query("SELECT l.*, d.domain FROM email_lists l JOIN domains d ON d.id=l.domain_id WHERE l.user_id=? ORDER BY d.domain, l.localpart", [$u['id']])->fetchAll();
            foreach ($lists as &$l) {
                $l['members'] = Database::query("SELECT * FROM email_list_members WHERE list_id=? ORDER BY address", [$l['id']])->fetchAll();
            }
            unset($l);
        } catch (Throwable $e) {}
        $smtpDefault = '200';
        try {
            $v = Database::query("SELECT value FROM settings WHERE name='smtp_default'")->fetch()['value'] ?? null;
            if ($v !== null && preg_match('/^[0-9]{1,6}$/', (string)$v)) $smtpDefault = (string)$v;
        } catch (Throwable $e) {}
        $listDefault = '10';
        try {
            $v = Database::query("SELECT value FROM settings WHERE name='list_posts_default'")->fetch()['value'] ?? null;
            if ($v !== null && preg_match('/^[0-9]{1,6}$/', (string)$v)) $listDefault = (string)$v;
        } catch (Throwable $e) {}
        // weekend scan-skip (Exim acl_check_data): Sat/Sun spamd bypass to save
        // CPU. Default off — missing setting means always scan.
        $weekendSkip = '0';
        try {
            $v = Database::query("SELECT value FROM settings WHERE name='spam_weekend_skip'")->fetch()['value'] ?? null;
            if ($v === '1') $weekendSkip = '1';
        } catch (Throwable $e) {}
        // mail guard (cli/mail-guard.php): compromised-mailbox auto-hold.
        // Default ON — missing setting means guarding.
        $mailGuard = '1';
        try {
            $v = Database::query("SELECT value FROM settings WHERE name='mail_guard'")->fetch()['value'] ?? null;
            if ($v === '0') $mailGuard = '0';
        } catch (Throwable $e) {}
        $responders = [];
        try { $responders = Database::query("SELECT e.id, e.localpart, e.autoresp_subject, d.domain FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE e.user_id=? AND e.autoresp_enabled=1 ORDER BY d.domain, e.localpart", [$u['id']])->fetchAll(); } catch (Throwable $e) {}
        // mail authentication per domain: SPF/DMARC/policy rows from the zone
        // that holds them (a subdomain's live in its parent zone at prefixed
        // names — MailDns), DKIM via key files
        $auth = [];
        foreach ($domains as $d) {
            $t = MailDns::target($d);
            $row = ['id' => $d['id'], 'domain' => $d['domain'], 'spf' => false, 'spfval' => 'v=spf1 a mx ~all', 'dkim' => false, 'txt' => '', 'dmarc' => false,
                'mta_sts' => (string)($d['mta_sts_mode'] ?? 'off'), 'reports' => (int)($d['mail_reports'] ?? 0) === 1, 'remote' => ($d['mail_handling'] ?? 'local') === 'remote', 'dmarcval' => '',
                'zone' => $t['label'] !== '' ? $t['origin'] : '', 'dmarcinh' => '',
                'hosts' => ['spf' => MailDns::host($t, '@'), 'dkim' => MailDns::host($t, 'default._domainkey'), 'dmarc' => MailDns::host($t, '_dmarc')]];
            try {
                $zid = (int)($t['zone']['id'] ?? 0);
                $spf = Database::query("SELECT value FROM dns_records WHERE domain_id=? AND host=? AND type='TXT' AND value LIKE '%v=spf1%' LIMIT 1", [$zid, $row['hosts']['spf']])->fetch();
                if ($spf) { $row['spf'] = true; $row['spfval'] = $spf['value']; }
                $dm = Database::query("SELECT value FROM dns_records WHERE domain_id=? AND host=? AND type='TXT' AND value LIKE '%v=DMARC1%' LIMIT 1", [$zid, $row['hosts']['dmarc']])->fetch();
                $row['dmarc'] = (bool)$dm;
                if ($dm) $row['dmarcval'] = trim((string)$dm['value'], '"');
                // no record of its own: the parent's policy covers a subdomain
                // (sp=, else p=) — covered, not missing
                elseif ($t['label'] !== '' && ($ip = MailDns::inheritedP($t)) !== '') { $row['dmarc'] = true; $row['dmarcinh'] = "$ip from {$t['origin']}"; }
                // MTA-STS / TLS-RPT rows (copy-block for external nameservers)
                $row['policyrecs'] = [];
                $ph = array_map(fn($h) => MailDns::host($t, $h), ['mta-sts', '_mta-sts', '_smtp._tls']);
                foreach (Database::query("SELECT type, host, value FROM dns_records WHERE domain_id=? AND ((type='A' AND host=?) OR (type='TXT' AND host IN (?,?))) ORDER BY host", [$zid, $ph[0], $ph[1], $ph[2]])->fetchAll() as $pr) {
                    $row['policyrecs'][] = [$pr['type'], $pr['host'], trim((string)$pr['value'], '"')];
                }
                // autopilot: which policy step the reports say is safe now
                $row['ap'] = []; $row['dmarcp'] = '';
                if ($row['reports']) {
                    $j = MailAutopilot::dmarc($d);
                    $row['dmarcp'] = $j['p'];
                    if ($j['ok']) $row['ap'][] = 'DMARC p='.$j['next'];
                    if (MailAutopilot::mtaSts($d)['ok']) $row['ap'][] = 'MTA-STS enforce';
                }
                $st = ServiceManager::run('dkim-status.sh', [$d['domain']]);
                $lines = array_filter(array_map('trim', explode("\n", $st['out'] ?? '')));
                if (($lines[0] ?? '') === 'signed yes') {
                    $row['dkim'] = true;
                    $row['txt'] = implode("\n", array_slice($lines, 1));
                }
            } catch (Throwable $e) {}
            $auth[] = $row;
        }
        $this->view('email', compact('emails','forwards','domains','auth','responders','pg','pages','total','scores','smtpDefault','filters','lists','listDefault','weekendSkip','mailGuard','q') + ['relay' => ($u['role'] === 'admin' ? $this->relayStatus() : null)]);
    }
    public function create(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $u = Auth::user();
        $local = trim($_POST['local']??''); $domainId = (int)($_POST['domain_id']??0);
        $pass = (string)($_POST['password']??'');
        if (!preg_match('/^[a-z0-9._-]{1,64}$/i',$local) || strlen($pass)<8) { Helpers::flash('err','Bad email/password'); Helpers::redirect('/email'); }
        $d = Database::query("SELECT * FROM domains WHERE id=? AND user_id=?", [$domainId,$u['id']])->fetch();
        if (!$d) { Helpers::flash('err','Domain not found'); Helpers::redirect('/email'); }
        if (MailPolicyController::reserved((int)$d['id'], $local)) { Helpers::flash('err', "$local@{$d['domain']} receives DMARC/TLS reports here — turn report intake off first to use it"); Helpers::redirect('/email'); }
        // remote-handled domains serve mail at their provider — a mailbox
        // here would never receive anything (remote_route runs first)
        // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string -- not SQL: flash text, no query sink
        if (($d['mail_handling'] ?? 'local') === 'remote') { Helpers::flash('err', "Mail for {$d['domain']} routes to your external provider — switch it back to Local in Domains to create mailboxes here"); Helpers::redirect('/email'); }
        if ($err = Helpers::limitError($u, 'emails')) { Helpers::flash('err', $err); Helpers::redirect('/email'); }
        if ($err = Helpers::diskBlocked($u)) { Helpers::flash('err', $err); Helpers::redirect('/email'); }
        $hash = password_hash($pass, PASSWORD_BCRYPT);
        // Dovecot SHA512-CRYPT via doveadm would be ideal; bcrypt stored, synced by script
        Database::query("INSERT INTO email_accounts(user_id,domain_id,localpart,password_hash,quota_mb) VALUES(?,?,?,?,?)", [$u['id'],$domainId,$local,$hash,1024]);
        ServiceManager::run('email-add.sh', [$local.'@'.$d['domain'], $u['username']]);
        Helpers::flash('ok',"Mailbox $local@{$d['domain']} created.".MailDns::ensureInbound($d));
        Helpers::redirect('/email');
    }
    public function delete(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $u = Auth::user();
        $id = (int)($_POST['id'] ?? 0);
        $sql = ($u['role'] === 'admin')
            ? "SELECT e.*, d.domain FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE e.id=?"
            : "SELECT e.*, d.domain FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE e.id=? AND e.user_id=?";
        $args = ($u['role'] === 'admin') ? [$id] : [$id, $u['id']];
        $row = Database::query($sql, $args)->fetch();
        if (!$row) { Helpers::flash('err','Mailbox not found'); Helpers::redirect('/email'); }
        try {
            Database::query("DELETE FROM email_accounts WHERE id=?", [$row['id']]);
            Database::query("DELETE FROM email_filters WHERE domain_id=? AND localpart=?", [$row['domain_id'], $row['localpart']]);
            // lists living at this exact address die with the mailbox
            $ll = Database::query("SELECT id FROM email_lists WHERE domain_id=? AND localpart=?", [$row['domain_id'], $row['localpart']])->fetchAll();
            foreach ($ll as $l) {
                Database::query("DELETE FROM email_list_members WHERE list_id=?", [$l['id']]);
                Database::query("DELETE FROM email_lists WHERE id=?", [$l['id']]);
            }
            // drop the box from any list it subscribed to (stale members bounce)
            Database::query("DELETE FROM email_list_members WHERE address=?", [$row['localpart'].'@'.$row['domain']]);
            // alias line removed; ~/Maildir is shared between addresses and is kept
            $r = ServiceManager::run('email-remove.sh', [$row['localpart'].'@'.$row['domain']]);
            ServiceManager::run('autoreply-sync.sh', []);
            ServiceManager::run('email-filters-sync.sh', []);
            ServiceManager::run('list-sync.sh', []);
            Helpers::flash('ok', "Mailbox {$row['localpart']}@{$row['domain']} removed. ".($r['out'] ?? ''));
        } catch (Throwable $e) { Helpers::flash('err','Remove failed: '.$e->getMessage()); }
        Helpers::redirect('/email');
    }
    // Vacation autoresponder: one reply per sender per 7d (Exim once-DB),
    // delivered alongside the original (unseen). A forwarder on the same
    // address wins and suppresses the reply.
    private function responderRow(array $u, int $id): ?array {
        $sql = ($u['role'] === 'admin')
            ? "SELECT e.*, d.domain FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE e.id=?"
            : "SELECT e.*, d.domain FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE e.id=? AND e.user_id=?";
        $args = ($u['role'] === 'admin') ? [$id] : [$id, $u['id']];
        return Database::query($sql, $args)->fetch() ?: null;
    }
    public function autoresponder(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $u = Auth::user();
        $row = $this->responderRow($u, (int)($_POST['id'] ?? 0));
        if (!$row) { Helpers::flash('err','Mailbox not found'); Helpers::redirect('/email'); }
        $subject = trim((string)preg_replace('/\s+/', ' ', (string)($_POST['subject'] ?? '')));
        $text = trim((string)($_POST['text'] ?? ''));
        if ($subject === '' || mb_strlen($subject) > 190) { Helpers::flash('err','Subject required (max 190 chars, single line)'); Helpers::redirect('/email'); }
        if ($text === '' || mb_strlen($text) > 2000) { Helpers::flash('err','Message required (max 2000 chars)'); Helpers::redirect('/email'); }
        Database::query("UPDATE email_accounts SET autoresp_enabled=1, autoresp_subject=?, autoresp_text=? WHERE id=?", [$subject, $text, $row['id']]);
        $r = ServiceManager::run('autoreply-sync.sh', []);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Saved but sync failed: '.trim($r['out']));
        else Helpers::flash('ok', "Auto-reply ON for {$row['localpart']}@{$row['domain']}");
        Helpers::redirect('/email');
    }
    public function autoresponderDelete(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $u = Auth::user();
        $row = $this->responderRow($u, (int)($_POST['id'] ?? 0));
        if (!$row) { Helpers::flash('err','Mailbox not found'); Helpers::redirect('/email'); }
        Database::query("UPDATE email_accounts SET autoresp_enabled=0 WHERE id=?", [$row['id']]);
        ServiceManager::run('autoreply-sync.sh', []);
        Helpers::flash('ok', "Auto-reply OFF for {$row['localpart']}@{$row['domain']}");
        Helpers::redirect('/email');
    }
    // Passwords live in the panel DB (Dovecot reads them via SQL) — rotation is instant.
    public function createForward(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $u = Auth::user();
        $local = strtolower(trim($_POST['flocal'] ?? ''));
        $domainId = (int)($_POST['domain_id'] ?? 0);
        $targets = trim((string)($_POST['targets'] ?? ''));
        if ((!preg_match('/^[a-z0-9._-]{1,64}$/', $local) && $local !== '*') || $targets === '') { Helpers::flash('err','Bad forwarder'); Helpers::redirect('/email'); }
        $d = Database::query("SELECT * FROM domains WHERE id=? AND user_id=?", [$domainId, $u['id']])->fetch();
        if (!$d) { Helpers::flash('err','Domain not found'); Helpers::redirect('/email'); }
        if (MailPolicyController::reserved((int)$d['id'], $local)) { Helpers::flash('err', "$local@{$d['domain']} receives DMARC/TLS reports here — turn report intake off first to use it"); Helpers::redirect('/email'); }
        $clean = [];
        foreach (explode(',', $targets) as $t) {
            $t = strtolower(trim($t));
            if (!filter_var($t, FILTER_VALIDATE_EMAIL)) { Helpers::flash('err',"Bad target: $t"); Helpers::redirect('/email'); }
            if (str_contains($t, '|') || str_contains($t, '/')) { Helpers::flash('err','Pipes/paths refused'); Helpers::redirect('/email'); }
            if ($t === $local.'@'.$d['domain']) { Helpers::flash('err','Self-forward refused (loop)'); Helpers::redirect('/email'); }
            $clean[] = $t;
        }
        $joined = implode(', ', array_unique($clean));
        Database::query("INSERT INTO email_forwards(user_id,domain_id,localpart,targets) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE targets=VALUES(targets)",
            [$u['id'], $domainId, $local, $joined]);
        $r = ServiceManager::run('forward-add.sh', [$local.'@'.$d['domain'], $joined]);
        if (($r['code'] ?? 1) !== 0) { Database::query("DELETE FROM email_forwards WHERE domain_id=? AND localpart=?", [$domainId, $local]); Helpers::flash('err','Forwarder refused: '.trim($r['out'])); }
        else Helpers::flash('ok', "Forwarder $local@{$d['domain']} → $joined.".MailDns::ensureInbound($d));
        Helpers::redirect('/email');
    }
    public function deleteForward(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $u = Auth::user();
        $id = (int)($_POST['id'] ?? 0);
        $row = Database::query("SELECT f.*, d.domain FROM email_forwards f JOIN domains d ON d.id=f.domain_id WHERE f.id=? AND f.user_id=?", [$id, $u['id']])->fetch();
        if (!$row) { Helpers::flash('err','Forwarder not found'); Helpers::redirect('/email'); }
        Database::query("DELETE FROM email_forwards WHERE id=?", [$id]);
        ServiceManager::run('forward-remove.sh', [$row['localpart'].'@'.$row['domain']]);
        Helpers::flash('ok', "Forwarder {$row['localpart']}@{$row['domain']} removed");
        Helpers::redirect('/email');
    }
        public function dkimEnable(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $u = Auth::user();
        $domainId = (int)($_POST['domain_id'] ?? 0);
        $d = Database::query("SELECT * FROM domains WHERE id=? AND user_id=?", [$domainId, $u['id']])->fetch();
        if (!$d) { Helpers::flash('err','Domain not found'); Helpers::redirect('/email'); }
        $r = ServiceManager::run('dkim-gen.sh', [$d['domain']]);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','DKIM failed: '.trim($r['out']));
        // the TXT record itself is shown in Mail authentication below — keep
        // the flash short (the full key used to overflow the banner). A zone
        // hosted here gets it automatically (a subdomain's: in the parent).
        else {
            $t = MailDns::target($d);
            $at = MailDns::host($t, 'default._domainkey').($t['label'] !== '' ? " in the {$t['origin']} zone" : '');
            $pub = MailDns::publishDkim($d, MailDns::dkimValue($d['domain']));
            Helpers::flash('ok', "Signing on for {$d['domain']}. ".match (true) {
                $pub === 'published' => "DKIM TXT published at $at.",
                $pub === 'exists' => "Its DKIM TXT is already published at $at.",
                $pub === 'differs' => "A different DKIM key is already published at $at — replace it with the TXT shown in Mail authentication below, or receivers fail the signature.",
                $pub === 'cname' => "$at is a CNAME — point it at this key or remove it, then add the TXT shown below.",
                str_starts_with($pub, 'failed') => "Publishing the TXT at $at failed ($pub) — add it in DNS by hand.",
                default => "Publish the TXT shown in Mail authentication below at $at wherever its DNS is managed.",
            });
        }
        Helpers::redirect('/email');
    }
        public function setThreshold(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $u = Auth::user();
        $id = (int)($_POST['id'] ?? 0);
        $t = max(1, min(100, (float)($_POST['threshold'] ?? 5)));
        $row = Database::query("SELECT * FROM email_accounts WHERE id=? AND user_id=?", [$id, $u['id']])->fetch();
        if (!$row) { Helpers::flash('err','Mailbox not found'); Helpers::redirect('/email'); }
        Database::query("UPDATE email_accounts SET spam_threshold=? WHERE id=?", [$t, $id]);
        Helpers::flash('ok',"Spam flag threshold for {$row['localpart']} set to $t");
        Helpers::redirect('/email');
    }
    // SMTP relay (smarthost) for port-25-blocked boxes. Admin only. The
    // password travels via a 600 staging file the script shreds (never argv:
    // the panel logs full commands). Status shows host/user only — the
    // password is never read back anywhere.
    private function relayStatus(): array {
        $st = ['on' => false, 'host' => '', 'port' => '', 'user' => ''];
        try {
            $r = ServiceManager::run('smtp-relay.sh', ['status']);
            foreach (explode("\n", trim($r['out'] ?? '')) as $line) {
                if (preg_match('/^(host|port|user|on)=(.*)$/', trim($line), $m)) $st[$m[1]] = $m[2];
            }
            $st['on'] = ($st['on'] === '1');
        } catch (Throwable $e) {}
        return $st;
    }
    // SMTP relay (smarthost) for port-25-blocked boxes. Admin only. The
    // password travels via a 600 staging file the script shreds (never argv:
    // the panel logs full commands). Status shows host/user only — the
    // password is never read back anywhere.
    public function relaySave(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $host = strtolower(trim((string)($_POST['host'] ?? '')));
        $port = trim((string)($_POST['port'] ?? ''));
        $user = trim((string)($_POST['user'] ?? ''));
        $pass = (string)($_POST['pass'] ?? '');
        $hostOk = (bool)preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $host)
            || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        if (!$hostOk || !preg_match('/^[0-9]{1,5}$/', $port) || (int)$port < 1 || (int)$port > 65535) {
            Helpers::flash('err','Bad relay host/port'); Helpers::redirect('/email');
        }
        if ($user === '' || str_contains($user, ' ') || str_contains($user, ':') || strlen($user) > 190) {
            Helpers::flash('err','Bad relay username'); Helpers::redirect('/email');
        }
        if ($pass === '' || strlen($pass) > 500 || str_contains($pass, "\n") || str_contains($pass, ':')) {
            Helpers::flash('err','Bad relay password (no colons or newlines — Exim LOGIN framing)'); Helpers::redirect('/email');
        }
        // staging file (600): the script moves it root-only and shreds it —
        // the password never touches argv, logs, or the DB
        $stage = '/usr/local/skypanel/panel/storage/tmp-uploads';
        $real = realpath($stage) ?: $stage;
        @mkdir($real, 0700, true);
        $pwf = $real.'/relay-'.bin2hex(random_bytes(8)).'.pw';
        if (@file_put_contents($pwf, $pass) === false) { Helpers::flash('err','Staging failed'); Helpers::redirect('/email'); }
        @chmod($pwf, 0600);
        unset($pass);
        $r = ServiceManager::run('smtp-relay.sh', ['set', $host, $port, $user, $pwf]);
        // nosemgrep: php.lang.security.unlink-use.unlink-use -- random-hex stage path built above, zero user bytes
        @unlink($pwf);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Relay save failed: '.trim($r['out']));
        else Helpers::flash('ok', trim($r['out']).' — send a test mail below to prove it');
        Helpers::redirect('/email');
    }
    public function relayClear(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $r = ServiceManager::run('smtp-relay.sh', ['clear']);
        Helpers::flash('ok', trim($r['out'] ?? 'Relay off'));
        Helpers::redirect('/email');
    }
    public function relayTest(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $to = strtolower(trim((string)($_POST['to'] ?? '')));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { Helpers::flash('err','Bad test address'); Helpers::redirect('/email'); }
        $r = ServiceManager::run('smtp-relay.sh', ['test', $to]);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Relay test FAILED: '.trim($r['out']));
        else Helpers::flash('ok','Relay test sent to '.$to.' — check it arrived');
        Helpers::redirect('/email');
    }
    // Sending limits: per-mailbox override (blank = global default) + the
    // global default itself (admin only). Enforced by Exim at SMTP time.
    public function setLimit(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $u = Auth::user();
        $row = $this->responderRow($u, (int)($_POST['id'] ?? 0));
        if (!$row) { Helpers::flash('err','Mailbox not found'); Helpers::redirect('/email'); }
        $raw = trim((string)($_POST['limit'] ?? ''));
        if ($raw === '') {
            Database::query("UPDATE email_accounts SET smtp_limit=NULL WHERE id=?", [$row['id']]);
            Helpers::flash('ok',"Limit cleared for {$row['localpart']} (global default applies)");
        } else {
            if (!preg_match('/^[0-9]{1,6}$/', $raw) || (int)$raw < 1 || (int)$raw > 100000) { Helpers::flash('err','Limit 1..100000/hr (or blank for default)'); Helpers::redirect('/email'); }
            Database::query("UPDATE email_accounts SET smtp_limit=? WHERE id=?", [(int)$raw, $row['id']]);
            Helpers::flash('ok',"Limit for {$row['localpart']}: $raw/hr");
        }
        Helpers::redirect('/email');
    }
    public function limitDefault(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $raw = trim((string)($_POST['default'] ?? ''));
        if (!preg_match('/^[0-9]{1,6}$/', $raw) || (int)$raw < 1 || (int)$raw > 100000) { Helpers::flash('err','Default 1..100000/hr'); Helpers::redirect('/email'); }
        try {
            Database::query("INSERT INTO settings(name,value) VALUES('smtp_default',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [$raw]);
            Helpers::flash('ok',"Global sending default: $raw/hr");
        } catch (Throwable $e) {
            try {
                Database::query("CREATE TABLE IF NOT EXISTS settings (name VARCHAR(190) PRIMARY KEY, value TEXT NOT NULL)");
                Database::query("INSERT INTO settings(name,value) VALUES('smtp_default',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [$raw]);
                Helpers::flash('ok',"Global sending default: $raw/hr");
            } catch (Throwable $e2) { Helpers::flash('err','Save failed: '.$e2->getMessage()); }
        }
        Helpers::redirect('/email');
    }
    // Weekend spam-scan skip (admin): Sat/Sun bypass for spamd. Malware
    // rejects, rate caps and relay policy are unaffected. Takes effect
    // immediately — Exim reads the setting per message, no reload needed.
    // Mail guard release (admin): a false positive. The password stays as it
    // is — for a real compromise the owner's Reset PW is the cure instead.
    public function guardRelease(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $row = Database::query("SELECT e.id, e.localpart, e.send_hold_at, d.domain FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE e.id=?", [(int)($_POST['id'] ?? 0)])->fetch();
        if (!$row || $row['send_hold_at'] === null) { Helpers::flash('err','Mailbox not held'); Helpers::redirect('/email'); }
        Database::query("UPDATE email_accounts SET send_hold_at=NULL, send_hold_reason='', send_released_at=NOW() WHERE id=?", [$row['id']]);
        Helpers::log("mail-guard: released {$row['localpart']}@{$row['domain']} by admin ".(Auth::user()['username'] ?? '?'));
        $addr = $row['localpart'].'@'.$row['domain'];
        Helpers::flash('ok', $addr.': sending restored. Its frozen queued mail stays frozen — thaw or drop it in Admin → Mail queue');
        Helpers::redirect('/email');
    }
    // Mail guard on/off (admin). Holds already placed stay until released.
    public function guardToggle(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $on = (isset($_POST['on']) && $_POST['on'] === '1') ? '1' : '0';
        try {
            Database::query("INSERT INTO settings(name,value) VALUES('mail_guard',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [$on]);
            Helpers::flash('ok', $on === '1' ? 'Mail guard ON: hijacked-looking mailboxes are held automatically' : 'Mail guard OFF: no new automatic holds (existing holds stay until released)');
        } catch (Throwable $e) { Helpers::flash('err','Save failed: '.$e->getMessage()); }
        Helpers::redirect('/email');
    }
    public function weekendSkip(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $on = (isset($_POST['on']) && $_POST['on'] === '1') ? '1' : '0';
        try {
            Database::query("INSERT INTO settings(name,value) VALUES('spam_weekend_skip',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [$on]);
            Helpers::flash('ok', $on === '1' ? 'Weekend scan-skip ON: Sat/Sun mail skips spamd (marked X-Spam-Skipped)' : 'Weekend scan-skip OFF: spamd scans every day');
        } catch (Throwable $e) {
            try {
                Database::query("CREATE TABLE IF NOT EXISTS settings (name VARCHAR(190) PRIMARY KEY, value TEXT NOT NULL)");
                Database::query("INSERT INTO settings(name,value) VALUES('spam_weekend_skip',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [$on]);
                Helpers::flash('ok','Weekend scan-skip saved');
            } catch (Throwable $e2) { Helpers::flash('err','Save failed: '.$e2->getMessage()); }
        }
        Helpers::redirect('/email');
    }
    // Deliverability score (mail-score.sh, cached in mail_scores). Synchronous:
    public function scoreCheck(): void {        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $u = Auth::user();
        $domainId = (int)($_POST['domain_id'] ?? 0);
        $d = Database::query("SELECT * FROM domains WHERE id=? AND user_id=?", [$domainId, $u['id']])->fetch();
        if (!$d) { Helpers::flash('err','Domain not found'); Helpers::redirect('/email'); }
        $r = ServiceManager::run('mail-score.sh', [$d['domain']]);
        if (($r['code'] ?? 1) !== 0) { Helpers::flash('err','Check failed: '.trim($r['out'])); Helpers::redirect('/email'); }
        $score = 0; $det = [];
        foreach (explode("\n", trim($r['out'] ?? '')) as $line) {
            if (preg_match('/^score=(\d{1,3})$/', trim($line), $m)) $score = min(100, (int)$m[1]);
            elseif (preg_match('/^(spf|dkim|dmarc|mx|tls|mtasts|tlsrpt)=\d+ .{1,160}$/', trim($line))) $det[] = trim($line);
        }
        Database::query("INSERT INTO mail_scores(domain_id,score,details) VALUES(?,?,?) ON DUPLICATE KEY UPDATE score=VALUES(score), details=VALUES(details), checked_at=NOW()",
            [$d['id'], $score, implode("\n", $det)]);
        Helpers::flash('ok', "Deliverability for {$d['domain']}: $score/100");
        Helpers::redirect('/email');
    }
    // Mail filters (sieve via LMTP) + mailing lists (managed distribution).
    private function ownBox(array $u, int $id): ?array {
        $sql = ($u['role'] === 'admin')
            ? "SELECT e.*, d.domain FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE e.id=?"
            : "SELECT e.*, d.domain FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE e.id=? AND e.user_id=?";
        $args = ($u['role'] === 'admin') ? [$id] : [$id, $u['id']];
        return Database::query($sql, $args)->fetch() ?: null;
    }
    private function ownList(array $u, int $id): ?array {
        $sql = ($u['role'] === 'admin')
            ? "SELECT l.*, d.domain FROM email_lists l JOIN domains d ON d.id=l.domain_id WHERE l.id=?"
            : "SELECT l.*, d.domain FROM email_lists l JOIN domains d ON d.id=l.domain_id WHERE l.id=? AND l.user_id=?";
        $args = ($u['role'] === 'admin') ? [$id] : [$id, $u['id']];
        return Database::query($sql, $args)->fetch() ?: null;
    }
    public function filterCreate(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $u = Auth::user();
        $box = $this->ownBox($u, (int)($_POST['mailbox_id'] ?? 0));
        if (!$box) { Helpers::flash('err','Mailbox not found'); Helpers::redirect('/email'); }
        $field = strtolower(trim((string)($_POST['field'] ?? '')));
        $match = trim((string)($_POST['match'] ?? ''));
        $action = strtolower(trim((string)($_POST['action'] ?? '')));
        $target = trim((string)($_POST['target'] ?? ''));
        if (!in_array($field, ['subject','from','to'], true)) { Helpers::flash('err','Bad field'); Helpers::redirect('/email'); }
        if ($match === '' || mb_strlen($match) > 160) { Helpers::flash('err','Match text 1..160 chars'); Helpers::redirect('/email'); }
        if (!in_array($action, ['folder','discard','redirect'], true)) { Helpers::flash('err','Bad action'); Helpers::redirect('/email'); }
        if ($action === 'folder' && !preg_match('/^[A-Za-z0-9_-]{1,64}(\.[A-Za-z0-9_-]{1,64})*$/', $target)) { Helpers::flash('err','Folder: letters/digits/._-, dot-separated nesting ok'); Helpers::redirect('/email'); }
        if ($action === 'redirect' && (!filter_var($target, FILTER_VALIDATE_EMAIL) || str_contains($target, '|') || str_contains($target, '/'))) { Helpers::flash('err','Bad redirect target'); Helpers::redirect('/email'); }
        if ($action === 'discard') $target = '';
        Database::query("INSERT INTO email_filters(user_id,domain_id,localpart,field,match_text,action,target) VALUES(?,?,?,?,?,?,?)",
            [$u['id'], $box['domain_id'], $box['localpart'], $field, $match, $action, $target]);
        $r = ServiceManager::run('email-filters-sync.sh', []);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Saved but sync failed: '.trim($r['out']));
        else Helpers::flash('ok', "Filter on {$box['localpart']}@{$box['domain']}: $field contains → $action");
        Helpers::redirect('/email');
    }
    public function filterDelete(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $u = Auth::user();
        $id = (int)($_POST['id'] ?? 0);
        $row = Database::query("SELECT f.*, d.domain FROM email_filters f JOIN domains d ON d.id=f.domain_id WHERE f.id=?", [$id])->fetch();
        if (!$row || ($u['role'] !== 'admin' && (int)$row['user_id'] !== (int)$u['id'])) {
            Helpers::flash('err','Filter not found'); Helpers::redirect('/email');
        }
        Database::query("DELETE FROM email_filters WHERE id=?", [$id]);
        ServiceManager::run('email-filters-sync.sh', []);
        Helpers::flash('ok','Filter deleted'); Helpers::redirect('/email');
    }
    public function listCreate(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $u = Auth::user();
        $local = strtolower(trim($_POST['llocal'] ?? ''));
        $domainId = (int)($_POST['domain_id'] ?? 0);
        $d = Database::query("SELECT * FROM domains WHERE id=? AND user_id=?", [$domainId, $u['id']])->fetch();
        if (!$d) { Helpers::flash('err','Domain not found'); Helpers::redirect('/email'); }
        if (!preg_match('/^[a-z0-9._-]{1,64}$/', $local)) { Helpers::flash('err','Bad list name'); Helpers::redirect('/email'); }
        if (MailPolicyController::reserved((int)$d['id'], $local)) { Helpers::flash('err', "$local@{$d['domain']} receives DMARC/TLS reports here — turn report intake off first to use it"); Helpers::redirect('/email'); }
        // a list address must be free everywhere (mailbox/forwarder/alias/list)
        $taken = Database::query("SELECT 1 FROM email_accounts WHERE domain_id=? AND localpart=? UNION SELECT 1 FROM email_forwards WHERE domain_id=? AND localpart=? UNION SELECT 1 FROM domain_aliases WHERE alias=? UNION SELECT 1 FROM email_lists WHERE domain_id=? AND localpart=? LIMIT 1",
            [$domainId, $local, $domainId, $local, $local.'@'.$d['domain'], $domainId, $local])->fetch();
        if ($taken) { Helpers::flash('err','That address is already used'); Helpers::redirect('/email'); }
        Database::query("INSERT INTO email_lists(user_id,domain_id,localpart) VALUES(?,?,?)", [$u['id'], $domainId, $local]);
        ServiceManager::run('list-sync.sh', []);
        Helpers::flash('ok', "List $local@{$d['domain']} created — add subscribers (only they can post).".MailDns::ensureInbound($d));
        Helpers::redirect('/email');
    }
    public function listDelete(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $u = Auth::user();
        $row = $this->ownList($u, (int)($_POST['id'] ?? 0));
        if (!$row) { Helpers::flash('err','List not found'); Helpers::redirect('/email'); }
        Database::query("DELETE FROM email_list_members WHERE list_id=?", [$row['id']]);
        Database::query("DELETE FROM email_lists WHERE id=?", [$row['id']]);
        ServiceManager::run('list-sync.sh', []);
        Helpers::flash('ok','List deleted'); Helpers::redirect('/email');
    }
    public function memberAdd(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $u = Auth::user();
        $row = $this->ownList($u, (int)($_POST['id'] ?? 0));
        if (!$row) { Helpers::flash('err','List not found'); Helpers::redirect('/email'); }
        $addr = strtolower(trim((string)($_POST['address'] ?? '')));
        $listAddr = $row['localpart'].'@'.$row['domain'];
        if (!filter_var($addr, FILTER_VALIDATE_EMAIL) || str_contains($addr, '|') || str_contains($addr, '/')) { Helpers::flash('err','Bad address'); Helpers::redirect('/email'); }
        if ($addr === $listAddr) { Helpers::flash('err','A list cannot subscribe to itself (loop)'); Helpers::redirect('/email'); }
        $isList = Database::query("SELECT 1 FROM email_lists l JOIN domains d ON d.id=l.domain_id WHERE CONCAT(l.localpart,'@',d.domain)=? LIMIT 1", [$addr])->fetch();
        if ($isList) { Helpers::flash('err','Lists cannot subscribe to other lists (loop)'); Helpers::redirect('/email'); }
        $n = (int)Database::query("SELECT COUNT(*) c FROM email_list_members WHERE list_id=?", [$row['id']])->fetch()['c'];
        if ($n >= 200) { Helpers::flash('err','List full (200 members)'); Helpers::redirect('/email'); }
        Database::query("INSERT IGNORE INTO email_list_members(list_id,address) VALUES(?,?)", [$row['id'], $addr]);
        ServiceManager::run('list-sync.sh', []);
        Helpers::flash('ok',"$addr subscribed to $listAddr"); Helpers::redirect('/email');
    }
    // List posting cap (list_posts_default, 10/hr): one post fans out per
    // member, so it draws from the list's own bucket, not the sender's.
    public function listDefault(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $raw = trim((string)($_POST['default'] ?? ''));
        if (!preg_match('/^[0-9]{1,6}$/', $raw) || (int)$raw < 1 || (int)$raw > 100000) { Helpers::flash('err','Default 1..100000/hr'); Helpers::redirect('/email'); }
        try {
            Database::query("INSERT INTO settings(name,value) VALUES('list_posts_default',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [$raw]);
            Helpers::flash('ok',"List posting default: $raw/hr per list");
        } catch (Throwable $e) {
            try {
                Database::query("CREATE TABLE IF NOT EXISTS settings (name VARCHAR(190) PRIMARY KEY, value TEXT NOT NULL)");
                Database::query("INSERT INTO settings(name,value) VALUES('list_posts_default',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [$raw]);
                Helpers::flash('ok',"List posting default: $raw/hr per list");
            } catch (Throwable $e2) { Helpers::flash('err','Save failed: '.$e2->getMessage()); }
        }
        Helpers::redirect('/email');
    }
    public function memberDelete(): void {        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $u = Auth::user();
        $mid = (int)($_POST['mid'] ?? 0);
        $m = Database::query("SELECT m.*, l.user_id FROM email_list_members m JOIN email_lists l ON l.id=m.list_id WHERE m.id=?", [$mid])->fetch();
        if (!$m || ($u['role'] !== 'admin' && (int)$m['user_id'] !== (int)$u['id'])) {
            Helpers::flash('err','Member not found'); Helpers::redirect('/email');
        }
        Database::query("DELETE FROM email_list_members WHERE id=?", [$mid]);
        ServiceManager::run('list-sync.sh', []);
        Helpers::flash('ok','Member removed'); Helpers::redirect('/email');
    }
    public function resetPass(): void {        Auth::requireLogin();        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $u = Auth::user();
        $id = (int)($_POST['id'] ?? 0);
        $sql = ($u['role'] === 'admin')
            ? "SELECT e.*, d.domain FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE e.id=?"
            : "SELECT e.*, d.domain FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE e.id=? AND e.user_id=?";
        $args = ($u['role'] === 'admin') ? [$id] : [$id, $u['id']];
        $row = Database::query($sql, $args)->fetch();
        if (!$row) { Helpers::flash('err','Mailbox not found'); Helpers::redirect('/email'); }
        $pass = bin2hex(random_bytes(10));
        Database::query("UPDATE email_accounts SET password_hash=? WHERE id=?", [password_hash($pass, PASSWORD_BCRYPT), $row['id']]);
        // a fresh password is the cure for a mail-guard hold (the thief's
        // password is dead), so it lifts the hold; the release time fences
        // the detector so the old burst in the log can't re-hold it
        $wasHeld = ($row['send_hold_at'] ?? null) !== null;
        if ($wasHeld) {
            Database::query("UPDATE email_accounts SET send_hold_at=NULL, send_hold_reason='', send_released_at=NOW() WHERE id=?", [$row['id']]);
            Helpers::log("mail-guard: released {$row['localpart']}@{$row['domain']} by password reset ({$u['username']})");
        }
        ServiceManager::run('mail-kick.sh', ["{$row['localpart']}@{$row['domain']}"]);
        Helpers::flash('ok', "New password for {$row['localpart']}@{$row['domain']}: $pass (save it — shown only once)".($wasHeld ? ' · sending restored' : ''));
        Helpers::redirect('/email');
    }
}
