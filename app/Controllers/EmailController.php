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
        $p = Helpers::paginate(EmailModel::count((int)$u['id'], $dom, $like), max(1, (int)($_GET['page'] ?? 1)));
        $pg = $p['pg']; $pages = $p['pages']; $total = $p['total'];
        $emails = EmailModel::page((int)$u['id'], $dom, $like, (int)$p['limit'], (int)$p['offset']);
        // every mailbox (not just this page) for the filter/autoreply pickers
        $boxes = EmailModel::boxes((int)$u['id']);
        $quotaMax = $this->quotaMax($u);
        // live usage per mailbox (Dovecot accounting; failures show as —, never fatal)
        $usage = MailUsage::many(array_map(fn($e) => $e['localpart'].'@'.$e['domain'], $emails));
        foreach ($emails as &$e) {
            $a = $e['localpart'].'@'.$e['domain'];
            $e['used_mb'] = isset($usage[$a]) ? number_format($usage[$a], 1, '.', '') : null;
        }
        unset($e);
        $forwards = [];
        try { $forwards = EmailModel::forwards((int)$u['id']); } catch (Throwable $e) {}
        foreach ($forwards as &$f) {
            $self = $f['localpart'].'@'.$f['domain'];
            $ts = array_values(array_filter(array_map('trim', explode(',', (string)$f['targets'])), 'strlen'));
            $f['keep'] = in_array($self, $ts, true);
            $f['shown'] = implode(', ', array_diff($ts, [$self]));
        }
        unset($f);
        $domains = DomainModel::forUser((int)$u['id']);
        $scores = [];
        try { foreach (EmailModel::scores((int)$u['id']) as $s) $scores[$s['domain']] = $s; } catch (Throwable $e) {}
        $filters = [];
        try { $filters = EmailModel::filters((int)$u['id']); } catch (Throwable $e) {}
        $lists = [];
        try {
            $lists = EmailModel::lists((int)$u['id']);
            foreach ($lists as &$l) {
                $l['members'] = EmailModel::listMembers((int)$l['id']);
            }
            unset($l);
        } catch (Throwable $e) {}
        $smtpDefault = '200';
        try {
            $v = EmailModel::setting('smtp_default');
            if ($v !== null && preg_match('/^[0-9]{1,6}$/', $v)) $smtpDefault = $v;
        } catch (Throwable $e) {}
        // PHP mail: this account's own switch (notice when off) + server defaults (admin)
        $phpMail = ['on' => true, 'reason' => ''];
        try {
            $pm = EmailModel::phpMail((int)$u['id']);
            if ($pm) $phpMail = ['on' => (int)$pm['php_mail'] === 1, 'reason' => (string)($pm['php_mail_off_reason'] ?? '')];
        } catch (Throwable $e) {}
        $phpMailCap = ''; $phpMailDefault = '1';
        try {
            $mailSettings = EmailModel::settings(['php_mail_cap', 'php_mail_default']);
            if (isset($mailSettings['php_mail_cap']) && preg_match('/^[0-9]{1,6}$/', $mailSettings['php_mail_cap'])) $phpMailCap = $mailSettings['php_mail_cap'];
            if (isset($mailSettings['php_mail_default'])) $phpMailDefault = $mailSettings['php_mail_default'] === '0' ? '0' : '1';
        } catch (Throwable $e) {}
        $listDefault = '10';
        try {
            $v = EmailModel::setting('list_posts_default');
            if ($v !== null && preg_match('/^[0-9]{1,6}$/', $v)) $listDefault = $v;
        } catch (Throwable $e) {}
        // weekend scan-skip (Exim acl_check_data): Sat/Sun spamd bypass to save
        // CPU. Default off — missing setting means always scan.
        $weekendSkip = '0';
        try {
            if (EmailModel::setting('spam_weekend_skip') === '1') $weekendSkip = '1';
        } catch (Throwable $e) {}
        // mail guard (cli/mail-guard.php): compromised-mailbox auto-hold.
        // Default ON — missing setting means guarding.
        $mailGuard = '1';
        try {
            if (EmailModel::setting('mail_guard') === '0') $mailGuard = '0';
        } catch (Throwable $e) {}
        $responders = [];
        try { $responders = EmailModel::responders((int)$u['id']); } catch (Throwable $e) {}
        // mail authentication per domain: SPF/DMARC/policy rows from the zone
        // that holds them (a subdomain's live in its parent zone at prefixed
        // names — MailDns), DKIM via key files
        $auth = [];
        foreach ($domains as $d) {
            $t = MailDns::target($d);
            $row = ['id' => $d['id'], 'domain' => $d['domain'], 'spf' => false, 'spfval' => MailDns::spfDefault(), 'dkim' => false, 'txt' => '', 'dmarc' => false,
                'mta_sts' => (string)($d['mta_sts_mode'] ?? 'off'), 'reports' => (int)($d['mail_reports'] ?? 0) === 1, 'remote' => ($d['mail_handling'] ?? 'local') === 'remote', 'dmarcval' => '',
                'zone' => $t['label'] !== '' ? $t['origin'] : '', 'dmarcinh' => '', 'origin' => $t['origin'], 'extns' => MailDns::externalNs($t),
                'hosts' => ['spf' => MailDns::host($t, '@'), 'dkim' => MailDns::host($t, 'default._domainkey'), 'dmarc' => MailDns::host($t, '_dmarc')]];
            try {
                $zid = (int)($t['zone']['id'] ?? 0);
                $spf = EmailModel::zoneTxt($zid, $row['hosts']['spf'], '%v=spf1%');
                if ($spf !== null) { $row['spf'] = true; $row['spfval'] = $spf; }
                $dm = EmailModel::zoneTxt($zid, $row['hosts']['dmarc'], '%v=DMARC1%');
                $row['dmarc'] = $dm !== null;
                if ($dm !== null) $row['dmarcval'] = trim($dm, '"');
                // no record of its own: the parent's policy covers a subdomain
                // (sp=, else p=) — covered, not missing
                elseif ($t['label'] !== '' && ($ip = MailDns::inheritedP($t)) !== '') { $row['dmarc'] = true; $row['dmarcinh'] = "$ip from {$t['origin']}"; }
                // MTA-STS / TLS-RPT rows (copy-block for external nameservers)
                $row['policyrecs'] = [];
                $ph = array_map(fn($h) => MailDns::host($t, $h), ['mta-sts', '_mta-sts', '_smtp._tls']);
                foreach (EmailModel::policyRows($zid, $ph[0], $ph[1], $ph[2]) as $pr) {
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
            try { $held = EmailModel::held(); } catch (Throwable $e) {}
            // last relay test, shown in the relay card (one-shot)
            if (isset($_SESSION['relay_test']) && is_array($_SESSION['relay_test'])) { $relayResult = $_SESSION['relay_test']; unset($_SESSION['relay_test']); }
        }
        $this->view('email', compact('emails','boxes','quotaMax','dom','forwards','domains','auth','responders','pg','pages','total','scores','smtpDefault','phpMail','phpMailCap','phpMailDefault','filters','lists','listDefault','weekendSkip','mailGuard','q','queue','held','relayResult') + ['relay' => ($u['role'] === 'admin' ? EmailRelayController::relayStatus() : null)]);
    }
    // Mailbox quota ceiling: the account's disk allowance (mail counts against
    // it), capped at 100 GB; quotas are whole MB, minimum 10.
    private function quotaMax(array $u): int {
        $disk = EmailModel::diskQuotaMb((int)$u['id']);
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
        if (!Helpers::localpartOk($local, true) || strlen($pass)<8) { Helpers::flash('err','Bad email/password'); Helpers::redirect('/email'); }
        $d = EmailModel::domain($domainId, (int)$u['id']);
        if (!$d) { Helpers::flash('err','Domain not found'); Helpers::redirect('/email'); }
        if (MailPolicyController::reserved((int)$d['id'], $local)) { Helpers::flash('err', "$local@{$d['domain']} receives DMARC/TLS reports here — turn report intake off first to use it"); Helpers::redirect('/email'); }
        // remote-handled domains serve mail at their provider — a mailbox
        // here would never receive anything (remote_route runs first)
        // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string -- not SQL: flash text, no query sink
        if (($d['mail_handling'] ?? 'local') === 'remote') { Helpers::flash('err', "Mail for {$d['domain']} routes to your external provider — switch it back to Local in Domains to create mailboxes here"); Helpers::redirect('/email'); }
        if ($err = Quota::limitError($u, 'emails')) { Helpers::flash('err', $err); Helpers::redirect('/email'); }
        if ($err = Quota::blocked($u)) { Helpers::flash('err', $err); Helpers::redirect('/email'); }
        $quota = $this->quotaArg($u, $_POST['quota_mb'] ?? '', 1024);
        if ($quota === null) { Helpers::flash('err','Quota: 10..'.$this->quotaMax($u).' MB'); Helpers::redirect('/email'); }
        $hash = password_hash($pass, PASSWORD_BCRYPT);
        // Dovecot SHA512-CRYPT via doveadm would be ideal; bcrypt stored, synced by script
        EmailModel::createMailbox((int)$u['id'], $domainId, $local, $hash, $quota);
        ServiceManager::run('email-add.sh', [$local.'@'.$d['domain'], $u['username']]);
        Helpers::flash('ok',"Mailbox $local@{$d['domain']} created.".MailDns::ensureInbound($d));
        Helpers::redirect('/email');
    }
    public function delete(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $u = Auth::user();
        $row = EmailModel::box($u, (int)($_POST['id'] ?? 0));
        if (!$row) { Helpers::flash('err','Mailbox not found'); Helpers::redirect('/email'); }
        try {
            EmailModel::deleteMailbox((int)$row['id']);
            EmailModel::deleteFiltersAt((int)$row['domain_id'], $row['localpart']);
            EmailModel::deleteImportsAt((int)$row['domain_id'], $row['localpart']);
            // lists living at this exact address die with the mailbox
            foreach (EmailModel::listsAt((int)$row['domain_id'], $row['localpart']) as $l) {
                EmailModel::deleteListMembers((int)$l['id']);
                EmailModel::deleteList((int)$l['id']);
            }
            // a forwarder keeping a copy here forwards only from now on
            $addr = $row['localpart'].'@'.$row['domain'];
            $fw = EmailModel::forwardAt((int)$row['domain_id'], $row['localpart']);
            if ($fw) {
                $rest = array_values(array_filter(array_map('trim', explode(',', (string)$fw['targets'])), fn($t) => $t !== '' && $t !== $addr));
                if ($rest && count($rest) < count(explode(',', (string)$fw['targets']))) {
                    EmailModel::setForwardTargets((int)$fw['id'], implode(', ', $rest));
                    ServiceManager::run('forward-add.sh', [$addr, implode(', ', $rest)]);
                }
            }
            // drop the box from any list it subscribed to (stale members bounce)
            EmailModel::deleteMembersByAddress($row['localpart'].'@'.$row['domain']);
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
        return EmailModel::box($u, $id);
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
        EmailModel::setAutoresponder((int)$row['id'], $subject, $text, $dates['start'], $dates['end']);
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
        EmailModel::clearAutoresponder((int)$row['id']);
        ServiceManager::run('autoreply-sync.sh', []);
        Helpers::flash('ok', "Auto-reply OFF for {$row['localpart']}@{$row['domain']}");
        Helpers::redirect('/email');
    }
    // Passwords live in the panel DB (Dovecot reads them via SQL) — rotation is instant.
        public function dkimEnable(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $u = Auth::user();
        $domainId = (int)($_POST['domain_id'] ?? 0);
        $d = EmailModel::domain($domainId, (int)$u['id']);
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
        $row = EmailModel::boxOwned((int)$u['id'], $id);
        if (!$row) { Helpers::flash('err','Mailbox not found'); Helpers::redirect('/email'); }
        EmailModel::setSpamThreshold($id, $t);
        Helpers::flash('ok',"Spam flag threshold for {$row['localpart']} set to $t");
        Helpers::redirect('/email');
    }
    // auto-empty Trash/Spam (scripts/mail-clean.sh, nightly): 0 = off
    public const CLEAN_DAYS = [0, 7, 14, 30, 60, 90];
    public function setClean(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $u = Auth::user();
        $id = (int)($_POST['id'] ?? 0);
        $d = (int)($_POST['days'] ?? -1);
        if (!in_array($d, self::CLEAN_DAYS, true)) { Helpers::flash('err','Pick one of the listed periods'); Helpers::redirect('/email'); }
        $row = EmailModel::boxOwned((int)$u['id'], $id);
        if (!$row) { Helpers::flash('err','Mailbox not found'); Helpers::redirect('/email'); }
        EmailModel::setCleanDays($id, $d);
        Helpers::flash('ok', $d === 0 ? "Trash and Spam of {$row['localpart']} are kept until emptied by hand"
            : "Trash and Spam of {$row['localpart']} are emptied of messages older than $d days, nightly");
        Helpers::redirect('/email');
    }
    // SMTP relay (smarthost) for port-25-blocked boxes. Admin only. The
    // password travels via a 600 staging file the script shreds (never argv:
    // the panel logs full commands). Status shows host/user only — the
    // password is never read back anywhere.
    private function ownBox(array $u, int $id): ?array {
        return EmailModel::box($u, $id);
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
        EmailModel::setPasswordHash((int)$row['id'], password_hash($pass, PASSWORD_BCRYPT));
        $wasHeld = ($row['send_hold_at'] ?? null) !== null;
        if ($wasHeld) {
            EmailModel::releaseHold((int)$row['id']);
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
        EmailModel::setQuota((int)$row['id'], $quota);
        Helpers::flash('ok', "Quota for {$row['localpart']}@{$row['domain']}: $quota MB");
        Helpers::redirect('/email');
    }
}
