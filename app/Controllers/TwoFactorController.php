<?php
declare(strict_types=1);
class TwoFactorController extends Controller {
    public function form(): void {
        Auth::requireLogin();
        $u = Auth::user();
        $row = Database::query("SELECT totp_enabled FROM users WHERE id=?", [$u['id']])->fetch();
        $required = '';
        try { $required = (string)(Database::query("SELECT value FROM settings WHERE name='totp_required'")->fetch()['value'] ?? ''); } catch (Throwable $e) {}
        $this->view('twofactor', ['enabled' => !empty($row['totp_enabled']),
            'pending' => $_SESSION['totp_new'] ?? null, 'username' => $u['username'],
            'required' => $required, 'isAdmin' => ($u['role'] ?? '') === 'admin']);
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
            Database::query("INSERT INTO settings(name,value) VALUES('totp_required',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [$on]);
            Helpers::flash('ok', $on === '1' ? '2FA now REQUIRED for admin + reseller logins' : '2FA requirement off (opt-in per account)');
        } catch (Throwable $e) {
            try {
                Database::query("CREATE TABLE IF NOT EXISTS settings (name VARCHAR(190) PRIMARY KEY, value TEXT NOT NULL)");
                Database::query("INSERT INTO settings(name,value) VALUES('totp_required',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [$on]);
                Helpers::flash('ok','2FA requirement saved');
            } catch (Throwable $e2) { Helpers::flash('err','Save failed: '.$e2->getMessage()); }
        }
        Helpers::redirect('/2fa');
    }
    // step 1: confirm password, stage a fresh secret in session (never DB until verified)
    public function setup(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/2fa'); }
        $u = Auth::user();
        $row = Database::query("SELECT password_hash FROM users WHERE id=?", [$u['id']])->fetch();
        if (!$row || !password_verify((string)($_POST['password'] ?? ''), $row['password_hash'])) {
            Helpers::flash('err','Password is wrong'); Helpers::redirect('/2fa');
        }
        $_SESSION['totp_new'] = Totp::makeSecret();
        Helpers::redirect('/2fa');
    }
    // step 2: code proves the app scanned correctly -> persist
    public function enable(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf() || empty($_SESSION['totp_new'])) { Helpers::flash('err','Start setup first'); Helpers::redirect('/2fa'); }
        $u = Auth::user();
        $sec = $_SESSION['totp_new'];
        $step = Totp::verifyStep($sec, preg_replace('/\D/', '', (string)($_POST['code'] ?? '')));
        if ($step === null) {
            Helpers::flash('err','Code does not match — check your authenticator clock'); Helpers::redirect('/2fa');
        }
        // the setup code counts as used: it can't be replayed at the next login
        Database::query("UPDATE users SET totp_secret=?, totp_enabled=1, totp_last_step=? WHERE id=?", [$sec, $step, $u['id']]);
        unset($_SESSION['totp_new']);
        Helpers::log("2FA enabled for {$u['username']}");
        Helpers::notify((int)$u['id'], 'security', "Two-factor authentication enabled");
        Helpers::flash('ok','Two-factor enabled — your next login will ask for a code');
        Helpers::redirect('/2fa');
    }
    public function disable(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/2fa'); }
        $u = Auth::user();
        $row = Database::query("SELECT password_hash FROM users WHERE id=?", [$u['id']])->fetch();
        if (!$row || !password_verify((string)($_POST['password'] ?? ''), $row['password_hash'])) {
            Helpers::flash('err','Password is wrong'); Helpers::redirect('/2fa');
        }
        Database::query("UPDATE users SET totp_secret=NULL, totp_enabled=0 WHERE id=?", [$u['id']]);
        Helpers::log("2FA disabled for {$u['username']}");
        Helpers::notify((int)$u['id'], 'security', "Two-factor authentication disabled");
        Helpers::flash('ok','Two-factor disabled'); Helpers::redirect('/2fa');
    }
}
