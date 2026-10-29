<?php
declare(strict_types=1);
class DatabaseController extends Controller {
    public function list(): void {
        Auth::requireLogin();
        $u = Auth::user();
        $p = Helpers::paginate((int)Database::query("SELECT COUNT(*) c FROM mysql_dbs WHERE user_id=?", [$u['id']])->fetch()['c'], max(1, (int)($_GET['page'] ?? 1)));
        $pg = $p['pg']; $pages = $p['pages']; $total = $p['total'];
        $dbs = Database::query("SELECT * FROM mysql_dbs WHERE user_id=? ORDER BY db_name LIMIT ? OFFSET ?", [$u['id'], (int)$p['limit'], (int)$p['offset']])->fetchAll();
        try {
            $gate = Database::query("SELECT pma_gate_hash FROM users WHERE id=?", [$u['id']])->fetch()['pma_gate_hash'] ?? null;
        } catch (Throwable $e) { $gate = null; }
        $caps = Auth::role() === 'admin' ? MysqlDb::caps() : null;
        $this->view('databases', compact('dbs','gate','pg','pages','total','caps'));
    }
    public function create(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/databases'); }
        $u = Auth::user();
        $name = preg_replace('/[^a-z0-9_]/','', strtolower(trim($_POST['name']??'')));
        // blank = generated (a blank field posts '', not null — never let it through)
        $pass = MysqlDb::password($_POST['password'] ?? null);
        if (strlen($name)<3) { Helpers::flash('err','Bad DB name'); Helpers::redirect('/databases'); }
        if ($pass === null) { Helpers::flash('err','DB password must be 8+ characters (or blank for a random one)'); Helpers::redirect('/databases'); }
        if ($err = Helpers::limitError($u, 'dbs')) { Helpers::flash('err', $err); Helpers::redirect('/databases'); }
        if ($err = Helpers::diskBlocked($u)) { Helpers::flash('err', $err); Helpers::redirect('/databases'); }
        try {
            [$dbname, $dbuser] = MysqlDb::create($u, $name, $pass);
            Helpers::flash('ok',"Database $dbname / user $dbuser created. Password: $pass (save it!)");
        } catch (Throwable $e) { Helpers::flash('err','DB failed: '.$e->getMessage()); }
        Helpers::redirect('/databases');
    }
    // Per-database-user MariaDB caps (admin): new users get them at once;
    // "apply" also re-grants every existing panel database user.
    public function caps(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/databases'); }
        $caps = MysqlDb::parseCaps($_POST);
        if ($caps === null) { Helpers::flash('err','Each limit must be a whole number from 0 to 999999999 (0 = no limit)'); Helpers::redirect('/databases'); }
        try {
            foreach ($caps as $k => $v) Database::query("INSERT INTO settings(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [$k, (string)$v]);
            $msg = 'Database limits saved: '.MysqlDb::capsClause($caps).' (new database users)';
            if (!empty($_POST['apply'])) {
                [$ok, $bad] = MysqlDb::applyCapsToAll($caps);
                $msg .= " · applied to $ok existing user(s)".($bad ? ", $bad failed" : '');
            }
            Helpers::flash('ok', $msg);
        } catch (Throwable $e) { Helpers::flash('err','Save failed: '.$e->getMessage()); }
        Helpers::redirect('/databases');
    }
    // Passwords are shown once and never stored — this issues a replacement.
    public function reset(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/databases'); }
        $u = Auth::user();
        $row = Database::query("SELECT * FROM mysql_dbs WHERE id=? AND user_id=?", [(int)($_POST['id']??0), $u['id']])->fetch();
        if (!$row) { Helpers::flash('err','Database not found'); Helpers::redirect('/databases'); }
        try {
            $pass = MysqlDb::resetPassword($row);
            Helpers::flash('ok',"New password for {$row['db_user']}: $pass (save it — shown only once)");
        } catch (Throwable $e) { Helpers::flash('err','Reset failed: '.$e->getMessage()); }
        Helpers::redirect('/databases');
    }
    // Delete drops the real MariaDB database + user, then the panel row.
    public function delete(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/databases'); }
        $u = Auth::user();
        $row = Database::query("SELECT * FROM mysql_dbs WHERE id=? AND user_id=?", [(int)($_POST['id']??0), $u['id']])->fetch();
        if (!$row) { Helpers::flash('err','Database not found'); Helpers::redirect('/databases'); }
        if (!MysqlDb::validRow($row)) { Helpers::flash('err','Bad stored name or system database refused'); Helpers::redirect('/databases'); }
        try {
            MysqlDb::drop($row);
            Helpers::flash('ok',"Database {$row['db_name']} deleted");
        } catch (Throwable $e) { Helpers::flash('err','Delete failed: '.$e->getMessage()); }
        Helpers::redirect('/databases');
    }
    // phpMyAdmin gate password: panel hashes (SHA256-crypt, htpasswd-compatible),
    // only the hash reaches the sudo script — plaintext never hits argv/ps.
    public function gate(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/databases'); }
        $u = Auth::user();
        $pass = (string)($_POST['gate_password'] ?? '');
        if ($pass !== '' && strlen($pass) < 10) { Helpers::flash('err','Gate password must be 10+ characters (or empty to keep)'); Helpers::redirect('/databases'); }
        try {
            if ($pass === '') {
                Database::query("UPDATE users SET pma_gate_hash=NULL WHERE id=?", [$u['id']]);
                ServiceManager::run('pma-gate.sh', [$u['username'], '--revoke']);
                Helpers::flash('ok','phpMyAdmin gate removed — login screen is public again');
            } else {
                $salt = substr(strtr(base64_encode(random_bytes(12)), '+', '.'), 0, 16);
                $hash = crypt($pass, '$5$rounds=5000$'.$salt.'$');
                if (!preg_match('/^\$5\$rounds=5000\$[a-zA-Z0-9.\/]{1,16}\$[a-zA-Z0-9.\/]{43}$/', $hash)) {
                    throw new RuntimeException('hash generation failed');
                }
                Database::query("UPDATE users SET pma_gate_hash=? WHERE id=?", [$hash, $u['id']]);
                $r = ServiceManager::run('pma-gate.sh', [$u['username'], new Secret($hash)]);
                if (($r['code'] ?? 1) !== 0) throw new RuntimeException(trim($r['out']));
                Helpers::flash('ok','phpMyAdmin gate password set — it now asks for it before the login screen');
            }
        } catch (Throwable $e) { Helpers::flash('err','Gate failed: '.$e->getMessage()); }
        Helpers::redirect('/databases');
    }
}
