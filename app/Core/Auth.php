<?php
declare(strict_types=1);

class Auth {
    // nosemgrep: generic.secrets.security.detected-bcrypt-hash.detected-bcrypt-hash -- timing-parity dummy constant (all-A), not a credential
    private const DUMMY_HASH = '$2y$12$AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
    public static function user(): ?array {
        return $_SESSION['user'] ?? null;
    }
    public static function check(): bool { return isset($_SESSION['user']); }
    public static function role(): string { return $_SESSION['user']['role'] ?? 'guest'; }
    // Fresh users row for this request (null until requireLogin ran).
    private static ?array $fresh = null;
    public static function fresh(): ?array { return self::$fresh; }
    public static function requireLogin(): void {
        if (!self::check()) Helpers::redirect('/login');
        $u = $_SESSION['user'];
        // Live account check: the session snapshots the row at login, so a
        // suspend/delete/role change would otherwise survive until logout.
        // One PK read per request (Controller::view reuses it for quota).
        // DB errors fail open to the snapshot — an outage must not log
        // everyone out mid-task; suspend/delete need a working DB anyway.
        try {
            $row = Database::query("SELECT * FROM users WHERE id=?", [$u['id'] ?? 0])->fetch();
            if (!$row || ($row['status'] ?? '') !== 'active' || ($row['role'] ?? '') !== ($u['role'] ?? '')) {
                $who = (string)($u['username'] ?? '?');
                $_SESSION = [];
                session_destroy();
                Helpers::log("session ended for '$who': account ".(!$row ? 'deleted' : (($row['status'] ?? '') !== 'active' ? 'not active' : 'role changed')));
                session_start(); session_regenerate_id(true);
                Helpers::flash('err', 'Your session has ended — please log in again');
                Helpers::redirect('/login');
            }
            self::$fresh = $row;
        } catch (Throwable $e) { $row = null; }
        // 2FA enforcement: privileged accounts without TOTP get herded to
        // /2fa. Fresh DB check (session row is stale after enabling). Token-
        // API never passes through here, so automation keeps working. The
        // /2fa + /logout + /password routes stay reachable or it's a trap.
        if (in_array($u['role'] ?? '', ['admin', 'reseller'], true)) {
            try {
                $req = Database::query("SELECT value FROM settings WHERE name='totp_required'")->fetch()['value'] ?? '';
                if ($req === '1') {
                    $row = $row ?? Database::query("SELECT totp_enabled FROM users WHERE id=?", [$u['id']])->fetch();
                    if (empty($row['totp_enabled'])) {
                        $path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
                        if (!preg_match('#^/(2fa|logout|password)#', $path)) {
                            Helpers::flash('err','Two-factor is required for your role — enable it to continue');
                            Helpers::redirect('/2fa');
                        }
                    }
                }
            } catch (Throwable $e) {}
        }
    }
    public static function requireRole(array $roles): void {
        self::requireLogin();
        if (!in_array(self::role(), $roles, true)) {
            http_response_code(403); echo "Forbidden"; exit;
        }
    }
    // returns 'ok', 'totp' (password good, second factor required), or false
    public static function attempt(string $username, string $password) {
        $st = Database::query("SELECT * FROM users WHERE username=? AND status='active' LIMIT 1", [$username]);
        $u = $st->fetch();
        // timing parity: unknown users cost one bcrypt too, or usernames
        // become enumerable by response time (valid-format dummy, ~250ms).
        if (!$u) { password_verify($password, self::DUMMY_HASH); return false; }
        if (!password_verify($password, $u['password_hash'])) return false;
        unset($u['password_hash']);
        session_regenerate_id(true); // fixation defense: fresh ID on privilege change
        if (!empty($u['totp_enabled']) && !empty($u['totp_secret'])) {
            $_SESSION['totp_pending'] = $u['id']; // NOT logged in yet — code still required
            return 'totp';
        }
        self::finishLogin($u);
        return 'ok';
    }
    public static function finishTotp(int $userId): bool {
        if (($_SESSION['totp_pending'] ?? null) !== $userId) return false;
        $u = Database::query("SELECT * FROM users WHERE id=? AND status='active' LIMIT 1", [$userId])->fetch();
        if (!$u || empty($u['totp_enabled'])) return false;
        unset($u['password_hash'], $_SESSION['totp_pending']);
        session_regenerate_id(true);
        self::finishLogin($u);
        return true;
    }
    private static function finishLogin(array $u): void {
        $_SESSION['user'] = $u;
        $now = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $prev = (string)($u['last_ip'] ?? '');
        Database::query("UPDATE users SET last_login=NOW(), last_ip=? WHERE id=?", [$now, $u['id']]);
        // new-IP alert: first login (no previous IP) stays silent, and the
        // hourly notify dedup absorbs dynamic-IP flapping. Impersonation
        // never passes through here, so no false alarms from login-as.
        if ($prev !== '' && $now !== '' && $prev !== $now) {
            Helpers::notify((int)$u['id'], 'login-new-ip',
                "Login for '{$u['username']}' from a new IP",
                "Previous: $prev — now: $now. If this wasn't you, change the password and check Admin > Sessions.");
            Helpers::log("login-new-ip for '{$u['username']}': $prev -> $now");
        }
    }
    public static function logout(): void {
        unset($_SESSION['user']); session_destroy();
    }
    // Support login-as: $targetId must be visible to $me (admin: anyone
    // non-admin; reseller: own users only). Never nested, never self.
    public static function impersonate(int $targetId): bool {
        $me = self::user();
        if (!$me || isset($_SESSION['impersonator'])) return false;
        $t = Database::query("SELECT * FROM users WHERE id=? AND status='active' LIMIT 1", [$targetId])->fetch();
        if (!$t || (int)$t['id'] === (int)$me['id']) return false;
        if ($t['role'] === 'admin') return false; // no lateral admin hops
        if ($me['role'] === 'reseller' && ((int)$t['owner_id'] !== (int)$me['id'] || $t['role'] !== 'user')) return false;
        if ($me['role'] === 'user') return false;
        unset($t['password_hash']);
        $_SESSION['impersonator'] = ['id' => $me['id'], 'username' => $me['username']];
        $_SESSION['user'] = $t;
        session_regenerate_id(true);
        Helpers::log("login-as: {$me['username']} -> {$t['username']}");
        Helpers::notify(null, 'login-as', "{$me['username']} switched to {$t['username']}");
        return true;
    }
    public static function stopImpersonating(): bool {
        if (!isset($_SESSION['impersonator'])) return false;
        $orig = Database::query("SELECT * FROM users WHERE id=? AND status='active' LIMIT 1", [$_SESSION['impersonator']['id']])->fetch();
        $who = $_SESSION['user']['username'] ?? '?';
        if (!$orig) { self::logout(); return false; } // original gone: safest is full logout
        unset($orig['password_hash']);
        $_SESSION['user'] = $orig;
        Helpers::log("login-as return: {$orig['username']} (was $who)");
        unset($_SESSION['impersonator']);
        session_regenerate_id(true);
        return true;
    }
    public static function impersonator(): ?array {
        return $_SESSION['impersonator'] ?? null;
    }
}
