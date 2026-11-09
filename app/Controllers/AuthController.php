<?php
declare(strict_types=1);
// AuthController — sign-in side of the dashboard: password login, TOTP,
// passkeys and the failed-attempt throttles. Extends DashboardController
// for view()/json(); the dashboard page itself stays in the base.
class AuthController extends DashboardController {
    // failed password logins per IP per 15 minutes before the wait message
    public const LOGIN_MAX = 4;
    public function loginForm(): void {
        if (Auth::check()) Helpers::redirect('/');
        // NOTE: never hint at (default) credentials here — this page is
        // visible to unauthenticated visitors. First-login hardening lives
        // in the installer (random per-install admin password, file 0600).
        $this->view('login');
    }
    public function login(): void {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/login'); }
        // brute-force guard: LOGIN_MAX failures / 15 min per IP (table may not exist on old installs -> fail open).
        // Kept below the fail2ban panel jail (maxretry 10, counting failures +
        // throttled tries) so a person sees the wait message before any firewall ban.
        $fails = 0;
        try {
            $fails = LoginAttempt::failuresByIp($ip);
            // per-username bucket too: spreading guesses over many IPs must
            // not escape the limit (looser cap — it doubles as a lockout lever)
            $userFails = LoginAttempt::failuresByUser(substr((string)($_POST['username'] ?? ''), 0, 64));
            if ($fails >= self::LOGIN_MAX || $userFails >= 30) {
                Helpers::log("login throttled for $ip ($fails ip / $userFails user recent failures)");
                // real wait: until the oldest failure in the window ages out
                $byIp = $fails >= self::LOGIN_MAX;
                $key = $byIp ? $ip : substr((string)($_POST['username'] ?? ''), 0, 64);
                $left = LoginAttempt::waitMinutes($byIp, $key, $byIp ? self::LOGIN_MAX : 30);
                Helpers::flash('err', 'Too many failed attempts — try again in '.max(1, min(15, $left)).' minute'.($left === 1 ? '' : 's'));
                Helpers::redirect('/login');
            }
        } catch (Throwable $e) {}
        try {
            $res = Auth::attempt($_POST['username']??'', $_POST['password']??'');
            if ($res === 'ok') {
                // clear only THIS username's failures: wiping the whole IP
                // bucket let anyone with a valid account reset the counter
                // between guesses at someone else's
                try { LoginAttempt::clearFor($ip, substr((string)($_POST['username'] ?? ''), 0, 64)); } catch (Throwable $e) {}
                Helpers::redirect('/');
            }
            if ($res === 'totp') { Helpers::redirect('/login/totp'); }
        } catch (Throwable $e) {
            Helpers::log("login DB error: ".$e->getMessage());
            Helpers::flash('err','Login unavailable — try again in a minute');
            Helpers::redirect('/login');
        }
        try {
            LoginAttempt::record($ip, substr((string)($_POST['username'] ?? ''), 0, 64));
        } catch (Throwable $e) {}
        $rem = self::LOGIN_MAX - ($fails + 1);
        Helpers::flash('err', $rem <= 0 ? 'Invalid login — too many failed attempts, try again in 15 minutes'
            : ($rem <= 2 ? "Invalid login — $rem attempt".($rem === 1 ? '' : 's').' left before a 15-minute wait' : 'Invalid login'));
        Helpers::log("failed login for '".preg_replace('/[^a-z0-9_.@-]/i','?',$_POST['username']??'')."' from $ip");
        Helpers::notify(null, 'login-fail', "Failed login for '".preg_replace('/[^a-z0-9_.@-]/i','?',substr((string)($_POST['username'] ?? ''),0,32))."'", "from $ip");
        Helpers::redirect('/login');
    }
    public function logout(): void {
        if (!Helpers::checkCsrf()) { Helpers::redirect('/login'); }
        Auth::logout(); Helpers::redirect('/login');
    }
    public function totpForm(): void {
        if (empty($_SESSION['totp_pending'])) Helpers::redirect('/login');
        $uid = (int)$_SESSION['totp_pending'];
        $rp = WebAuthn::rpId();
        $hasApp = false;
        try { $hasApp = LoginAttempt::totpEnabled($uid); } catch (Throwable $e) {}
        $this->view('login_totp', ['hasApp' => $hasApp,
            'pkHere' => $rp !== null ? WebAuthn::countFor($uid, $rp) : 0,
            'pkAll' => WebAuthn::countFor($uid), 'pkHome' => WebAuthn::homeUrl()]);
    }
    public function totpVerify(): void {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
        $uid = (int)($_SESSION['totp_pending'] ?? 0);
        if ($uid <= 0) Helpers::redirect('/login');
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/login/totp'); }
        if (self::secondFactorThrottled($ip, $uid)) {
            Helpers::flash('err','Too many failed attempts — try again in 15 minutes');
            Helpers::redirect('/login/totp');
        }
        $code = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));
        $ok = false;
        try {
            $row = LoginAttempt::totpRow($uid);
            $step = $row ? Totp::verifyStep($row['totp_secret'], $code) : null;
            // replay guard: each 30s step is accepted once — the conditional
            // UPDATE is atomic, so a sniffed/shoulder-surfed code can't be
            // reused inside its window (nor raced in parallel)
            if ($step !== null) {
                $claimed = LoginAttempt::claimTotpStep($uid, (int)$step);
                if ($claimed && Auth::finishTotp($uid)) $ok = true;
            }
        } catch (Throwable $e) {}
        if ($ok) {
            try { LoginAttempt::clearForMany($ip, [(string)$row['username'], 'totp:'.$uid]); } catch (Throwable $e) {}
            Helpers::redirect('/');
        }
        self::secondFactorFailed($ip, $uid, 'totp');
        Helpers::flash('err','Invalid code');
        Helpers::redirect('/login/totp');
    }
    // Passkey login, step 1 (fetch): request options for the pending user,
    // limited to their passkeys made for THIS panel host.
    public function passkeyLoginOptions(): void {
        $uid = (int)($_SESSION['totp_pending'] ?? 0);
        if ($uid <= 0) $this->json(['error' => 'Log in with your password first', 'to' => '/login'], 400);
        if (!Helpers::checkCsrf()) $this->json(['error' => 'Bad CSRF — reload the page'], 400);
        $rp = WebAuthn::rpId();
        if ($rp === null) $this->json(['error' => 'Passkeys only work at '.(WebAuthn::homeUrl() ?: 'the panel host')], 400);
        $ids = LoginAttempt::passkeyIds($uid, $rp);
        if (!$ids) $this->json(['error' => 'No passkey for this account on this address'], 400);
        $this->json(['ok' => true, 'options' => [
            'challenge' => WebAuthn::challenge('auth', $uid), 'rpId' => $rp, 'timeout' => 120000,
            'userVerification' => 'preferred',
            'allowCredentials' => array_map(fn($r) => ['type' => 'public-key', 'id' => $r['cred_id']], $ids),
        ]]);
    }
    // Passkey login, step 2 (fetch): verify, then finish the login exactly
    // like a good app code (same throttle bucket, same session handling).
    public function passkeyLogin(): void {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
        $uid = (int)($_SESSION['totp_pending'] ?? 0);
        if ($uid <= 0) $this->json(['error' => 'Log in with your password first', 'to' => '/login'], 400);
        if (!Helpers::checkCsrf()) $this->json(['error' => 'Bad CSRF — reload the page'], 400);
        if (self::secondFactorThrottled($ip, $uid)) $this->json(['error' => 'Too many failed attempts — try again in 15 minutes'], 429);
        $rp = WebAuthn::rpId();
        if ($rp === null) $this->json(['error' => 'Passkeys only work at the panel host'], 400);
        $r = 'Login failed';
        try { $r = WebAuthn::verifyLogin($_POST, $uid, $rp); } catch (Throwable $e) {}
        if (is_int($r) && Auth::finishTotp($uid)) {
            $who = (string)(Auth::user()['username'] ?? '');
            try { LoginAttempt::clearForMany($ip, [$who, 'totp:'.$uid]); } catch (Throwable $e) {}
            Helpers::log("passkey login for '$who' from $ip");
            $this->json(['ok' => true, 'to' => '/']);
        }
        self::secondFactorFailed($ip, $uid, 'passkey');
        $this->json(['error' => is_string($r) ? $r : 'Login failed'], 403);
    }
    // throttle like passwords: 6-digit codes fall in hours unthrottled.
    // (Counts password + second-factor failures together — one bucket per IP.)
    private static function secondFactorThrottled(string $ip, int $uid): bool {
        try {
            $fails = LoginAttempt::failuresByIp($ip);
            // per-user cap across IPs: 6-digit codes must not fall to a spread run
            $userFails = LoginAttempt::failuresByUser('totp:'.$uid);
            if ($fails >= 8 || $userFails >= 10) {
                Helpers::log("2nd factor throttled for $ip (uid $uid, $fails ip / $userFails user recent failures)");
                return true;
            }
        } catch (Throwable $e) {}
        return false;
    }
    // hammering a second factor is always hostile — log + alert, unlike a
    // mistyped password (which only notifies). App codes and passkeys share
    // the bucket.
    private static function secondFactorFailed(string $ip, int $uid, string $what): void {
        try {
            LoginAttempt::record($ip, 'totp:'.$uid);
        } catch (Throwable $e) {}
        Helpers::log("failed $what for uid $uid from $ip");
        Helpers::notify(null, 'login-fail', "Failed second-factor ".($what === 'totp' ? 'code' : $what)." for user #$uid", "from $ip — repeated failures mean someone holds the password");
    }
}
