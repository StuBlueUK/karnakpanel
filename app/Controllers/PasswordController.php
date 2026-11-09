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
        $pwned = Auth::passwordPwned($new);
        if ($pwned !== null && $pwned > 0) { Helpers::flash('err',"That password appears in $pwned known breach(es) — pick one that hasn't leaked"); Helpers::redirect('/password'); }
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '?');
        if (Auth::passwordThrottled((int)$u['id'], $ip)) {
            Helpers::flash('err','Too many wrong current passwords — try again in 15 minutes'); Helpers::redirect('/password');
        }
        $row = ['password_hash' => User::passwordHash((int)$u['id'])];
        if (!$row || !password_verify($cur, $row['password_hash'])) {
            Auth::passwordFailed((int)$u['id'], $ip, 'password change');
            Helpers::flash('err','Current password is wrong'); Helpers::redirect('/password');
        }
        try { LoginAttempt::clearBucket('password:'.$u['id']); } catch (Throwable $e) {}
        User::setPasswordHash((int)$u['id'], Auth::hash($new));
        ServiceManager::run('password-sync.sh', [$u['username'], new Secret($new)]);
        // end every OTHER session: a cookie stolen before the change must not
        // survive it; the caller's own session stays (they just used it)
        $killed = Auth::killSessions($u['username'], session_id());
        Helpers::log("password changed for {$u['username']} ($killed other sessions ended)");
        Helpers::notify((int)$u['id'], 'security', "Password changed");
        Helpers::flash('ok','Password changed');
        Helpers::redirect('/password');
    }
}
