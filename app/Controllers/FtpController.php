<?php
declare(strict_types=1);
class FtpController extends Controller {
    public function list(): void {
        Auth::requireLogin();
        $u = Auth::user();
        $p = Helpers::paginate((int)Database::query("SELECT COUNT(*) c FROM ftp_accounts WHERE user_id=?", [$u['id']])->fetch()['c'], max(1, (int)($_GET['page'] ?? 1)));
        $pg = $p['pg']; $pages = $p['pages']; $total = $p['total'];
        $ftps = Database::query("SELECT * FROM ftp_accounts WHERE user_id=? ORDER BY ftp_user LIMIT ? OFFSET ?", [$u['id'], (int)$p['limit'], (int)$p['offset']])->fetchAll();
        $ftpHost = Helpers::ftpTlsHost((string)@file_get_contents('/etc/vsftpd.conf'));
        $this->view('ftp', compact('ftps','u','pg','pages','total','ftpHost'));
    }
    public function create(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/ftp'); }
        $u = Auth::user();
        $name = trim($_POST['ftp_user']??''); $pass = (string)($_POST['password']??'');
        if (!preg_match('/^[a-z0-9_]{3,32}$/',$name) || strlen($pass)<8) { Helpers::flash('err','Bad FTPS user/pass'); Helpers::redirect('/ftp'); }
        $login = $u['username'].'_'.$name;
        if ($err = Helpers::limitError($u, 'ftps')) { Helpers::flash('err', $err); Helpers::redirect('/ftp'); }
        // usernames may contain "_": owner "bob" + name "x" must never land on account "bob_x"
        if (Database::query("SELECT 1 FROM users WHERE username=?", [$login])->fetch()
            || Database::query("SELECT 1 FROM ftp_accounts WHERE ftp_user=?", [$login])->fetch()) {
            Helpers::flash('err', "The name $login is already taken"); Helpers::redirect('/ftp');
        }
        Database::query("INSERT INTO ftp_accounts(user_id,ftp_user,homedir) VALUES(?,?,?)", [$u['id'],$login,"/home/{$u['username']}"]);
        $r = ServiceManager::run('ftp-add.sh', [$login, new Secret($pass), "/home/{$u['username']}"]);
        if (($r['code'] ?? 1) !== 0) {
            Database::query("DELETE FROM ftp_accounts WHERE user_id=? AND ftp_user=?", [$u['id'], $login]);
            Helpers::flash('err', 'FTPS login not created: '.trim($r['out'] ?? '')); Helpers::redirect('/ftp');
        }
        Helpers::flash('ok',"FTPS login $login created");
        Helpers::redirect('/ftp');
    }
    // row must belong to the requester AND start with their username_ prefix
    // (stops suffix-spoofing across accounts sharing one panel DB)
    private function own(array $u, int $id): ?array {
        $row = Database::query("SELECT * FROM ftp_accounts WHERE id=? AND user_id=?", [$id, $u['id']])->fetch();
        if (!$row || !str_starts_with($row['ftp_user'], $u['username'].'_')) return null;
        return $row;
    }
    public function delete(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/ftp'); }
        $u = Auth::user();
        $row = $this->own($u, (int)($_POST['id'] ?? 0));
        if (!$row) { Helpers::flash('err','FTPS login not found'); Helpers::redirect('/ftp'); }
        Database::query("DELETE FROM ftp_accounts WHERE id=?", [$row['id']]);
        ServiceManager::run('ftp-remove.sh', [$row['ftp_user']]);
        Helpers::flash('ok',"FTPS login {$row['ftp_user']} removed"); Helpers::redirect('/ftp');
    }
    public function resetPass(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/ftp'); }
        $u = Auth::user();
        $row = $this->own($u, (int)($_POST['id'] ?? 0));
        if (!$row) { Helpers::flash('err','FTPS login not found'); Helpers::redirect('/ftp'); }
        $pass = bin2hex(random_bytes(10));
        $r = ServiceManager::run('ftp-passwd.sh', [$row['ftp_user'], new Secret($pass)]);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Reset failed: '.trim($r['out']));
        else Helpers::flash('ok',"New password for {$row['ftp_user']}: $pass (save it — shown only once)");
        Helpers::redirect('/ftp');
    }
}
