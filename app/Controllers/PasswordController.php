<?php
declare(strict_types=1);
class PasswordController extends Controller {
    public function form(): void {
        Auth::requireLogin();
        $this->view('password', []);
    }
    public function update(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/password'); }
        $u = Auth::user();
        $cur = (string)($_POST['current'] ?? '');
        $new = (string)($_POST['new'] ?? '');
        $conf = (string)($_POST['confirm'] ?? '');
        if (strlen($new) < 10) { Helpers::flash('err','New password must be 10+ characters'); Helpers::redirect('/password'); }
        if ($new !== $conf) { Helpers::flash('err','New passwords do not match'); Helpers::redirect('/password'); }
        $pwned = Helpers::passwordPwned($new);
        if ($pwned !== null && $pwned > 0) { Helpers::flash('err',"That password appears in $pwned known breach(es) — pick one that hasn't leaked"); Helpers::redirect('/password'); }
        $row = Database::query("SELECT password_hash FROM users WHERE id=?", [$u['id']])->fetch();
        if (!$row || !password_verify($cur, $row['password_hash'])) {
            Helpers::flash('err','Current password is wrong'); Helpers::redirect('/password');
        }
        Database::query("UPDATE users SET password_hash=? WHERE id=?", [password_hash($new, PASSWORD_BCRYPT), $u['id']]);
        ServiceManager::run('password-sync.sh', [$u['username'], new Secret($new)]);
        Helpers::log("password changed for {$u['username']}");
        Helpers::notify((int)$u['id'], 'security', "Password changed");
        Helpers::flash('ok','Password changed');
        Helpers::redirect('/password');
    }
}
