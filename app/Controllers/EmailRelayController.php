<?php
declare(strict_types=1);
class EmailRelayController extends Controller {
    public static function relayStatus(): array {
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
    public function limitDefault(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $raw = trim((string)($_POST['default'] ?? ''));
        if (!preg_match('/^[0-9]{1,6}$/', $raw) || (int)$raw < 1 || (int)$raw > 100000) { Helpers::flash('err','Default 1..100000/hr'); Helpers::redirect('/email'); }
        try {
            EmailModel::putSetting('smtp_default', $raw);
            Helpers::flash('ok',"Global sending default: $raw/hr");
        } catch (Throwable $e) {
            try {
                EmailModel::createSettingsTable();
                EmailModel::putSetting('smtp_default', $raw);
                Helpers::flash('ok',"Global sending default: $raw/hr");
            } catch (Throwable $e2) { Helpers::flash('err','Save failed: '.$e2->getMessage()); }
        }
        Helpers::redirect('/email');
    }
    // PHP mail server defaults (admin): cap for accounts without their own
    // (blank = follow the mailbox sending default, 0 = no cap) and whether
    // NEW accounts start with PHP mail on. Existing accounts keep their switch.
    public function phpMailDefault(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email#server'); }
        $cap = trim((string)($_POST['cap'] ?? ''));
        if ($cap !== '' && (!preg_match('/^[0-9]{1,6}$/', $cap) || (int)$cap > 100000)) { Helpers::flash('err','PHP mail cap: blank, 0 (no cap) or 1..100000 per hour'); Helpers::redirect('/email#server'); }
        $def = ($_POST['default'] ?? '1') === '0' ? '0' : '1';
        if ($cap === '') EmailModel::dropSetting('php_mail_cap');
        else EmailModel::putSetting('php_mail_cap', $cap);
        EmailModel::putSetting('php_mail_default', $def);
        $r = ServiceManager::run('mail-owners-sync.sh', [], 60);
        if ((int)$r['code'] !== 0) Helpers::flash('err', 'Saved, but the mail server map was not refreshed yet (retries within 5 min)');
        else Helpers::flash('ok', 'PHP mail defaults saved');
        Helpers::redirect('/email#server');
    }
    // Weekend spam-scan skip (admin): Sat/Sun bypass for spamd. Malware
    // rejects, rate caps and relay policy are unaffected. Takes effect
    // immediately — Exim reads the setting per message, no reload needed.
    // Mail guard release (admin): a false positive. The password stays as it
    // is — for a real compromise the owner's Reset PW is the cure instead.
    public function guardRelease(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $row = EmailModel::heldBox((int)($_POST['id'] ?? 0));
        if (!$row || $row['send_hold_at'] === null) { Helpers::flash('err','Mailbox not held'); Helpers::redirect('/email'); }
        EmailModel::releaseHold((int)$row['id']);
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
            EmailModel::putSetting('mail_guard', $on);
            Helpers::flash('ok', $on === '1' ? 'Mail guard ON: hijacked-looking mailboxes are held automatically' : 'Mail guard OFF: no new automatic holds (existing holds stay until released)');
        } catch (Throwable $e) { Helpers::flash('err','Save failed: '.$e->getMessage()); }
        Helpers::redirect('/email');
    }
    public function weekendSkip(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $on = (isset($_POST['on']) && $_POST['on'] === '1') ? '1' : '0';
        try {
            EmailModel::putSetting('spam_weekend_skip', $on);
            Helpers::flash('ok', $on === '1' ? 'Weekend scan-skip ON: Sat/Sun mail skips spamd (marked X-Spam-Skipped)' : 'Weekend scan-skip OFF: spamd scans every day');
        } catch (Throwable $e) {
            try {
                EmailModel::createSettingsTable();
                EmailModel::putSetting('spam_weekend_skip', $on);
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
        $d = EmailModel::domain($domainId, (int)$u['id']);
        if (!$d) { Helpers::flash('err','Domain not found'); Helpers::redirect('/email'); }
        $r = ServiceManager::run('mail-score.sh', [$d['domain']]);
        if (($r['code'] ?? 1) !== 0) { Helpers::flash('err','Check failed: '.trim($r['out'])); Helpers::redirect('/email'); }
        $score = 0; $det = [];
        foreach (explode("\n", trim($r['out'] ?? '')) as $line) {
            if (preg_match('/^score=(\d{1,3})$/', trim($line), $m)) $score = min(100, (int)$m[1]);
            elseif (preg_match('/^(spf|dkim|dmarc|mx|tls|mtasts|tlsrpt)=\d+ .{1,160}$/', trim($line))) $det[] = trim($line);
        }
        EmailModel::saveScore((int)$d['id'], $score, implode("\n", $det));
        Helpers::flash('ok', "Deliverability for {$d['domain']}: $score/100");
        Helpers::redirect('/email');
    }
    // Mail filters (sieve via LMTP) + mailing lists (managed distribution).
    // ---- mailbox import from another IMAP server (MailImport, mail-import.sh)

    public function setLimit(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $u = Auth::user();
        $row = EmailModel::box($u, (int)($_POST['id'] ?? 0));
        if (!$row) { Helpers::flash('err','Mailbox not found'); Helpers::redirect('/email'); }
        $raw = trim((string)($_POST['limit'] ?? ''));
        if ($raw === '') {
            EmailModel::setSmtpLimit((int)$row['id'], null);
            Helpers::flash('ok',"Limit cleared for {$row['localpart']} (global default applies)");
        } else {
            if (!preg_match('/^[0-9]{1,6}$/', $raw) || (int)$raw < 1 || (int)$raw > 100000) { Helpers::flash('err','Limit 1..100000/hr (or blank for default)'); Helpers::redirect('/email'); }
            EmailModel::setSmtpLimit((int)$row['id'], (int)$raw);
            Helpers::flash('ok',"Limit for {$row['localpart']}: $raw/hr");
        }
        Helpers::redirect('/email');
    }
}
