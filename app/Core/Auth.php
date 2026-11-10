<?php
declare(strict_types=1);

class Auth {
    // One cost for every panel-user hash AND the timing dummy, or the
    // unknown-user path verifies faster/slower than real users and usernames
    // become enumerable by response time. Legacy rows are upgraded on login.
    public const BCRYPT_COST = 12;
    // nosemgrep: generic.secrets.security.detected-bcrypt-hash.detected-bcrypt-hash -- timing-parity dummy, not a credential
    private const DUMMY_HASH = '$2y$12$G5i0Tnk0tLB.dSQKNocomueLTIuhJrTnqiUnc5agIPxemOV6Z11c.';
    public static function hash(string $password): string {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
    }
    public static function user(): ?array {
        return $_SESSION['user'] ?? null;
    }
    /**
     * Keep the session snapshot's quota fields in step with a live users row
     * (a package change must show up on the dashboard immediately). The
     * snapshot is owned here — callers never touch the session key directly.
     */
    public static function syncQuotas(array $row): void {
        if (!isset($_SESSION['user'])) { return; }
        $_SESSION['user']['disk_quota_mb'] = $row['disk_quota_mb'] ?? null;
        $_SESSION['user']['bandwidth_quota_gb'] = $row['bandwidth_quota_gb'] ?? null;
    }
    // "has a second factor": the authenticator app OR at least one passkey.
    // SQL form for list queries (users table unaliased) + a per-user check.
    public const HAS_2FA = "(users.totp_enabled=1 OR EXISTS(SELECT 1 FROM user_passkeys pk WHERE pk.user_id=users.id))";
    public static function has2fa(int $userId): bool {
        try {
            $r = Database::query("SELECT totp_enabled FROM users WHERE id=?", [$userId])->fetch();
            if (!empty($r['totp_enabled'])) return true;
        } catch (Throwable $e) { return false; }
        return WebAuthn::countFor($userId) > 0;
    }
    public static function check(): bool { return self::user() !== null; }
    public static function role(): string { return (string)(self::user()['role'] ?? 'guest'); }
    // Idle + absolute session lifetime (security sweep 2026-11-08 M2). A stolen
    // session cookie used to live until PHP's probabilistic GC — hours to days,
    // with no ceiling. Both clocks are session keys owned here and checked on
    // EVERY authed request in requireLogin(); impersonation does not restart
    // them (login-as must not extend the real login's life).
    public const SESSION_IDLE = 7200;   // 2 h without a request
    public const SESSION_MAX  = 43200;  // 12 h from login, active or not
    /**
     * Pure decision, unit-tested: 'idle', 'absolute' or null. A session from
     * before these stamps existed has login_at <= 0 and is stamped on its next
     * request instead of being logged out en masse by the upgrade.
     * @param array<string,mixed> $s
     */
    public static function expiryReason(array $s, int $now): ?string {
        $start = (int)($s['login_at'] ?? 0);
        if ($start <= 0) return null;
        if ($now - $start > self::SESSION_MAX) return 'absolute';
        $last = (int)($s['last_activity'] ?? $start);
        return ($now - $last > self::SESSION_IDLE) ? 'idle' : null;
    }
    // Fresh users row for this request (null until requireLogin ran).
    private static ?array $fresh = null;
    public static function fresh(): ?array { return self::$fresh; }
    public static function requireLogin(): void {
        if (!self::check()) Helpers::redirect('/login');
        $u = self::user() ?? [];
        $now = time();
        if (!isset($_SESSION['login_at'])) $_SESSION['login_at'] = $now; // pre-timeout session
        if (!isset($_SESSION['last_activity'])) $_SESSION['last_activity'] = $now;
        $why = self::expiryReason($_SESSION, $now);
        if ($why !== null) {
            $who = (string)($u['username'] ?? '?');
            $_SESSION = [];
            session_destroy();
            Helpers::log("session expired for '$who' ($why timeout)");
            session_start(); session_regenerate_id(true);
            Helpers::flash('err', $why === 'idle'
                ? 'Your session expired after '.intdiv(self::SESSION_IDLE, 60).' minutes of inactivity — please log in again'
                : 'Your session reached its maximum lifetime — please log in again');
            Helpers::redirect('/login');
        }
        $_SESSION['last_activity'] = $now; // sliding idle window
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
                    if (!self::has2fa((int)$u['id'])) {
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
    // returns 'ok', 'totp' (password good, second factor — app code or
    // passkey — required), or false
    public static function attempt(string $username, string $password) {
        $st = Database::query("SELECT * FROM users WHERE username=? AND status='active' LIMIT 1", [$username]);
        $u = $st->fetch();
        // timing parity: unknown users cost one bcrypt too, or usernames
        // become enumerable by response time (valid-format dummy, ~250ms).
        if (!$u) { password_verify($password, self::DUMMY_HASH); return false; }
        if (!password_verify($password, $u['password_hash'])) return false;
        // upgrade legacy cost-10 hashes on successful login (never fail login for it)
        if (password_needs_rehash($u['password_hash'], PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST])) {
            try { Database::query("UPDATE users SET password_hash=? WHERE id=?", [self::hash($password), (int)$u['id']]); } catch (Throwable $e) {}
        }
        unset($u['password_hash'], $u['totp_secret']);
        session_regenerate_id(true); // fixation defense: fresh ID on privilege change
        // gate on the FLAGS — never on $u['totp_secret'], which the scrub above
        // has already removed (a gate that tested it skipped the second factor
        // for authenticator-app accounts entirely; pinned in tests/run.php)
        if (!empty($u['totp_enabled']) || WebAuthn::countFor((int)$u['id']) > 0) {
            $_SESSION['totp_pending'] = $u['id']; // NOT logged in yet — code still required
            return 'totp';
        }
        self::finishLogin($u);
        return 'ok';
    }
    public static function finishTotp(int $userId): bool {
        // (int) on the session side: PDO int fetch typing must not decide this
        if ((int)($_SESSION['totp_pending'] ?? 0) !== $userId) return false;
        $u = Database::query("SELECT * FROM users WHERE id=? AND status='active' LIMIT 1", [$userId])->fetch();
        if (!$u || !self::has2fa((int)$u['id'])) return false;
        unset($u['password_hash'], $u['totp_secret'], $_SESSION['totp_pending']);
        session_regenerate_id(true);
        self::finishLogin($u);
        return true;
    }
    private static function finishLogin(array $u): void {
        unset($_SESSION['csrf']); // fresh token across the privilege change
        $_SESSION['user'] = $u;
        $_SESSION['login_at'] = time();      // absolute lifetime starts here
        $_SESSION['last_activity'] = $_SESSION['login_at'];
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
        unset($_SESSION['user']);
        // Expire the cookie too: session_destroy() only removes the server
        // side, and the browser would keep sending a dead SID (sweep Low).
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000, 'path' => $p['path'], 'domain' => $p['domain'],
                'secure' => (bool)$p['secure'], 'httponly' => (bool)$p['httponly'],
                'samesite' => (string)$p['samesite'],
            ]);
        }
        session_destroy();
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
        unset($t['password_hash'], $t['totp_secret']);
        $_SESSION['impersonator'] = ['id' => $me['id'], 'username' => $me['username']];
        $_SESSION['user'] = $t;
        unset($_SESSION['csrf']); // fresh token across the privilege change
        session_regenerate_id(true);
        Helpers::log("login-as: {$me['username']} -> {$t['username']}");
        Helpers::notify(null, 'login-as', "{$me['username']} switched to {$t['username']}");
        return true;
    }
    public static function stopImpersonating(): bool {
        if (!isset($_SESSION['impersonator'])) return false;
        $orig = Database::query("SELECT * FROM users WHERE id=? AND status='active' LIMIT 1", [$_SESSION['impersonator']['id']])->fetch();
        $who = (string)(self::user()['username'] ?? '?');
        if (!$orig) { self::logout(); return false; } // original gone: safest is full logout
        unset($orig['password_hash'], $orig['totp_secret']);
        $_SESSION['user'] = $orig;
        Helpers::log("login-as return: {$orig['username']} (was $who)");
        unset($_SESSION['impersonator'], $_SESSION['csrf']); // fresh token on return
        session_regenerate_id(true);
        return true;
    }
    public static function impersonator(): ?array {
        return $_SESSION['impersonator'] ?? null;
    }
    // End live panel sessions of $username. $exceptSid keeps the caller's own
    // session (self-service password change); null ends every one (admin reset,
    // role change, API password reset — a reset means the old session may be stolen).
    public static function killSessions(string $username, ?string $exceptSid = null): int {
        $killed = 0;
        try {
            $sess = ServiceManager::run('session-list.sh', []);
            foreach (explode("\n", trim((string)($sess['out'] ?? ''))) as $line) {
                $p = explode("\t", $line);
                if (count($p) >= 2 && $p[1] === $username && ($exceptSid === null || $p[0] !== $exceptSid)) {
                    $k = ServiceManager::run('session-kill.sh', [$p[0]]);
                    if (($k['code'] ?? 1) === 0) $killed++;
                }
            }
        } catch (Throwable $e) {}
        return $killed;
    }
    // Password re-check throttle outside /login (2FA setup/disable, passkey
    // add/delete, password change): a stolen session must not brute-force the
    // account password. Same table/shape as the login guards (fail open).
    // Throttle password re-checks outside /login (2FA setup/disable, passkey
    // add/delete, password change): a stolen session must not be able to
    // brute-force the account password without tripping a counter. Same table
    // and shape as the login/second-factor guards (fail-open if it's missing).
    public static function passwordThrottled(int $uid, string $ip): bool {
        try {
            $ipFails = (int)Database::query(
                "SELECT COUNT(*) c FROM login_attempts WHERE ip=? AND success=0 AND attempted_at > (NOW() - INTERVAL 15 MINUTE)",
                [$ip])->fetch()['c'];
            $userFails = (int)Database::query(
                "SELECT COUNT(*) c FROM login_attempts WHERE username=? AND success=0 AND attempted_at > (NOW() - INTERVAL 15 MINUTE)",
                ['password:'.$uid])->fetch()['c'];
            if ($ipFails >= 8 || $userFails >= 10) {
                Helpers::log("password re-check throttled for $ip (uid $uid, $ipFails ip / $userFails user recent failures)");
                return true;
            }
        } catch (Throwable $e) {}
        return false;
    }
    public static function passwordFailed(int $uid, string $ip, string $what): void {
        try { Database::query("INSERT INTO login_attempts(ip,username,success) VALUES(?,?,0)", [$ip, 'password:'.$uid]); } catch (Throwable $e) {}
        Helpers::log("failed password re-check ($what) for uid $uid from $ip");
        Helpers::notify(null, 'login-fail', "Failed password check ($what) for user #$uid", "from $ip — repeated failures mean someone holds this session");
    }
    // Breached-password check (HaveIBeenPwned k-anonymity): only the first 5
    // SHA1 chars leave the box, full hash never transmitted. Returns breach
    // count, 0 = unseen, null = check unavailable (fail OPEN — never block a
    // password change on a network error). One HTTPS call, only at set-time.
    public static function passwordPwned(string $pass): ?int {
        try {
            $sha = strtoupper(sha1($pass));
            $pre = substr($sha, 0, 5);
            $body = null;
            if (function_exists('curl_init')) {
                $ch = curl_init('https://api.pwnedpasswords.com/range/'.$pre);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
                    CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_USERAGENT => 'KarnakPanel-pwncheck',
                    CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
                $body = curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($body === false || $code !== 200) return null;
            } else {
                $ctx = stream_context_create(['http' => ['timeout' => 5, 'header' => 'User-Agent: KarnakPanel-pwncheck'],
                    'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
                $body = @file_get_contents('https://api.pwnedpasswords.com/range/'.$pre, false, $ctx);
                if ($body === false) return null;
            }
            foreach (explode("\n", (string)$body) as $line) {
                $p = explode(':', trim($line));
                if (count($p) === 2 && strtoupper($p[0]) === substr($sha, 5)) return (int)$p[1];
            }
            return 0;
        } catch (Throwable $e) { return null; }
    }
}
