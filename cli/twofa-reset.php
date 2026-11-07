#!/usr/bin/env php
<?php
// twofa-reset.php <username> — root-only 2FA recovery for when NO admin can
// log in (the only admin lost their authenticator). Same effect as the
// Accounts-page reset: app secret AND every passkey cleared, 2FA off, every live panel session of
// that user ended, panel.log line + a notification to the user. They log in
// with their password and set 2FA up again (enforcement herds them to /2fa).
// Reached only via `karnakpanel 2fa-reset <user>` as root over SSH — the
// panel can never call it (not a scripts/*.sh, so not in the sudoers list).
declare(strict_types=1);
if (function_exists('posix_geteuid') ? posix_geteuid() !== 0 : trim((string)shell_exec('id -u')) !== '0') {
    fwrite(STDERR, "must run as root (karnakpanel 2fa-reset <user>)\n"); exit(1);
}
$root = dirname(__DIR__);
spl_autoload_register(function ($c) use ($root) {
    $f = "$root/app/Core/$c.php";
    if (preg_match('/^[A-Za-z]+$/', $c) && file_exists($f)) require_once $f;
});
Config::load($root.'/config/config.php');

$u = (string)($argv[1] ?? '');
if ($u === '') {
    echo "usage: karnakpanel 2fa-reset <username>\naccounts with 2FA on:\n";
    foreach (Database::query("SELECT username, role FROM users WHERE ".Auth::HAS_2FA." ORDER BY role, username")->fetchAll() as $r) {
        echo "  {$r['username']} ({$r['role']})\n";
    }
    exit(1);
}
if (!preg_match('/^[a-z][a-z0-9_-]{0,31}$/i', $u)) { fwrite(STDERR, "bad username\n"); exit(1); }
$t = Database::query("SELECT id, username, role FROM users WHERE username=?", [$u])->fetch();
if (!$t) { fwrite(STDERR, "no such account: $u\n"); exit(1); }
if (!Auth::has2fa((int)$t['id'])) { echo "{$t['username']} does not have 2FA on — nothing to do\n"; exit(0); }

Database::query("UPDATE users SET totp_secret=NULL, totp_enabled=0, totp_last_step=NULL WHERE id=?", [$t['id']]);
Database::query("DELETE FROM user_passkeys WHERE user_id=?", [$t['id']]);

// end live sessions (a lost device may be a stolen one) — we ARE root, so
// call the session tools directly rather than through sudo
$killed = 0;
$out = (string)shell_exec('/usr/local/skypanel/scripts/session-list.sh 2>/dev/null');
foreach (explode("\n", trim($out)) as $line) {
    $p = explode("\t", $line);
    if (count($p) >= 2 && $p[1] === $t['username']) {
        // nosemgrep: php.lang.security.exec-use.exec-use -- fixed script, escapeshellarg'd arg
        exec('/usr/local/skypanel/scripts/session-kill.sh '.escapeshellarg($p[0]).' 2>/dev/null', $o, $rc);
        if ($rc === 0) $killed++;
    }
}
$who = getenv('SUDO_USER') ?: (getenv('USER') ?: 'root');
Helpers::log("2FA reset for {$t['username']} by root via SSH ($who, $killed sessions killed)");
Helpers::notify((int)$t['id'], 'security', "Your two-factor authentication was reset from the server's root console — set it up again on the 2FA page");
echo "2FA reset for {$t['username']} ({$t['role']}) — log in with the password and set it up again ($killed live session(s) ended)\n";
