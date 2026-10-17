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

$lines = (function () use ($logDir) {
    // mainlog.1 only while fresh (just after rotation the last hour spans
    // both); compressed generations are always older than the window
    foreach (["$logDir/mainlog.1", "$logDir/mainlog"] as $f) {
        if (!is_file($f) || is_link($f)) continue;
        if (str_ends_with($f, '.1') && filemtime($f) < time() - 7200) continue;
        $h = fopen($f, 'r');
        if (!$h) continue;
        while (($l = fgets($h, 8192)) !== false) yield rtrim($l, "\n");
        fclose($h);
    }
})();
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

function run(array $cmd): string {
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
    $p = proc_open(['/usr/sbin/sendmail', '-t', '-i'], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (is_resource($p)) { fwrite($pipes[0], $msg); fclose($pipes[0]); proc_close($p); }
}
