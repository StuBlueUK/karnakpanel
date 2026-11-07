#!/usr/bin/env php
<?php
// mail-guard.php — compromised-mailbox auto-hold. Run as root by
// scripts/mail-guard.sh (cron, flock-guarded). Reads the last hour of the
// Exim mainlog, scores each authenticated sender with MailGuard (see its
// header for the signals), and HOLDS a mailbox that looks hijacked:
//   - email_accounts.send_hold_at set -> Exim's RCPT ACL refuses its sends;
//   - its queued outbound mail is FROZEN (not deleted: an admin reviews it in
//     the queue viewer, a false positive thaws, spam gets dropped);
//   - its IMAP sessions are kicked;
//   - owner gets a panel notification, the admin a notification + mail
//     (alert_email setting, else admin accounts — never the mailbox itself).
// Released by a password reset (owner) or an admin release on the Email page;
// only activity after send_released_at counts again, so the same burst can't
// re-hold a mailbox the moment it is released.
// PHP mail (hosting accounts' mail()/sendmail, see MailGuard's PHP mail part):
//   - an account flooding past its per-account cap gets PHP mail SWITCHED OFF
//     (users.php_mail=0 -> phpmail map "off" -> Exim refuses), its queued
//     local mail frozen, owner + admin told; an admin switches it back on;
//     only activity after php_mail_off_at counts again (same burst can't
//     re-trip it). Admin-set "off" accounts are left alone.
//   - hourly, advisory only: a site form that bots fire at other people's
//     addresses (probably no CAPTCHA) -> owner + admin notice, once a week
//     per domain + path (form_alerts).
// Off switch: settings mail_guard = 0 (Email page, admin).
declare(strict_types=1);
$root = dirname(__DIR__);
spl_autoload_register(function ($c) use ($root) {
    $f = "$root/app/Core/$c.php";
    if (preg_match('/^[A-Za-z]+$/', $c) && file_exists($f)) require_once $f;
});
Config::load($root.'/config/config.php');

$verbose = in_array('-v', $argv, true);
$dry = in_array('--dry-run', $argv, true);
$logDir = getenv('MAILGUARD_LOG_DIR') ?: '/var/log/exim4';
function say(string $m): void { global $verbose; if ($verbose) echo $m, "\n"; }

$on = Database::query("SELECT value FROM settings WHERE name='mail_guard'")->fetch();
if ($on && (string)$on['value'] === '0') { say('mail guard off'); exit(0); }

// window from the DB clock: Exim logs system local time, MariaDB NOW() is
// system time too, and send_released_at is written with NOW() — one clock
$since = (string)Database::query("SELECT DATE_FORMAT(NOW() - INTERVAL 1 HOUR, '%Y-%m-%d %H:%i:%s') s")->fetch()['s'];

/** Exim mainlog lines, oldest first; mainlog.1 only when it can still overlap the window. */
function logLines(string $logDir, int $maxAge): Generator {
    foreach (["$logDir/mainlog.1", "$logDir/mainlog"] as $f) {
        if (!is_file($f) || is_link($f)) continue;
        if (str_ends_with($f, '.1') && filemtime($f) < time() - $maxAge) continue;
        $h = fopen($f, 'r');
        if (!$h) continue;
        while (($l = fgets($h, 8192)) !== false) yield rtrim($l, "\n");
        fclose($h);
    }
}
// mainlog.1 only while fresh (just after rotation the last hour spans
// both); compressed generations are always older than the window
$lines = logLines($logDir, 7200);
$p = MailGuard::parse($lines, $since);
say('senders in window: '.count($p['sends']).', cap-hit senders: '.count($p['caps']));

$addrs = array_unique(array_merge(array_keys($p['sends']), array_keys($p['caps'])));
$held = 0;
foreach ($addrs as $addr) {
    [$lp, $dom] = explode('@', $addr, 2);
    $box = Database::query("SELECT e.id, e.user_id, e.send_hold_at, e.send_released_at, d.domain FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE e.localpart=? AND d.domain=?", [$lp, $dom])->fetch();
    if (!$box || $box['send_hold_at'] !== null) continue;
    $fence = ($box['send_released_at'] !== null && strcmp((string)$box['send_released_at'], $since) > 0) ? (string)$box['send_released_at'] : $since;
    $s = MailGuard::stats($p, $addr, $fence);
    $why = MailGuard::verdict($s);
    say("$addr: ".json_encode($s).' -> '.($why ?? 'ok'));
    if ($why === null || $dry) continue;
    $n = Database::query("UPDATE email_accounts SET send_hold_at=NOW(), send_hold_reason=? WHERE id=? AND send_hold_at IS NULL", [substr($why, 0, 255), $box['id']])->rowCount();
    if ($n !== 1) continue;
    $held++;
    $frozen = freezeQueued($addr);
    run(['doveadm', 'kick', $addr]);
    Helpers::log("mail-guard: HELD $addr ($why); froze $frozen queued message(s)");
    $title = "Sending held for $addr: suspected compromise";
    $body = "KarnakPanel stopped outgoing mail from $addr — $why.\n"
        ."That pattern usually means its password was stolen and it is being used to send spam.\n\n"
        ."Its incoming mail is unaffected. $frozen queued outgoing message(s) were frozen for review.\n"
        ."To restore sending: Email page -> Reset PW on that mailbox (the old password stops working),\n"
        ."then update the new password in your mail apps. An admin can also release it if this was a false alarm.";
    Helpers::notify((int)$box['user_id'], 'mail-guard', $title, $body);
    Helpers::notify(null, 'mail-guard', $title, $body."\n\nAdmin: frozen messages are in Admin -> Mail queue (drop the spam, thaw a false positive).");
    foreach (adminRecipients() as $to) mailTo($to, $title, $body."\n\nAdmin: frozen messages are in Admin -> Mail queue (drop the spam, thaw a false positive).");
}
say("held $held mailbox(es)");

// ------------------------------------------------------------ PHP mail
$accts = [];
foreach (Database::query("SELECT id, username, php_mail, php_mail_off_at FROM users")->fetchAll() as $u) $accts[(string)$u['username']] = $u;
$lp = MailGuard::parseLocal(logLines($logDir, 7200), $since, $accts);
$offed = 0;
foreach (array_keys($lp['caps']) as $acct) {
    $u = $accts[$acct];
    if ((int)$u['php_mail'] !== 1) continue;
    $fence = ($u['php_mail_off_at'] !== null && strcmp((string)$u['php_mail_off_at'], $since) > 0) ? (string)$u['php_mail_off_at'] : $since;
    $s = MailGuard::localStats($lp, $acct, $fence);
    $why = MailGuard::phpVerdict($s);
    say("php mail $acct: ".json_encode($s).' -> '.($why ?? 'ok'));
    if ($why === null || $dry) continue;
    $n = Database::query("UPDATE users SET php_mail=0, php_mail_off_reason=?, php_mail_off_at=NOW() WHERE id=? AND php_mail=1", ['Mail guard: '.substr($why, 0, 230), $u['id']])->rowCount();
    if ($n !== 1) continue;
    $offed++;
    run(['/usr/local/skypanel/scripts/mail-owners-sync.sh']);
    $frozen = freezeIds(array_map(fn($x) => $x[1], $lp['subs'][$acct] ?? []));
    Helpers::log("mail-guard: PHP mail OFF for $acct ($why); froze $frozen queued message(s)");
    $title = "PHP mail switched off for account $acct: sending flood";
    $body = "KarnakPanel switched off PHP mail (mail()/sendmail from websites, scripts and cron) for account $acct — $why.\n"
        ."That usually means a site form or script is being abused to send mail (spam, or bots signing up with other people's addresses), or a site was hacked.\n\n"
        ."Mail sent through the account's mailboxes is unaffected. $frozen queued message(s) were frozen for review.\n"
        ."To keep a site sending meanwhile, set its mail/SMTP plugin to log in to one of your mailboxes: the SMTP settings on your Email page (port 587, the full mailbox address and its password).\n"
        ."Fix the cause (add a CAPTCHA or rate limit to the form, update or clean the site), then ask your admin to switch PHP mail back on.";
    $adm = "\n\nAdmin: switch it back on under Admin -> Accounts -> $acct once the cause is fixed. Frozen messages are in Admin -> Mail queue (drop the spam, thaw anything legitimate).";
    Helpers::notify((int)$u['id'], 'mail-guard', $title, $body);
    Helpers::notify(null, 'mail-guard', $title, $body.$adm);
    foreach (adminRecipients() as $to) mailTo($to, $title, $body.$adm);
}
say("php mail switched off for $offed account(s)");

// form abuse: hourly (state file), 24 h window, advisory only
$stamp = '/var/lib/karnakpanel/form-abuse.stamp';
if (in_array('--forms', $argv, true) || !is_file($stamp) || filemtime($stamp) < time() - 3300) {
    if (!$dry) { @mkdir(dirname($stamp), 0700, true); @touch($stamp); }
    formAbuseScan($logDir, $accts, $dry);
}

function formAbuseScan(string $logDir, array $accts, bool $dry): void {
    $since = (string)Database::query("SELECT DATE_FORMAT(NOW() - INTERVAL 1 DAY, '%Y-%m-%d %H:%i:%s') s")->fetch()['s'];
    $off = (int)Database::query("SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) o")->fetch()['o'];
    $p = MailGuard::parseLocal(logLines($logDir, 26 * 3600), $since, $accts);
    $from = time() - 86400 - 60;
    foreach ($p['subs'] as $acct => $subs) {
        // cheap pre-filter on the mail log alone before opening any access log
        $rc = [];
        foreach ($subs as [, $id]) foreach (array_keys($p['rcpt'][$id] ?? []) as $a) $rc[$a] = true;
        if (count($subs) < MailGuard::FORM_MIN_MSGS || count($rc) < MailGuard::FORM_MIN_MSGS) continue;
        $uid = (int)$accts[$acct]['id'];
        $doms = array_map(fn($r) => strtolower((string)$r['d']), Database::query(
            "SELECT domain d FROM domains WHERE user_id=? UNION SELECT a.alias FROM domain_aliases a JOIN domains d ON d.id=a.domain_id WHERE d.user_id=?", [$uid, $uid])->fetchAll());
        $posts = [];
        foreach ($doms as $d) {
            if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,62}\.)+[a-z][a-z0-9-]{1,62}$/', $d)) continue;
            $base = "/var/log/karnakpanel/$d.access.log";
            $files = array_filter(glob("$base.*") ?: [], fn($f) => !str_ends_with($f, '.gz') && is_file($f) && !is_link($f) && filemtime($f) >= $from);
            if (is_file($base) && !is_link($base)) $files[] = $base;
            foreach ($files as $f) {
                $h = fopen($f, 'r');
                if (!$h) continue;
                while (($l = fgets($h, 8192)) !== false) {
                    if (!str_contains($l, '"POST ')) continue;
                    $q = MailGuard::accessPost($l);
                    if ($q !== null && $q[0] >= $from) $posts[$d][] = $q;
                }
                fclose($h);
            }
        }
        if (!$posts) continue;
        $ep = array_map(fn($x) => [strtotime($x[0].' UTC') - $off, $x[1]], $subs);
        foreach (MailGuard::formAbuse($ep, $posts, $p['rcpt']) as $f) {
            say("form abuse $acct: ".json_encode($f));
            if ($dry) continue;
            $seen = Database::query("SELECT 1 FROM form_alerts WHERE user_id=? AND domain=? AND path=? AND notified_at > NOW() - INTERVAL 7 DAY", [$uid, $f['domain'], $f['path']])->fetch();
            if ($seen) continue;
            Database::query("REPLACE INTO form_alerts(user_id, domain, path, notified_at) VALUES(?,?,?,NOW())", [$uid, $f['domain'], $f['path']]);
            $how = [];
            if ($f['bad_pct'] >= 50) $how[] = "{$f['bad_pct']}% of the requests came from outdated browsers or bots";
            if ($f['fail_pct'] >= 30) $how[] = "{$f['fail_pct']}% of the emails bounced or could not be delivered";
            $title = "Possible form abuse on {$f['domain']} ({$f['path']})";
            $body = "In the last 24 hours the form at {$f['domain']}{$f['path']} sent {$f['msgs']} emails to {$f['rcpts']} different addresses, "
                ."triggered by {$f['ips']} different IP addresses; ".implode(', and ', $how).".\n\n"
                ."This usually means bots are submitting the form with other people's email addresses — the form probably has no CAPTCHA. "
                ."Each of those emails goes out from this server, so it hurts the domain's and the server's mail reputation (spam folders, blacklists).\n\n"
                ."Fix: add a CAPTCHA (Cloudflare Turnstile, hCaptcha or reCAPTCHA) or rate limiting to that form. "
                ."If these are real sign-ups, you can ignore this notice. This notice repeats at most once a week per form.";
            Helpers::notify($uid, 'form-abuse', $title, $body);
            Helpers::notify(null, 'form-abuse', $title." — account $acct", $body);
            foreach (adminRecipients() as $to) mailTo($to, $title." — account $acct", $body);
            Helpers::log("mail-guard: form abuse notice $acct {$f['domain']}{$f['path']} ".json_encode($f));
        }
    }
}

function run(array $cmd): string {
    // nosemgrep: php.lang.security.exec-use.exec-use -- array argv, no shell
    $p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($p)) return '';
    $out = (string)stream_get_contents($pipes[1]); fclose($pipes[1]); proc_close($p);
    return $out;
}
/** Freeze this sender's queued messages (exiqgrep -f matches the sender). */
function freezeQueued(string $addr): int {
    $ids = array_filter(preg_split('/\s+/', run(['exiqgrep', '-i', '-f', '^<?'.preg_quote($addr, '/').'>?$'])) ?: [],
        fn($i) => (bool)preg_match('/^[0-9A-Za-z]{6}-[0-9A-Za-z]{6,11}-[0-9A-Za-z]{2,4}$/', $i));
    if (!$ids) return 0;
    run(array_merge(['exim', '-Mf'], array_values($ids)));
    return count($ids);
}
/** Freeze those of these message ids still in the queue (covers null-sender mail too). */
function freezeIds(array $ids): int {
    $q = array_flip(array_filter(preg_split('/\s+/', run(['exiqgrep', '-i'])) ?: [], fn($i) => $i !== ''));
    $ids = array_values(array_filter(array_unique($ids), fn($i) => isset($q[$i]) && preg_match('/^[0-9A-Za-z]{6}-[0-9A-Za-z]{6,11}-[0-9A-Za-z]{2,4}$/', $i)));
    if (!$ids) return 0;
    run(array_merge(['exim', '-Mf'], $ids));
    return count($ids);
}
function adminRecipients(): array {
    $ok = fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL) && !str_ends_with($e, '@localhost');
    $a = (string)(Database::query("SELECT value FROM settings WHERE name='alert_email'")->fetch()['value'] ?? '');
    if ($ok($a)) return [$a];
    $out = [];
    foreach (Database::query("SELECT DISTINCT email FROM users WHERE role='admin'")->fetchAll() as $r) if ($ok((string)$r['email'])) $out[] = (string)$r['email'];
    return $out;
}
function mailTo(string $to, string $subject, string $body): void {
    $host = trim((string)shell_exec('hostname -f 2>/dev/null')) ?: (gethostname() ?: 'localhost');
    if (!preg_match('/^[a-z0-9.-]+$/i', $host)) $host = 'localhost';
    $msg = "To: $to\nFrom: KarnakPanel <noreply@$host>\nAuto-Submitted: auto-generated\n"
        ."Subject: ".str_replace(["\r", "\n"], ' ', $subject)."\nContent-Type: text/plain; charset=UTF-8\n\n$body\n";
    // nosemgrep: php.lang.security.exec-use.exec-use -- fixed sendmail argv, no shell
    $p = proc_open(['/usr/sbin/sendmail', '-t', '-i'], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (is_resource($p)) { fwrite($pipes[0], $msg); fclose($pipes[0]); proc_close($p); }
}
