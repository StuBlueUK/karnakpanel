<?php
declare(strict_types=1);
class TwoFactorController extends Controller {
    public function form(): void {
        Auth::requireLogin();
        $u = Auth::user();
        $enabled = LoginAttempt::totpEnabled((int)$u['id']);
        $required = '';
        try { $required = (string)(Settings::get('totp_required') ?? ''); } catch (Throwable $e) {}
        $passkeys = [];
        try { $passkeys = User::passkeys((int)$u['id']); } catch (Throwable $e) {}
        $this->view('twofactor', ['enabled' => $enabled,
            'pending' => $_SESSION['totp_new'] ?? null, 'username' => $u['username'],
            'required' => $required, 'isAdmin' => ($u['role'] ?? '') === 'admin',
            'passkeys' => $passkeys, 'pkRp' => WebAuthn::rpId(), 'pkHome' => WebAuthn::homeUrl(),
            'impersonating' => Auth::impersonator() !== null]);
    }
    // Global enforcement (admin): when on, admin+reseller logins without 2FA
    // are herded to /2fa until they enable it. Users are never forced (a
    // locked-out customer can't call anyone). Single-admin boxes: enabling
    // this before YOUR OWN 2FA works is a lockout — the page warns, and
    // recovery is another admin (Accounts → Reset 2FA) or DB/console access.
    public function enforce(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/2fa'); }
        $on = (isset($_POST['on']) && $_POST['on'] === '1') ? '1' : '0';
        try {
            Settings::put('totp_required', $on);
            Helpers::flash('ok', $on === '1' ? '2FA now REQUIRED for admin + reseller logins' : '2FA requirement off (opt-in per account)');
        } catch (Throwable $e) {
            try {
                Settings::ensureTable();
                Settings::put('totp_required', $on);
                Helpers::flash('ok','2FA requirement saved');
            } catch (Throwable $e2) { Helpers::flash('err','Save failed: '.$e2->getMessage()); }
        }
        Helpers::redirect('/2fa');
    }
    // step 1: confirm password, stage a fresh secret in session (never DB until verified)
    public function setup(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/2fa'); }
        if (Auth::impersonator() !== null) { Helpers::flash('err','Not while logged in as another account'); Helpers::redirect('/2fa'); }
        $u = Auth::user();
        $pc = self::passwordCheck((int)$u['id']);
        if ($pc === 'throttled') { Helpers::flash('err','Too many wrong passwords — try again in 15 minutes'); Helpers::redirect('/2fa'); }
        if ($pc !== 'ok') { Helpers::flash('err','Password is wrong'); Helpers::redirect('/2fa'); }
        $_SESSION['totp_new'] = Totp::makeSecret();
        Helpers::redirect('/2fa');
    }
    // step 2: code proves the app scanned correctly -> persist
    public function enable(): void {
        Auth::requireLogin();
        if (Auth::impersonator() !== null) { Helpers::flash('err','Not while logged in as another account'); Helpers::redirect('/2fa'); }
        if (!Helpers::checkCsrf() || empty($_SESSION['totp_new'])) { Helpers::flash('err','Start setup first'); Helpers::redirect('/2fa'); }
        $u = Auth::user();
        $sec = $_SESSION['totp_new'];
        $step = Totp::verifyStep($sec, preg_replace('/\D/', '', (string)($_POST['code'] ?? '')));
        if ($step === null) {
            Helpers::flash('err','Code does not match — check your authenticator clock'); Helpers::redirect('/2fa');
        }
        // the setup code counts as used: it can't be replayed at the next login
        User::setTotp((int)$u['id'], $sec, (int)$step);
        unset($_SESSION['totp_new']);
        Helpers::log("2FA enabled for {$u['username']}");
        Helpers::notify((int)$u['id'], 'security', "Two-factor authentication enabled");
        Helpers::flash('ok','Two-factor enabled — your next login will ask for a code');
        Helpers::redirect('/2fa');
    }
    public function disable(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/2fa'); }
        if (Auth::impersonator() !== null) { Helpers::flash('err','Not while logged in as another account'); Helpers::redirect('/2fa'); }
        $u = Auth::user();
        $pc = self::passwordCheck((int)$u['id']);
        if ($pc === 'throttled') { Helpers::flash('err','Too many wrong passwords — try again in 15 minutes'); Helpers::redirect('/2fa'); }
        if ($pc !== 'ok') { Helpers::flash('err','Password is wrong'); Helpers::redirect('/2fa'); }
        if (self::lastFactorBlocked($u, true, 0)) { Helpers::flash('err','2FA is required for your role — add a passkey before removing the authenticator app'); Helpers::redirect('/2fa'); }
        // the APP only: passkeys are removed one by one in their own card
        User::clearTotpApp((int)$u['id']);
        $still = WebAuthn::countFor((int)$u['id']) > 0;
        Helpers::log("2FA app removed for {$u['username']}".($still ? ' (passkeys remain)' : ''));
        Helpers::notify((int)$u['id'], 'security', $still ? "Authenticator app removed (passkeys still protect logins)" : "Two-factor authentication disabled");
        Helpers::flash('ok', $still ? 'Authenticator app removed — logins now use your passkeys' : 'Two-factor disabled'); Helpers::redirect('/2fa');
    }

    // Removing your LAST second factor turns 2FA off — refused while 2FA is
    // required for your role (it would only herd you straight back here).
    private static function lastFactorBlocked(array $u, bool $removingApp, int $removingPasskeys): bool {
        if (!in_array($u['role'] ?? '', ['admin', 'reseller'], true)) return false;
        try { if ((string)(Settings::get('totp_required') ?? '') !== '1') return false; } catch (Throwable $e) { return false; }
        $app = !$removingApp && LoginAttempt::totpEnabled((int)$u['id']);
        return !$app && WebAuthn::countFor((int)$u['id']) - $removingPasskeys <= 0;
    }
    // 'ok' | 'bad' | 'throttled' — throttled so a stolen session can't brute
    // the account password through these pages (no lockout on /login covers it).
    private static function passwordCheck(int $uid): string {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '?');
        if (Auth::passwordThrottled($uid, $ip)) return 'throttled';
        $hash = User::passwordHash($uid);
        if ($hash !== null && password_verify((string)($_POST['password'] ?? ''), $hash)) {
            // a good check clears this user's re-check failures (never the IP bucket)
            try { LoginAttempt::clearBucket('password:'.$uid); } catch (Throwable $e) {}
            return 'ok';
        }
        Auth::passwordFailed($uid, $ip, '2fa');
        return 'bad';
    }
    // Passkey step 1 (fetch): password re-check, then creation options with
    // a session challenge bound to this user. Never while impersonating —
    // a passkey added by login-as would be a permanent back door.
    public function passkeyOptions(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) $this->json(['error' => 'Bad CSRF — reload the page'], 400);
        if (Auth::impersonator() !== null) $this->json(['error' => 'Not while logged in as another account'], 403);
        $rp = WebAuthn::rpId();
        if ($rp === null) $this->json(['error' => 'Passkeys only work at '.(WebAuthn::homeUrl() ?: 'the panel host (Services → Panel host)')], 400);
        $u = Auth::user();
        $pc = self::passwordCheck((int)$u['id']);
        if ($pc === 'throttled') $this->json(['error' => 'Too many wrong passwords — try again in 15 minutes'], 429);
        if ($pc !== 'ok') $this->json(['error' => 'Password is wrong'], 403);
        if (WebAuthn::countFor((int)$u['id']) >= 10) $this->json(['error' => 'Ten passkeys is the limit — remove one first'], 400);
        $ex = User::passkeyCreds((int)$u['id'], $rp);
        $this->json(['ok' => true, 'options' => [
            'challenge' => WebAuthn::challenge('reg', (int)$u['id']),
            'rp' => ['id' => $rp, 'name' => 'KarnakPanel'],
            'user' => ['id' => WebAuthn::b64u(pack('J', (int)$u['id'])), 'name' => (string)$u['username'], 'displayName' => (string)$u['username']],
            'pubKeyCredParams' => array_map(fn($a) => ['type' => 'public-key', 'alg' => $a], WebAuthn::ALGS),
            'timeout' => 120000, 'attestation' => 'none',
            'authenticatorSelection' => ['residentKey' => 'preferred', 'userVerification' => 'preferred'],
            'excludeCredentials' => array_map(fn($r) => ['type' => 'public-key', 'id' => $r['cred_id']], $ex),
        ]]);
    }
    // Passkey step 2 (fetch): verify the browser's answer, store the key
    public function passkeyAdd(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) $this->json(['error' => 'Bad CSRF — reload the page'], 400);
        if (Auth::impersonator() !== null) $this->json(['error' => 'Not while logged in as another account'], 403);
        $rp = WebAuthn::rpId();
        if ($rp === null) $this->json(['error' => 'Passkeys only work at the panel host'], 400);
        $u = Auth::user();
        $name = trim(preg_replace('/[^\p{L}\p{N} ._()\'-]/u', '', (string)($_POST['name'] ?? '')) ?? '');
        $name = mb_substr($name !== '' ? $name : 'Passkey', 0, 64);
        $r = WebAuthn::verifyRegistration($_POST, (int)$u['id'], $rp);
        if (is_string($r)) { Helpers::log("passkey add refused for {$u['username']}: $r"); $this->json(['error' => $r], 400); }
        try {
            User::addPasskey((int)$u['id'], $r, $rp, $name);
        } catch (Throwable $e) { $this->json(['error' => 'That passkey is already registered'], 400); }
        Helpers::log("passkey '$name' added for {$u['username']} (rp $rp".($r['synced'] ? ', synced' : '').")");
        Helpers::notify((int)$u['id'], 'security', "Passkey \"$name\" added to your account");
        Helpers::flash('ok', "Passkey \"$name\" added — your next login can use it instead of a code");
        $this->json(['ok' => true]);
    }
    public function passkeyDelete(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/2fa'); }
        if (Auth::impersonator() !== null) { Helpers::flash('err','Not while logged in as another account'); Helpers::redirect('/2fa'); }
        $u = Auth::user();
        $pc = self::passwordCheck((int)$u['id']);
        if ($pc === 'throttled') { Helpers::flash('err','Too many wrong passwords — try again in 15 minutes'); Helpers::redirect('/2fa'); }
        if ($pc !== 'ok') { Helpers::flash('err','Password is wrong'); Helpers::redirect('/2fa'); }
        $pk = User::passkey((int)$u['id'], (int)($_POST['id'] ?? 0));
        if (!$pk) { Helpers::flash('err','Passkey not found'); Helpers::redirect('/2fa'); }
        if (self::lastFactorBlocked($u, false, 1)) { Helpers::flash('err','2FA is required for your role — add another passkey or the authenticator app before removing this one'); Helpers::redirect('/2fa'); }
        User::deletePasskey((int)$u['id'], (int)$pk['id']);
        Helpers::log("passkey '{$pk['name']}' removed for {$u['username']}");
        Helpers::notify((int)$u['id'], 'security', "Passkey \"{$pk['name']}\" removed from your account");
        Helpers::flash('ok', "Passkey \"{$pk['name']}\" removed".(Auth::has2fa((int)$u['id']) ? '' : ' — 2FA is now OFF for this account'));
        Helpers::redirect('/2fa');
    }
}
