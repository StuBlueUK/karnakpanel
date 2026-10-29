<?php
declare(strict_types=1);
class EmailController extends Controller {
    public function list(): void {
        Auth::requireLogin();
        $u = Auth::user();
        $q = substr(trim((string)($_GET['q'] ?? '')), 0, 64);
        $like = ($q !== '') ? '%'.str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], strtolower($q)).'%' : null;
        // optional domain filter (owned domains only — a foreign id just matches nothing)
        $dom = max(0, (int)($_GET['domain'] ?? 0));
        $where = "e.user_id=?"; $args = [$u['id']];
        if ($dom > 0) { $where .= " AND e.domain_id=?"; $args[] = $dom; }
        if ($like !== null) { $where .= " AND (e.localpart LIKE ? OR d.domain LIKE ?)"; $args[] = $like; $args[] = $like; }
        $p = Helpers::paginate((int)Database::query("SELECT COUNT(*) c FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE $where", $args)->fetch()['c'], max(1, (int)($_GET['page'] ?? 1)));
        $pg = $p['pg']; $pages = $p['pages']; $total = $p['total'];
        $emails = Database::query("SELECT e.*, d.domain FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE $where ORDER BY d.domain, e.localpart LIMIT ? OFFSET ?", array_merge($args, [(int)$p['limit'], (int)$p['offset']]))->fetchAll();
        // every mailbox (not just this page) for the filter/autoreply pickers
        $boxes = Database::query("SELECT e.id, e.localpart, d.domain FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE e.user_id=? ORDER BY d.domain, e.localpart", [$u['id']])->fetchAll();
        $quotaMax = $this->quotaMax($u);
        // live usage per mailbox (Dovecot accounting; failures show as —, never fatal)
        $usage = MailUsage::many(array_map(fn($e) => $e['localpart'].'@'.$e['domain'], $emails));
        foreach ($emails as &$e) {
            $a = $e['localpart'].'@'.$e['domain'];
            $e['used_mb'] = isset($usage[$a]) ? number_format($usage[$a], 1, '.', '') : null;
        }
        unset($e);
        $forwards = [];
        try { $forwards = Database::query("SELECT f.*, d.domain FROM email_forwards f JOIN domains d ON d.id=f.domain_id WHERE f.user_id=?", [$u['id']])->fetchAll(); } catch (Throwable $e) {}
        foreach ($forwards as &$f) {
            $self = $f['localpart'].'@'.$f['domain'];
            $ts = array_values(array_filter(array_map('trim', explode(',', (string)$f['targets'])), 'strlen'));
            $f['keep'] = in_array($self, $ts, true);
            $f['shown'] = implode(', ', array_diff($ts, [$self]));
        }
        unset($f);
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
        try { $responders = Database::query("SELECT e.id, e.localpart, e.autoresp_subject, e.autoresp_text, e.autoresp_start, e.autoresp_end, d.domain FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE e.user_id=? AND e.autoresp_enabled=1 ORDER BY d.domain, e.localpart", [$u['id']])->fetchAll(); } catch (Throwable $e) {}
        // mail authentication per domain: SPF/DMARC/policy rows from the zone
        // that holds them (a subdomain's live in its parent zone at prefixed
        // names — MailDns), DKIM via key files
        $auth = [];
        foreach ($domains as $d) {
            $t = MailDns::target($d);
            $row = ['id' => $d['id'], 'domain' => $d['domain'], 'spf' => false, 'spfval' => 'v=spf1 a mx ~all', 'dkim' => false, 'txt' => '', 'dmarc' => false,
                'mta_sts' => (string)($d['mta_sts_mode'] ?? 'off'), 'reports' => (int)($d['mail_reports'] ?? 0) === 1, 'remote' => ($d['mail_handling'] ?? 'local') === 'remote', 'dmarcval' => '',
                'zone' => $t['label'] !== '' ? $t['origin'] : '', 'dmarcinh' => '', 'origin' => $t['origin'], 'extns' => MailDns::externalNs($t),
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
        // admin Server tab: queue + every held mailbox (any owner — the
        // Mailboxes table only lists the admin's own)
        $queue = null; $held = []; $relayResult = null;
        if ($u['role'] === 'admin') {
            $queue = ['queued' => '?', 'frozen' => '?'];
            try {
                $qr = ServiceManager::run('mail-queue.sh', ['summary']);
                foreach (explode("\n", (string)($qr['out'] ?? '')) as $line) {
                    if (preg_match('/^(queued|frozen): (\d+)$/', trim($line), $m)) $queue[$m[1]] = $m[2];
                }
            } catch (Throwable $e) {}
            try { $held = Database::query("SELECT e.id, e.localpart, e.send_hold_at, e.send_hold_reason, d.domain, u.username FROM email_accounts e JOIN domains d ON d.id=e.domain_id JOIN users u ON u.id=e.user_id WHERE e.send_hold_at IS NOT NULL ORDER BY e.send_hold_at DESC LIMIT 50")->fetchAll(); } catch (Throwable $e) {}
            // last relay test, shown in the relay card (one-shot)
            if (isset($_SESSION['relay_test']) && is_array($_SESSION['relay_test'])) { $relayResult = $_SESSION['relay_test']; unset($_SESSION['relay_test']); }
        }
        $this->view('email', compact('emails','boxes','quotaMax','dom','forwards','domains','auth','responders','pg','pages','total','scores','smtpDefault','filters','lists','listDefault','weekendSkip','mailGuard','q','queue','held','relayResult') + ['relay' => ($u['role'] === 'admin' ? $this->relayStatus() : null)]);
    }
    // Mailbox quota ceiling: the account's disk allowance (mail counts against
    // it), capped at 100 GB; quotas are whole MB, minimum 10.
    private function quotaMax(array $u): int {
        $disk = (int)(Database::query("SELECT disk_quota_mb FROM users WHERE id=?", [$u['id']])->fetch()['disk_quota_mb'] ?? 5120);
        return max(10, min(102400, $disk > 0 ? $disk : 102400));
    }
    private function quotaArg(array $u, $raw, int $fallback): ?int {
        $raw = trim((string)$raw);
        if ($raw === '') return min($fallback, $this->quotaMax($u));
        if (!preg_match('/^[0-9]{1,6}$/', $raw)) return null;
        $mb = (int)$raw;
        return ($mb >= 10 && $mb <= $this->quotaMax($u)) ? $mb : null;
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
        $quota = $this->quotaArg($u, $_POST['quota_mb'] ?? '', 1024);
        if ($quota === null) { Helpers::flash('err','Quota: 10..'.$this->quotaMax($u).' MB'); Helpers::redirect('/email'); }
        $hash = password_hash($pass, PASSWORD_BCRYPT);
        // Dovecot SHA512-CRYPT via doveadm would be ideal; bcrypt stored, synced by script
        Database::query("INSERT INTO email_accounts(user_id,domain_id,localpart,password_hash,quota_mb) VALUES(?,?,?,?,?)", [$u['id'],$domainId,$local,$hash,$quota]);
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
            Database::query("DELETE FROM mail_imports WHERE domain_id=? AND localpart=?", [$row['domain_id'], $row['localpart']]);
            // lists living at this exact address die with the mailbox
            $ll = Database::query("SELECT id FROM email_lists WHERE domain_id=? AND localpart=?", [$row['domain_id'], $row['localpart']])->fetchAll();
            foreach ($ll as $l) {
                Database::query("DELETE FROM email_list_members WHERE list_id=?", [$l['id']]);
                Database::query("DELETE FROM email_lists WHERE id=?", [$l['id']]);
            }
            // a forwarder keeping a copy here forwards only from now on
            $addr = $row['localpart'].'@'.$row['domain'];
            $fw = Database::query("SELECT id, targets FROM email_forwards WHERE domain_id=? AND localpart=?", [$row['domain_id'], $row['localpart']])->fetch();
            if ($fw) {
                $rest = array_values(array_filter(array_map('trim', explode(',', (string)$fw['targets'])), fn($t) => $t !== '' && $t !== $addr));
                if ($rest && count($rest) < count(explode(',', (string)$fw['targets']))) {
                    Database::query("UPDATE email_forwards SET targets=? WHERE id=?", [implode(', ', $rest), $fw['id']]);
                    ServiceManager::run('forward-add.sh', [$addr, implode(', ', $rest)]);
                }
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
        // optional window (server-local dates, inclusive): autoreply-sync.sh
        // runs hourly from cron and only publishes replies inside it
        $dates = [];
        foreach (['start', 'end'] as $k) {
            $v = trim((string)($_POST[$k] ?? ''));
            if ($v === '') { $dates[$k] = null; continue; }
            $dt = DateTime::createFromFormat('!Y-m-d', $v);
            if (!$dt || $dt->format('Y-m-d') !== $v) { Helpers::flash('err', ucfirst($k).' date: YYYY-MM-DD'); Helpers::redirect('/email'); }
            $dates[$k] = $v;
        }
        if ($dates['start'] !== null && $dates['end'] !== null && $dates['end'] < $dates['start']) { Helpers::flash('err','End date is before the start date'); Helpers::redirect('/email'); }
        if ($dates['end'] !== null && $dates['end'] < date('Y-m-d')) { Helpers::flash('err','End date is already past'); Helpers::redirect('/email'); }
        Database::query("UPDATE email_accounts SET autoresp_enabled=1, autoresp_subject=?, autoresp_text=?, autoresp_start=?, autoresp_end=? WHERE id=?", [$subject, $text, $dates['start'], $dates['end'], $row['id']]);
        $r = ServiceManager::run('autoreply-sync.sh', []);
        $when = ($dates['start'] !== null && $dates['start'] > date('Y-m-d')) ? " from {$dates['start']}" : ' now';
        if ($dates['end'] !== null) $when .= " until {$dates['end']}";
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Saved but sync failed: '.trim($r['out']));
        else Helpers::flash('ok', "Auto-reply for {$row['localpart']}@{$row['domain']} on$when");
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
        // "keep a copy": the source listed in its own targets — Exim skips
        // this redirect for that child, so it lands in the mailbox (and any
        // autoreply/filters still run). Needs a real mailbox at the address.
        $self = $local.'@'.$d['domain'];
        $keep = !empty($_POST['keep']);
        if ($keep && $local === '*') { Helpers::flash('err','A catch-all cannot keep a copy (there is no mailbox called *)'); Helpers::redirect('/email'); }
        // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string -- prepared query; the interpolated string is flash text
        if ($keep && !Database::query("SELECT 1 FROM email_accounts WHERE domain_id=? AND localpart=?", [$domainId, $local])->fetch()) { Helpers::flash('err',"Keep a copy needs a mailbox $self — create it first"); Helpers::redirect('/email'); }
        $clean = [];
        foreach (preg_split('/[\s,;]+/', $targets, -1, PREG_SPLIT_NO_EMPTY) as $t) {
            $t = strtolower(trim($t));
            if (!filter_var($t, FILTER_VALIDATE_EMAIL)) { Helpers::flash('err',"Bad target: $t"); Helpers::redirect('/email'); }
            if (str_contains($t, '|') || str_contains($t, '/')) { Helpers::flash('err','Pipes/paths refused'); Helpers::redirect('/email'); }
            if ($t === $self) continue; // only via "keep a copy"
            $clean[] = $t;
        }
        $clean = array_values(array_unique($clean));
        if (!$clean) { Helpers::flash('err','Add at least one address other than the forwarder itself (tick "keep a copy" to also deliver here)'); Helpers::redirect('/email'); }
        if (count($clean) > 50) { Helpers::flash('err','At most 50 targets — use a mailing list for more'); Helpers::redirect('/email'); }
        if ($keep) $clean[] = $self;
        $joined = implode(', ', $clean);
        // an edit that the script refuses must leave the old forwarder intact
        $prev = Database::query("SELECT targets FROM email_forwards WHERE domain_id=? AND localpart=?", [$domainId, $local])->fetch();
        Database::query("INSERT INTO email_forwards(user_id,domain_id,localpart,targets) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE targets=VALUES(targets)",
            [$u['id'], $domainId, $local, $joined]);
        $r = ServiceManager::run('forward-add.sh', [$self, $joined]);
        if (($r['code'] ?? 1) !== 0) {
            if ($prev) Database::query("UPDATE email_forwards SET targets=? WHERE domain_id=? AND localpart=?", [$prev['targets'], $domainId, $local]);
            else Database::query("DELETE FROM email_forwards WHERE domain_id=? AND localpart=?", [$domainId, $local]);
            Helpers::flash('err','Forwarder refused: '.trim($r['out']));
        }
        else Helpers::flash('ok', "Forwarder $self → ".implode(', ', array_diff($clean, [$self])).($keep ? ' (copy kept here)' : '').'.'.($prev ? '' : MailDns::ensureInbound($d)));
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
        // key + DKIM TXT + any missing SPF/DMARC in one go (MailDns::signDomain).
        // The TXT itself is shown in Mail authentication below — keep the
        // flash short (the full key used to overflow the banner). A zone
        // hosted here gets the records automatically (a subdomain's: in the parent).
        [$pub, $added] = MailDns::signDomain($d);
        if (str_starts_with($pub, 'failed') && MailDns::dkimValue($d['domain']) === '') Helpers::flash('err','DKIM failed: '.substr($pub, 8));
        else {
            $t = MailDns::target($d);
            $at = MailDns::host($t, 'default._domainkey').($t['label'] !== '' ? " in the {$t['origin']} zone" : '');
            $also = $added ? ' Also added '.implode(' + ', $added).'.' : '';
            if (($ext = MailDns::refreshDelegation($t)) !== '') $also .= " Note: the nameservers for {$t['origin']} are with your domain registrar ($ext), so these records are only on this server's copy — add them at your registrar (copy buttons in Mail authentication below).";
            Helpers::flash('ok', "Signing on for {$d['domain']}. ".match (true) {
                $pub === 'published' => "DKIM TXT published at $at.",
                $pub === 'exists' => "Its DKIM TXT is already published at $at.",
                $pub === 'differs' => "A different DKIM key is already published at $at — replace it with the TXT shown in Mail authentication below, or receivers fail the signature.",
                $pub === 'cname' => "$at is a CNAME — point it at this key or remove it, then add the TXT shown below.",
                str_starts_with($pub, 'failed') => "Publishing the TXT at $at failed ($pub) — add it in DNS by hand.",
                default => "Publish the TXT shown in Mail authentication below at $at wherever its DNS is managed.",
            }.$also);
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
        // shown inside the relay card (one-shot), not as a page banner
        $_SESSION['relay_test'] = ['ok' => ($r['code'] ?? 1) === 0, 'to' => $to, 'at' => date('H:i:s'), 'out' => substr(trim((string)($r['out'] ?? '')), 0, 2000)];
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
    // ---- mailbox import from another IMAP server (MailImport, mail-import.sh)
    public function importPage(): void {
        Auth::requireLogin();
        $box = $this->ownBox(Auth::user(), (int)($_GET['id'] ?? 0));
        if (!$box) { Helpers::flash('err','Mailbox not found'); Helpers::redirect('/email'); }
        try { Database::query("DELETE FROM mail_imports WHERE created_at < (NOW() - INTERVAL 30 DAY)"); } catch (Throwable $e) {}
        $sup = ServiceManager::run('mail-import.sh', ['supported']);
        $jobs = Database::query("SELECT * FROM mail_imports WHERE domain_id=? AND localpart=? ORDER BY created_at DESC LIMIT 10", [$box['domain_id'], $box['localpart']])->fetchAll();
        $this->view('email_import', ['box' => $box, 'jobs' => $jobs, 'supported' => ($sup['code'] ?? 1) === 0, 'why' => trim((string)($sup['out'] ?? ''))]);
    }
    public function importStart(): void {
        Auth::requireLogin();
        $id = (int)($_POST['id'] ?? 0); $back = '/email/import?id='.$id;
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect($back); }
        $u = Auth::user();
        $box = $this->ownBox($u, $id);
        if (!$box) { Helpers::flash('err','Mailbox not found'); Helpers::redirect('/email'); }
        $host = strtolower(trim((string)($_POST['host'] ?? '')));
        $port = (int)($_POST['port'] ?? 993);
        $ruser = trim((string)($_POST['remote_user'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');
        if ($port !== 993 && $port !== 143) { Helpers::flash('err','Port must be 993 (TLS) or 143 (STARTTLS)'); Helpers::redirect($back); }
        if (!MailImport::validRemoteUser($ruser)) { Helpers::flash('err','Old-server username: letters, digits and . _ + = @ - only'); Helpers::redirect($back); }
        if ($pass === '' || strlen($pass) > 256 || preg_match('/[\r\n\0]/', $pass)) { Helpers::flash('err','Password: 1-256 characters on one line'); Helpers::redirect($back); }
        if (($e = MailImport::hostError($host)) !== '') { Helpers::flash('err', $e); Helpers::redirect($back); }
        // rows belong to the box owner (purged with that account; an admin
        // importing on their behalf spends the owner's hourly allowance)
        $owner = Database::query("SELECT * FROM users WHERE id=?", [$box['user_id']])->fetch() ?: $u;
        if ($err = Helpers::diskBlocked($owner)) { Helpers::flash('err', $err); Helpers::redirect($back); }
        $n = (int)Database::query("SELECT COUNT(*) c FROM mail_imports WHERE user_id=? AND created_at > (NOW() - INTERVAL 1 HOUR)", [$box['user_id']])->fetch()['c'];
        if ($n >= MailImport::PER_HOUR) { Helpers::flash('err','At most '.MailImport::PER_HOUR.' import attempts per hour — try again later'); Helpers::redirect($back); }
        $job = bin2hex(random_bytes(8));
        $addr = $box['localpart'].'@'.$box['domain'];
        Database::query("INSERT INTO mail_imports(job,user_id,domain_id,localpart,host,port,remote_user,updated_at) VALUES(?,?,?,?,?,?,?,NOW())", [$job, $box['user_id'], $box['domain_id'], $box['localpart'], $host, $port, $ruser]);
        $r = ServiceManager::run('mail-import.sh', ['start', $job, $addr, $host, (string)$port, $ruser, new Secret($pass)], 120);
        $out = trim((string)($r['out'] ?? ''));
        if (($r['code'] ?? 1) !== 0) {
            // the script records its own refusals; one before it could (sudo, timeout) must not sit at 'checking'
            Database::query("UPDATE mail_imports SET stage='failed', detail=?, updated_at=NOW() WHERE job=? AND stage='checking'", [mb_substr($out, 0, 250), $job]);
            Helpers::flash('err', 'Import not started: '.$out);
        } else Helpers::flash('ok', $out);
        Helpers::log("mail import into $addr from $host requested by {$u['username']}: ".(($r['code'] ?? 1) === 0 ? 'started' : 'refused'));
        Helpers::redirect($back);
    }
    public function importCancel(): void {
        Auth::requireLogin();
        $id = (int)($_POST['id'] ?? 0); $back = '/email/import?id='.$id;
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect($back); }
        $box = $this->ownBox(Auth::user(), $id);
        $job = (string)($_POST['job'] ?? '');
        if (!$box || !preg_match('/^[a-f0-9]{16}$/', $job)) { Helpers::flash('err','Import not found'); Helpers::redirect($back); }
        $row = Database::query("SELECT job FROM mail_imports WHERE job=? AND domain_id=? AND localpart=?", [$job, $box['domain_id'], $box['localpart']])->fetch();
        if (!$row) { Helpers::flash('err','Import not found'); Helpers::redirect($back); }
        ServiceManager::run('mail-import.sh', ['cancel', $job], 20);
        // no process left (reboot, crash): close the row; a live job writes every 2 s, never matches
        Database::query("UPDATE mail_imports SET stage='cancelled', updated_at=NOW() WHERE job=? AND stage IN ('checking','running') AND updated_at < (NOW() - INTERVAL 30 SECOND)", [$job]);
        Helpers::flash('ok','Import stopped. Mail copied so far is kept.');
        Helpers::redirect($back);
    }
    public function importStatus(): void {
        Auth::requireLogin();
        header('Content-Type: application/json');
        $box = $this->ownBox(Auth::user(), (int)($_GET['id'] ?? 0));
        $job = (string)($_GET['job'] ?? '');
        $r = ($box && preg_match('/^[a-f0-9]{16}$/', $job))
            ? Database::query("SELECT * FROM mail_imports WHERE job=? AND domain_id=? AND localpart=?", [$job, $box['domain_id'], $box['localpart']])->fetch()
            : null;
        if (!$r) { http_response_code(404); echo json_encode(['error' => 'not found']); exit; }
        // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag, php.lang.security.injection.echoed-request.echoed-request -- JSON_HEX_* flags keep all HTML-significant bytes inert (detail carries remote-server text by design; no HTML context)
        echo json_encode(['stage' => MailImport::stage($r), 'pct' => MailImport::pct($r), 'copied' => (int)$r['copied'], 'total' => (int)$r['total'], 'detail' => $r['detail']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        exit;
    }
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
    // Filters: field subject/from/to/cc matched contains|is|starts, or
    // spam = spamd score of at least N (the X-Spam-Score bar, so negative
    // scores never match). Posting an id edits that filter in place.
    public function filterCreate(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $u = Auth::user();
        $box = $this->ownBox($u, (int)($_POST['mailbox_id'] ?? 0));
        if (!$box) { Helpers::flash('err','Mailbox not found'); Helpers::redirect('/email'); }
        $editId = (int)($_POST['id'] ?? 0);
        $old = null;
        if ($editId > 0) {
            $old = Database::query("SELECT * FROM email_filters WHERE id=?", [$editId])->fetch();
            if (!$old || ($u['role'] !== 'admin' && (int)$old['user_id'] !== (int)$u['id'])) { Helpers::flash('err','Filter not found'); Helpers::redirect('/email'); }
        }
        $field = strtolower(trim((string)($_POST['field'] ?? '')));
        $mtype = strtolower(trim((string)($_POST['match_type'] ?? 'contains')));
        $match = trim((string)($_POST['match'] ?? ''));
        $action = strtolower(trim((string)($_POST['action'] ?? '')));
        $target = trim((string)($_POST['target'] ?? ''));
        if (!in_array($field, ['subject','from','to','cc','spam'], true)) { Helpers::flash('err','Bad field'); Helpers::redirect('/email'); }
        if ($field === 'spam') {
            if (!preg_match('/^[0-9]{1,2}$/', $match) || (int)$match < 1 || (int)$match > 30) { Helpers::flash('err','Spam score: a whole number 1..30'); Helpers::redirect('/email'); }
            $match = (string)(int)$match; $mtype = 'ge';
        } else {
            if (!in_array($mtype, ['contains','is','starts'], true)) { Helpers::flash('err','Bad match type'); Helpers::redirect('/email'); }
            if ($match === '' || mb_strlen($match) > 160 || preg_match('/[\r\n]/', $match)) { Helpers::flash('err','Match text 1..160 chars, one line'); Helpers::redirect('/email'); }
        }
        if (!in_array($action, ['folder','discard','redirect'], true)) { Helpers::flash('err','Bad action'); Helpers::redirect('/email'); }
        if ($action === 'folder' && !preg_match('/^[A-Za-z0-9_-]{1,64}(\.[A-Za-z0-9_-]{1,64})*$/', $target)) { Helpers::flash('err','Folder: letters/digits/._-, dot-separated nesting ok'); Helpers::redirect('/email'); }
        if ($action === 'redirect' && (!filter_var($target, FILTER_VALIDATE_EMAIL) || str_contains($target, '|') || str_contains($target, '/'))) { Helpers::flash('err','Bad redirect target'); Helpers::redirect('/email'); }
        if ($action === 'discard') $target = '';
        if ($old) {
            Database::query("UPDATE email_filters SET domain_id=?, localpart=?, field=?, match_type=?, match_text=?, action=?, target=? WHERE id=?",
                [$box['domain_id'], $box['localpart'], $field, $mtype, $match, $action, $target, $old['id']]);
        } else {
            Database::query("INSERT INTO email_filters(user_id,domain_id,localpart,field,match_type,match_text,action,target) VALUES(?,?,?,?,?,?,?,?)",
                [$u['id'], $box['domain_id'], $box['localpart'], $field, $mtype, $match, $action, $target]);
        }
        $r = ServiceManager::run('email-filters-sync.sh', []);
        $desc = $field === 'spam' ? "spam score ≥ $match" : "$field $mtype \"$match\"";
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Saved but sync failed: '.trim($r['out']));
        else Helpers::flash('ok', ($old ? 'Filter updated' : 'Filter added')." on {$box['localpart']}@{$box['domain']}: $desc → $action");
        Helpers::redirect('/email');
    }
    public function filterToggle(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $u = Auth::user();
        $row = Database::query("SELECT * FROM email_filters WHERE id=?", [(int)($_POST['id'] ?? 0)])->fetch();
        if (!$row || ($u['role'] !== 'admin' && (int)$row['user_id'] !== (int)$u['id'])) { Helpers::flash('err','Filter not found'); Helpers::redirect('/email'); }
        $on = (int)$row['active'] === 1 ? 0 : 1;
        Database::query("UPDATE email_filters SET active=? WHERE id=?", [$on, $row['id']]);
        $r = ServiceManager::run('email-filters-sync.sh', []);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Saved but sync failed: '.trim($r['out']));
        else Helpers::flash('ok', $on ? 'Filter enabled' : 'Filter paused (kept, not applied)');
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
        // one or many: paste a column, a comma list, or "Name <a@b>" lines
        $raw = (string)($_POST['address'] ?? '');
        if (strlen($raw) > 20000) { Helpers::flash('err','Too much text (paste at most 200 addresses)'); Helpers::redirect('/email'); }
        $listAddr = $row['localpart'].'@'.$row['domain'];
        $cands = [];
        foreach (preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $tok) {
            $tok = strtolower(trim($tok, " \t<>\"'()"));
            if ($tok !== '' && str_contains($tok, '@')) $cands[$tok] = true;
        }
        if (!$cands) { Helpers::flash('err','No addresses found'); Helpers::redirect('/email'); }
        $have = [];
        foreach (Database::query("SELECT address FROM email_list_members WHERE list_id=?", [$row['id']])->fetchAll() as $m) $have[$m['address']] = true;
        $added = 0; $skip = ['bad' => 0, 'loop' => 0, 'dupe' => 0, 'full' => 0]; $firstBad = '';
        foreach (array_keys($cands) as $addr) {
            if (!filter_var($addr, FILTER_VALIDATE_EMAIL) || str_contains($addr, '|') || str_contains($addr, '/')) { $skip['bad']++; if ($firstBad === '') $firstBad = $addr; continue; }
            if (isset($have[$addr])) { $skip['dupe']++; continue; }
            if ($addr === $listAddr || Database::query("SELECT 1 FROM email_lists l JOIN domains d ON d.id=l.domain_id WHERE CONCAT(l.localpart,'@',d.domain)=? LIMIT 1", [$addr])->fetch()) { $skip['loop']++; continue; }
            if (count($have) >= 200) { $skip['full']++; continue; }
            Database::query("INSERT IGNORE INTO email_list_members(list_id,address) VALUES(?,?)", [$row['id'], $addr]);
            $have[$addr] = true; $added++;
        }
        if ($added > 0) ServiceManager::run('list-sync.sh', []);
        $why = array_filter(['already in' => $skip['dupe'], 'invalid' => $skip['bad'], 'lists (loop)' => $skip['loop'], 'over the 200 cap' => $skip['full']]);
        $msg = "$added subscribed to $listAddr";
        if ($why) $msg .= ' · skipped '.implode(', ', array_map(fn($k, $v) => "$v $k", array_keys($why), $why)).($firstBad !== '' ? " (e.g. $firstBad)" : '');
        Helpers::flash($added > 0 ? 'ok' : 'err', $msg); Helpers::redirect('/email');
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
    public function resetPass(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $u = Auth::user();
        $row = $this->ownBox($u, (int)($_POST['id'] ?? 0));
        if (!$row) { Helpers::flash('err','Mailbox not found'); Helpers::redirect('/email'); }
        $pass = bin2hex(random_bytes(10));
        $wasHeld = $this->applyPassword($u, $row, $pass);
        Helpers::flash('ok', "New password for {$row['localpart']}@{$row['domain']}: $pass (save it — shown only once)".($wasHeld ? ' · sending restored' : ''));
        Helpers::redirect('/email');
    }
    // Owner-chosen password: same effect as Reset PW (hold lifted, sessions
    // kicked) but never echoed back.
    public function setPassword(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $u = Auth::user();
        $row = $this->ownBox($u, (int)($_POST['id'] ?? 0));
        if (!$row) { Helpers::flash('err','Mailbox not found'); Helpers::redirect('/email'); }
        $pass = (string)($_POST['password'] ?? '');
        if (strlen($pass) < 8 || strlen($pass) > 128) { Helpers::flash('err','Password 8..128 characters'); Helpers::redirect('/email'); }
        if ($pass !== (string)($_POST['password2'] ?? '')) { Helpers::flash('err','The two passwords differ'); Helpers::redirect('/email'); }
        $wasHeld = $this->applyPassword($u, $row, $pass);
        Helpers::flash('ok', "Password changed for {$row['localpart']}@{$row['domain']} — open mail apps must sign in again".($wasHeld ? ' · sending restored' : ''));
        Helpers::redirect('/email');
    }
    // bcrypt into the DB (Dovecot reads it via SQL — instant). A fresh
    // password is the cure for a mail-guard hold (the thief's password is
    // dead), so it lifts the hold; the release time fences the detector so
    // the old burst in the log can't re-hold it. Returns whether it was held.
    private function applyPassword(array $u, array $row, string $pass): bool {
        Database::query("UPDATE email_accounts SET password_hash=? WHERE id=?", [password_hash($pass, PASSWORD_BCRYPT), $row['id']]);
        $wasHeld = ($row['send_hold_at'] ?? null) !== null;
        if ($wasHeld) {
            Database::query("UPDATE email_accounts SET send_hold_at=NULL, send_hold_reason='', send_released_at=NOW() WHERE id=?", [$row['id']]);
            Helpers::log("mail-guard: released {$row['localpart']}@{$row['domain']} by password change ({$u['username']})");
        }
        ServiceManager::run('mail-kick.sh', ["{$row['localpart']}@{$row['domain']}"]);
        return $wasHeld;
    }
    // Quota edit: Dovecot (quota_rule) and Exim (transport quota) both read
    // quota_mb per lookup, so the DB row is the whole change.
    public function setQuota(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $u = Auth::user();
        $row = $this->ownBox($u, (int)($_POST['id'] ?? 0));
        if (!$row) { Helpers::flash('err','Mailbox not found'); Helpers::redirect('/email'); }
        // ceiling from the box OWNER's allowance (an admin may edit anyone's)
        $owner = ['id' => (int)$row['user_id']];
        $quota = $this->quotaArg($owner, $_POST['quota_mb'] ?? 'x', 1024);
        if ($quota === null) { Helpers::flash('err','Quota: 10..'.$this->quotaMax($owner).' MB'); Helpers::redirect('/email'); }
        Database::query("UPDATE email_accounts SET quota_mb=? WHERE id=?", [$quota, $row['id']]);
        Helpers::flash('ok', "Quota for {$row['localpart']}@{$row['domain']}: $quota MB");
        Helpers::redirect('/email');
    }
}
