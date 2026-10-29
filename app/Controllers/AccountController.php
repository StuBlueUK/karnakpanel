<?php
declare(strict_types=1);
class AccountController extends Controller {
    public function list(): void {
        Auth::requireRole(['admin','reseller']);
        $me = Auth::user();
        $owner = ($me['role'] === 'admin') ? null : (int)$me['id'];
        $q = substr(trim((string)($_GET['q'] ?? '')), 0, 64);
        $total = ($q !== '') ? User::countSearch($owner, $q) : User::countAll($owner);
        $p = Helpers::paginate($total, max(1, (int)($_GET['page'] ?? 1)));
        $pg = $p['pg']; $pages = $p['pages']; $total = $p['total'];
        $users = ($q !== '') ? User::searchPaged($owner, $q, $p['limit'], $p['offset']) : User::allPaged($owner, $p['limit'], $p['offset']);
        $packages = Package::visibleTo($me);
        // usage per row (du per home + SQL sums — one page max, admin tool)
        foreach ($users as &$x) {
            try { $x['use'] = Helpers::quota($x); } catch (Throwable $e) { $x['use'] = null; }
            try { $x['bwmonth'] = Helpers::bandwidthMonthBytes($x); } catch (Throwable $e) { $x['bwmonth'] = null; }
        }
        unset($x);
        $this->view('accounts', compact('users','packages','me','pg','pages','total','q'));
    }
    public function create(): void {
        Auth::requireRole(['admin','reseller']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/accounts'); }
        $me = Auth::user();
        $username = strtolower(trim($_POST['username']??''));
        $email = trim($_POST['email']??'');
        $password = (string)($_POST['password']??'');
        $role = $_POST['role']??'user';
        if (!in_array($role, ['user','reseller','admin'], true)) $role = 'user';
        if ($me['role']==='reseller' && $role!=='user') { Helpers::flash('err','Resellers can only create user accounts'); Helpers::redirect('/accounts'); }
        if ($role === 'admin' && $me['role'] !== 'admin') $role = 'user'; // belt: forged POST from non-admin
        if (!Helpers::validNewUsername($username) || strlen($password)<8 || !Helpers::validEmail($email)) {
            Helpers::flash('err','Invalid username/email/password (user a-z0-9_, pass 8+ chars)'); Helpers::redirect('/accounts');
        }
        $pwned = Helpers::passwordPwned($password);
        if ($pwned !== null && $pwned > 0) { Helpers::flash('err',"That password appears in $pwned known breach(es) — choose an unleaked one"); Helpers::redirect('/accounts'); }
        try {
            $pkg = !empty($_POST['package_id']) ? Package::findVisible((int)$_POST['package_id'], $me) : null;
            if (!empty($_POST['package_id']) && !$pkg) { Helpers::flash('err','Plan not found'); Helpers::redirect('/accounts'); }
            if ($me['role'] === 'reseller' && $pkg && ($aerr = Helpers::allocationError($me, $pkg))) {
                Helpers::flash('err', $aerr); Helpers::redirect('/accounts');
            }
            [$id, $out] = User::provision([
                'username'=>$username,'email'=>$email,'password'=>$password,'role'=>$role,
                'owner_id'=>$me['id'],'package_id'=>$pkg['id']??null,
                'disk_quota_mb'=>$pkg['disk_quota_mb']??5120,
                'bandwidth_quota_gb'=>$pkg['bandwidth_quota_gb']??100,
            ], PhpVersions::orDefault($pkg['php_version'] ?? null), max(2, min(50, (int)($pkg['max_children'] ?? 10))));
            Helpers::log("account $username created id=$id role=$role by {$me['username']}");
            Helpers::flash('ok',"Account $username created (id $id). ".$out);
        } catch (DomainException $e) {
            Helpers::flash('err','Create failed: '.$e->getMessage());
        } catch (Throwable $e) {
            Helpers::log("new account $username failed: ".$e->getMessage());
            Helpers::flash('err', $e instanceof PDOException ? 'Create failed: database error (see the panel log)' : 'Create failed: '.$e->getMessage());
        }
        Helpers::redirect('/accounts');
    }
    // Change an account's email (the SSL page's ACME contact comes from here).
    // Scope mirrors suspend(): admins any row, resellers only their own users.
    public function email(): void {
        Auth::requireRole(['admin','reseller']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/accounts'); }
        $me = Auth::user();
        $t = Database::query("SELECT * FROM users WHERE id=?", [(int)($_POST['id'] ?? 0)])->fetch();
        if (!$t || ($me['role'] === 'reseller' && (int)$t['owner_id'] !== (int)$me['id'])) {
            Helpers::flash('err','Account not found'); Helpers::redirect('/accounts');
        }
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        // same bar as Issue-SSL: real routable address only (ACME rejects localhost)
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/@[^@]+\.[a-z]{2,}$/i', $email) || str_ends_with($email, '@localhost')) {
            Helpers::flash('err','Use a real email address (SSL/ACME rejects localhost)'); Helpers::redirect('/accounts');
        }
        Database::query("UPDATE users SET email=? WHERE id=?", [$email, $t['id']]);
        Helpers::log("account {$t['username']} email changed to $email by {$me['username']}");
        Helpers::flash('ok',"Email for {$t['username']} updated");
        Helpers::redirect('/accounts');
    }
    // Change an account's role (admin only — resellers keep create-user-only).
    // Audit notes: every authz check reads the session role with one
    // semantics, and API tokens read the DB row live, so a role change is
    // exactly: DB update + kill live sessions (else the old role survives to
    // re-login) + guards. Reseller->user with live clients is refused
    // (owner-scoped lists would strand them); last-admin and self are refused.
    public function changeRole(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/accounts'); }
        $me = Auth::user();
        $t = Database::query("SELECT * FROM users WHERE id=?", [(int)($_POST['id'] ?? 0)])->fetch();
        if (!$t) { Helpers::flash('err','Account not found'); Helpers::redirect('/accounts'); }
        $role = (string)($_POST['role'] ?? '');
        if (!in_array($role, ['user','reseller','admin'], true)) { Helpers::flash('err','Bad role'); Helpers::redirect('/accounts'); }
        if ($role === $t['role']) { Helpers::flash('err',"{$t['username']} is already {$role}"); Helpers::redirect('/accounts'); }
        if ((int)$t['id'] === (int)$me['id']) { Helpers::flash('err','You cannot change your own role — ask another admin'); Helpers::redirect('/accounts'); }
        if ($t['role'] === 'admin' && $role !== 'admin') {
            $others = (int)Database::query("SELECT COUNT(*) c FROM users WHERE role='admin' AND status='active' AND id!=?", [$t['id']])->fetch()['c'];
            if ($others < 1) { Helpers::flash('err','Refusing: the last active admin cannot be demoted'); Helpers::redirect('/accounts'); }
        }
        if ($t['role'] === 'reseller' && $role !== 'reseller') {
            $clients = (int)Database::query("SELECT COUNT(*) c FROM users WHERE owner_id=?", [$t['id']])->fetch()['c'];
            // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string -- not SQL: flash text, no query sink
            if ($clients > 0) { Helpers::flash('err',"Refusing: {$t['username']} still owns $clients account(s) — move or delete them first"); Helpers::redirect('/accounts'); }
        }
        Database::query("UPDATE users SET role=? WHERE id=?", [$role, $t['id']]);
        // live sessions snapshot the role at login — kill them all so the old
        // role dies now, not at next login
        $killed = self::killSessions($t['username']);
        Helpers::log("account {$t['username']} role {$t['role']} -> $role by {$me['username']} ($killed sessions killed)");
        Helpers::notify((int)$t['id'], 'account', "Your role was changed to $role by {$me['username']}");
        Helpers::flash('ok',"{$t['username']} is now $role ($killed live session(s) ended — they log in again with new powers)");
        Helpers::redirect('/accounts');
    }
    // Admin/reseller password reset for another account: new random password
    // shown ONCE (same pattern as FTP/mailbox Reset PW). Sets the panel login
    // AND the system (FTP/SFTP) password, and ends the account's live sessions
    // — a reset usually means the old password is lost or leaked. Your own
    // password stays on /password (asks for the current one). Scope mirrors
    // suspend(): admins any row, resellers only their own users.
    public function resetPassword(): void {
        Auth::requireRole(['admin','reseller']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/accounts'); }
        $me = Auth::user();
        $t = Database::query("SELECT * FROM users WHERE id=?", [(int)($_POST['id'] ?? 0)])->fetch();
        if (!$t || ($me['role'] === 'reseller' && (int)$t['owner_id'] !== (int)$me['id'])) {
            Helpers::flash('err','Account not found'); Helpers::redirect('/accounts');
        }
        if ((int)$t['id'] === (int)$me['id']) { Helpers::flash('err','Change your own password on the Password page'); Helpers::redirect('/password'); }
        $new = bin2hex(random_bytes(10));
        Database::query("UPDATE users SET password_hash=? WHERE id=?", [password_hash($new, PASSWORD_BCRYPT), $t['id']]);
        $r = ServiceManager::run('password-sync.sh', [$t['username'], new Secret($new)]);
        $killed = self::killSessions($t['username']);
        Helpers::log("account {$t['username']} password reset by {$me['username']} ($killed sessions killed)");
        Helpers::notify((int)$t['id'], 'security', "Your password was reset by {$me['username']}");
        if (($r['code'] ?? 1) !== 0) {
            Helpers::flash('err',"Panel password for {$t['username']} is now: $new (save it — shown only once). System/FTP password sync failed: ".trim((string)$r['out']));
        } else {
            Helpers::flash('ok',"New password for {$t['username']}: $new (save it — shown only once; $killed live session(s) ended)");
        }
        Helpers::redirect('/accounts');
    }
    // Lost-authenticator recovery: switch another account's 2FA off so they
    // log in with their password and set it up again (enforcement, if on,
    // herds admins/resellers straight back to /2fa). Ends live sessions —
    // a lost device may be a stolen one. Scope mirrors suspend().
    public function resetTwoFactor(): void {
        Auth::requireRole(['admin','reseller']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/accounts'); }
        $me = Auth::user();
        $t = Database::query("SELECT * FROM users WHERE id=?", [(int)($_POST['id'] ?? 0)])->fetch();
        if (!$t || ($me['role'] === 'reseller' && (int)$t['owner_id'] !== (int)$me['id'])) {
            Helpers::flash('err','Account not found'); Helpers::redirect('/accounts');
        }
        if ((int)$t['id'] === (int)$me['id']) { Helpers::flash('err','Manage your own 2FA on the 2FA page'); Helpers::redirect('/2fa'); }
        if (empty($t['totp_enabled'])) { Helpers::flash('err',"{$t['username']} does not have 2FA on"); Helpers::redirect('/accounts'); }
        Database::query("UPDATE users SET totp_secret=NULL, totp_enabled=0, totp_last_step=NULL WHERE id=?", [$t['id']]);
        $killed = self::killSessions($t['username']);
        Helpers::log("2FA reset for {$t['username']} by {$me['username']} ($killed sessions killed)");
        Helpers::notify((int)$t['id'], 'security', "Your two-factor authentication was reset by {$me['username']} — set it up again on the 2FA page");
        Helpers::flash('ok',"2FA reset for {$t['username']} — they log in with their password and set it up again ($killed live session(s) ended)");
        Helpers::redirect('/accounts');
    }
    // end every live panel session of $username (session-list/kill tools)
    private static function killSessions(string $username): int {
        $killed = 0;
        try {
            $sess = ServiceManager::run('session-list.sh', []);
            foreach (explode("\n", trim((string)($sess['out'] ?? ''))) as $line) {
                $p = explode("\t", $line);
                if (count($p) >= 2 && $p[1] === $username) {
                    $k = ServiceManager::run('session-kill.sh', [$p[0]]);
                    if (($k['code'] ?? 1) === 0) $killed++;
                }
            }
        } catch (Throwable $e) {}
        return $killed;
    }
    public function suspend(): void {        Auth::requireRole(['admin','reseller']);
        $me = Auth::user();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/accounts'); }
        $t = Database::query("SELECT * FROM users WHERE username=?", [$_POST['username']??''])->fetch();
        if (!$t || ($me['role'] === 'reseller' && (int)$t['owner_id'] !== (int)$me['id'])) {
            Helpers::flash('err','Account not found'); Helpers::redirect('/accounts');
        }
        $act = (($_POST['action'] ?? '') === 'unsuspend') ? 'unsuspended' : 'suspended';
        if ($act === 'suspended') {
            // lockout guards: no self-suspend, and never suspend the last
            // active admin (recovery would need root SSH + SQL)
            if ((int)$t['id'] === (int)$me['id']) { Helpers::flash('err','You cannot suspend yourself — ask another admin'); Helpers::redirect('/accounts'); }
            if ($t['role'] === 'admin') {
                $others = (int)Database::query("SELECT COUNT(*) c FROM users WHERE role='admin' AND status='active' AND id!=?", [$t['id']])->fetch()['c'];
                if ($others < 1) { Helpers::flash('err','Refusing: the last active admin cannot be suspended'); Helpers::redirect('/accounts'); }
            }
        }
        ServiceManager::run('suspend-account.sh', [$t['username'], $_POST['action']??'suspend']);
        // manual action wins over automation: clear the auto-suspend marker either way
        Database::query("UPDATE users SET status=?, bw_suspended=0 WHERE id=?", [$act === 'unsuspended' ? 'active' : 'suspended', $t['id']]);
        Helpers::flash('ok',"Account {$t['username']} updated");
        Helpers::notify((int)$t['id'], 'account', "Your account was $act by {$me['username']}");
        Helpers::redirect('/accounts');
    }
    public function delete(): void {
        Auth::requireRole(['admin','reseller']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/accounts'); }
        $me = Auth::user();
        $t = Database::query("SELECT * FROM users WHERE id=?", [(int)($_POST['id'] ?? 0)])->fetch();
        if (!$t) { Helpers::flash('err','Account not found'); Helpers::redirect('/accounts'); }
        if ((int)$t['id'] === (int)$me['id']) { Helpers::flash('err','You cannot delete yourself'); Helpers::redirect('/accounts'); }
        if ($t['role'] === 'admin') { Helpers::flash('err','Admin accounts cannot be deleted here'); Helpers::redirect('/accounts'); }
        if ($me['role'] === 'reseller' && (int)$t['owner_id'] !== (int)$me['id']) {
            Helpers::flash('err','Account not found'); Helpers::redirect('/accounts');
        }
        if (!Helpers::validUsername($t['username'])) { Helpers::flash('err','Stored username invalid'); Helpers::redirect('/accounts'); }
        try {
            $uid = (int)$t['id'];
            $mails = Database::query("SELECT e.localpart, d.domain FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE e.user_id=?", [$uid])->fetchAll();
            $domIds = Database::query("SELECT id, domain FROM domains WHERE user_id=?", [$uid])->fetchAll();
            // privileged teardown first: mail aliases, real MySQL objects, then everything system
            foreach ($mails as $m) ServiceManager::run('email-remove.sh', [$m['localpart'].'@'.$m['domain']]);
            MysqlDb::dropAllFor($uid);
            // parked aliases first: delete-account only walks /home/<user>/<domain> zones
            foreach ($domIds as $row) {
                $dn = Database::query("SELECT domain FROM domains WHERE id=?", [$row['id']])->fetchColumn();
                foreach (Database::query("SELECT alias FROM domain_aliases WHERE domain_id=?", [$row['id']])->fetchAll() as $a) {
                    ServiceManager::run('vhost-unalias.sh', [(string)$dn, $a['alias']]);
                }
            }
            $del = ServiceManager::run('delete-account.sh', [$t['username']]);
            if (($del['code'] ?? 1) !== 0) throw new RuntimeException(trim($del['out']));
            // panel rows (backup files on disk are kept for safety)
            Database::query("DELETE FROM cron_jobs WHERE user_id=?", [$uid]);
            Database::query("DELETE FROM email_accounts WHERE user_id=?", [$uid]);
            Database::query("DELETE FROM mail_imports WHERE user_id=?", [$uid]);
            Database::query("DELETE FROM ftp_accounts WHERE user_id=?", [$uid]);
            Database::query("DELETE FROM mysql_dbs WHERE user_id=?", [$uid]);
            Database::query("DELETE FROM api_tokens WHERE user_id=?", [$uid]);
            Database::query("DELETE FROM backups WHERE user_id=?", [$uid]);
            foreach ($domIds as $row) Database::query("DELETE FROM dns_records WHERE domain_id=?", [$row['id']]);
            foreach ($domIds as $row) MailAutopilot::purgeDomain((int)$row['id']);
            foreach ($domIds as $row) DomainModel::purgeRows((int)$row['id']);
            ServiceManager::run('email-filters-sync.sh', []);
            ServiceManager::run('list-sync.sh', []);
            Database::query("DELETE FROM domains WHERE user_id=?", [$uid]);
            // LE certs outlive delete-account.sh — drop them (no orphan renewals)
            foreach ($domIds as $row) ServiceManager::run('ssl-remove.sh', [(string)$row['domain']]);
            Database::query("DELETE FROM packages WHERE reseller_id=?", [$uid]);
            Database::query("UPDATE users SET owner_id=NULL WHERE owner_id=?", [$uid]);
            Database::query("DELETE FROM users WHERE id=?", [$uid]);
            Helpers::flash('ok',"Account {$t['username']} deleted (system, sites, DNS, mailboxes, databases). Backup files kept.");
            Helpers::notify(null, 'account', "Account {$t['username']} deleted by {$me['username']}");
        } catch (Throwable $e) { Helpers::flash('err','Delete failed: '.$e->getMessage()); }
        Helpers::redirect('/accounts');
    }
    // cPanel full-backup import v1 (admin only): files + DBs + mailboxes into
    // an EXISTING panel user + domain. Runs detached (see cpmove-import.sh for
    // the v1 scope: DNS/subdomains/cron are report-only, non-WP DB passwords
    // come back random and must go into app configs).
    public function import(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/accounts'); }
        $f = $_FILES['pkg'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== 0 || !str_ends_with(strtolower($f['name'] ?? ''), '.tar.gz')) {
            Helpers::flash('err','Upload a cPanel backup (.tar.gz)'); Helpers::redirect('/accounts');
        }
        $cpuser = strtolower(trim((string)($_POST['cpuser'] ?? '')));
        $username = strtolower(trim((string)($_POST['username'] ?? '')));
        $domain = strtolower(trim((string)($_POST['domain'] ?? '')));
        if (!preg_match('/^[a-z][a-z0-9_]{1,15}$/', $cpuser)) { Helpers::flash('err','Bad cPanel username'); Helpers::redirect('/accounts'); }
        if (!Helpers::validUsername($username) || !Helpers::validDomain($domain)) { Helpers::flash('err','Bad target user/domain'); Helpers::redirect('/accounts'); }
        $t = Database::query("SELECT * FROM users WHERE username=?", [$username])->fetch();
        $d = $t ? Database::query("SELECT * FROM domains WHERE domain=? AND user_id=?", [$domain, $t['id']])->fetch() : null;
        if (!$t || !$d) { Helpers::flash('err','Target user/domain must already exist (add the domain first)'); Helpers::redirect('/accounts'); }
        $stage = '/usr/local/skypanel/panel/storage/tmp-uploads';
        $real = realpath('/usr/local/skypanel/panel/storage/tmp-uploads') ?: $stage;
        @mkdir($real, 0700, true);
        $dest = $real.'/cpmove-'.bin2hex(random_bytes(8)).'.tar.gz';
        if (!@move_uploaded_file($f['tmp_name'], $dest)) { Helpers::flash('err','Staging failed'); Helpers::redirect('/accounts'); }
        $r = ServiceManager::run('cpmove-import.sh', [$dest, $cpuser, $username, $domain]);
        Helpers::log("cpmove import started for $domain by ".Auth::user()['username']);
        Helpers::flash('ok', trim($r['out'])." — watch progress in Logs → software");
        Helpers::redirect('/admin/logs?log=software');
    }
    // Remote cPanel imports (admin only): fetch the account from its source
    // server, then run it through the standard cpmove importer. Secrets
    // travel via a 600 file (never argv — the runner logs full commands);
    // the script shreds it after reading. Progress: cpmove-<job>.progress.
    private function remoteTarget(string $username, string $domain): ?array {
        $t = Database::query("SELECT * FROM users WHERE username=?", [$username])->fetch();
        $d = $t ? Database::query("SELECT * FROM domains WHERE domain=? AND user_id=?", [$domain, $t['id']])->fetch() : null;
        if (!$t || !$d) return null;
        return [$t, $d];
    }
    private function remoteSecret(string $job, string $secret): ?string {
        $stage = realpath('/usr/local/skypanel/panel/storage/tmp-uploads') ?: '/usr/local/skypanel/panel/storage/tmp-uploads';
        if (!is_dir($stage)) return null;
        $path = $stage.'/cpsecret-'.$job;
        if (@file_put_contents($path, $secret) === false) return null;
        @chmod($path, 0600);
        return $path;
    }
    public function importRemote(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/migrate'); }
        $host = strtolower(trim((string)($_POST['host'] ?? '')));
        $cpuser = strtolower(trim((string)($_POST['cpuser'] ?? '')));
        $cppass = (string)($_POST['cppass'] ?? '');
        // API token (Security → Manage API Tokens on the source): required on
        // modern cPanel, whose UAPI refuses password auth (web login unaffected)
        $ctoken = preg_replace('/[^a-zA-Z0-9]/', '', (string)($_POST['cptoken'] ?? ''));
        $username = strtolower(trim((string)($_POST['username'] ?? '')));
        $domain = strtolower(trim((string)($_POST['domain'] ?? '')));
        if (!preg_match('/^([a-z0-9.-]+\.[a-z]{2,}|[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)$/', $host)) { Helpers::flash('err','Bad source host'); Helpers::redirect('/admin/migrate'); }
        if (!preg_match('/^[a-z][a-z0-9_]{1,15}$/', $cpuser)) { Helpers::flash('err','Bad cPanel username'); Helpers::redirect('/admin/migrate'); }
        if (strlen($cppass) < 1 || strlen($cppass) > 256) { Helpers::flash('err','Source password required (FTP pull)'); Helpers::redirect('/admin/migrate'); }
        if (!Helpers::validUsername($username) || !Helpers::validDomain($domain)) { Helpers::flash('err','Bad target user/domain'); Helpers::redirect('/admin/migrate'); }
        if (!$this->remoteTarget($username, $domain)) { Helpers::flash('err','Target user/domain must already exist (add the domain first)'); Helpers::redirect('/admin/migrate'); }
        $job = bin2hex(random_bytes(8));
        $sec = $this->remoteSecret($job, $cppass."\n".$ctoken);
        if (!$sec) { Helpers::flash('err','Staging failed'); Helpers::redirect('/admin/migrate'); }
        $r = ServiceManager::run('cpremote-fetch.sh', [$job, $host, $cpuser, $sec, $username, $domain]);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Fetch refused: '.trim($r['out']));
        else Helpers::flash('ok', trim($r['out']));
        Helpers::redirect('/admin/migrate?job='.$job);
    }
    public function importRoot(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/migrate'); }
        $host = strtolower(trim((string)($_POST['host'] ?? '')));
        $ruser = strtolower(trim((string)($_POST['rootuser'] ?? 'root')));
        $rpass = (string)($_POST['rootpass'] ?? '');
        $cpuser = strtolower(trim((string)($_POST['cpuser'] ?? '')));
        $username = strtolower(trim((string)($_POST['username'] ?? '')));
        $domain = strtolower(trim((string)($_POST['domain'] ?? '')));
        if (!preg_match('/^([a-z0-9.-]+\.[a-z]{2,}|[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)$/', $host)) { Helpers::flash('err','Bad source host'); Helpers::redirect('/admin/migrate'); }
        if (!preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $ruser)) { Helpers::flash('err','Bad root username'); Helpers::redirect('/admin/migrate'); }
        if (strlen($rpass) < 1 || strlen($rpass) > 256) { Helpers::flash('err','Root password required'); Helpers::redirect('/admin/migrate'); }
        if (!preg_match('/^[a-z][a-z0-9_]{1,15}$/', $cpuser)) { Helpers::flash('err','Bad cPanel username'); Helpers::redirect('/admin/migrate'); }
        if (!Helpers::validUsername($username) || !Helpers::validDomain($domain)) { Helpers::flash('err','Bad target user/domain'); Helpers::redirect('/admin/migrate'); }
        if (!$this->remoteTarget($username, $domain)) { Helpers::flash('err','Target user/domain must already exist (add the domain first)'); Helpers::redirect('/admin/migrate'); }
        $job = bin2hex(random_bytes(8));
        $sec = $this->remoteSecret($job, $rpass);
        if (!$sec) { Helpers::flash('err','Staging failed'); Helpers::redirect('/admin/migrate'); }
        $r = ServiceManager::run('cproot-fetch.sh', [$job, $host, $ruser, $sec, $cpuser, $username, $domain]);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Fetch refused: '.trim($r['out']));
        else Helpers::flash('ok', trim($r['out']));
        Helpers::redirect('/admin/migrate?job='.$job);
    }
    // HestiaCP (admin only): everything one Hestia user owns — all its web
    // and mail domains get created under an EXISTING panel user (no domain
    // field). SSH pull (v-backup-user) or an uploaded backup .tar.
    private function hestiaTarget(string $huser, string $username): ?string {
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,30}[a-z0-9]$/', $huser)) return 'Bad Hestia username';
        if (!Helpers::validUsername($username)) return 'Bad target user';
        if (!Database::query("SELECT id FROM users WHERE username=?", [$username])->fetch()) return 'Target panel user must already exist (create the account first)';
        return null;
    }
    public function importHestia(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/migrate'); }
        $host = strtolower(trim((string)($_POST['host'] ?? '')));
        $port = (int)($_POST['port'] ?? 22);
        $ruser = strtolower(trim((string)($_POST['rootuser'] ?? 'root')));
        $rpass = (string)($_POST['rootpass'] ?? '');
        $huser = strtolower(trim((string)($_POST['huser'] ?? '')));
        $username = strtolower(trim((string)($_POST['username'] ?? '')));
        if (!preg_match('/^([a-z0-9.-]+\.[a-z]{2,}|[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)$/', $host)) { Helpers::flash('err','Bad source host'); Helpers::redirect('/admin/migrate'); }
        if ($port < 1 || $port > 65535) { Helpers::flash('err','Bad SSH port'); Helpers::redirect('/admin/migrate'); }
        if (!preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $ruser)) { Helpers::flash('err','Bad SSH username'); Helpers::redirect('/admin/migrate'); }
        if (strlen($rpass) < 1 || strlen($rpass) > 256) { Helpers::flash('err','SSH password required'); Helpers::redirect('/admin/migrate'); }
        if ($err = $this->hestiaTarget($huser, $username)) { Helpers::flash('err', $err); Helpers::redirect('/admin/migrate'); }
        $job = bin2hex(random_bytes(8));
        $sec = $this->remoteSecret($job, $rpass);
        if (!$sec) { Helpers::flash('err','Staging failed'); Helpers::redirect('/admin/migrate'); }
        $r = ServiceManager::run('hestia-fetch.sh', [$job, $host, (string)$port, $ruser, $sec, $huser, $username]);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Fetch refused: '.trim($r['out']));
        else Helpers::flash('ok', trim($r['out']));
        Helpers::log("hestia import ($huser@$host -> $username) started by ".Auth::user()['username']);
        Helpers::redirect('/admin/migrate?job='.$job);
    }
    public function importHestiaUpload(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/migrate'); }
        $f = $_FILES['pkg'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== 0 || !str_ends_with(strtolower($f['name'] ?? ''), '.tar')) {
            Helpers::flash('err','Upload a HestiaCP backup (<user>.<date>.tar)'); Helpers::redirect('/admin/migrate');
        }
        $huser = strtolower(trim((string)($_POST['huser'] ?? '')));
        $username = strtolower(trim((string)($_POST['username'] ?? '')));
        if ($err = $this->hestiaTarget($huser, $username)) { Helpers::flash('err', $err); Helpers::redirect('/admin/migrate'); }
        $real = realpath('/usr/local/skypanel/panel/storage/tmp-uploads') ?: '/usr/local/skypanel/panel/storage/tmp-uploads';
        @mkdir($real, 0700, true);
        $job = bin2hex(random_bytes(8));
        $dest = $real.'/hestia-'.$job.'.tar';
        if (!@move_uploaded_file($f['tmp_name'], $dest)) { Helpers::flash('err','Staging failed'); Helpers::redirect('/admin/migrate'); }
        $r = ServiceManager::run('hestia-import.sh', [$dest, $huser, $username, $job]);
        if (($r['code'] ?? 1) !== 0) { @unlink($dest); Helpers::flash('err','Import refused: '.trim($r['out'])); }
        else Helpers::flash('ok', trim($r['out']));
        Helpers::log("hestia upload import ($huser -> $username) started by ".Auth::user()['username']);
        Helpers::redirect('/admin/migrate?job='.$job);
    }
    public function migrateStatus(): void {
        Auth::requireRole(['admin']);
        header('Content-Type: application/json');
        $job = (string)($_GET['job'] ?? '');
        if (!preg_match('/^[a-f0-9]{16}$/', $job)) { http_response_code(400); echo json_encode(['error' => 'bad job'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); exit; }
        try { Database::query("DELETE FROM import_jobs WHERE updated_at < (NOW() - INTERVAL 7 DAY)"); } catch (Throwable $e) {}
        try {
            $r = Database::query("SELECT stage,pct,detail FROM import_jobs WHERE job=?", [$job])->fetch();
        } catch (Throwable $e) { $r = null; }
        // HEX flags (house style): detail carries remote-server text — keep
        // angle brackets inert even if a client ever sniffs this as HTML
        if (!$r) { echo json_encode(['stage' => 'queued', 'pct' => 0, 'detail' => 'starting'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); exit; }
        // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag, php.lang.security.injection.echoed-request.echoed-request -- JSON_HEX_* flags keep all HTML-significant bytes inert (detail carries remote-server text by design; no HTML context)
        echo json_encode(['stage' => $r['stage'], 'pct' => (int)$r['pct'], 'detail' => $r['detail']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        exit;
    }
    public function impersonate(): void {        Auth::requireRole(['admin','reseller']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/accounts'); }
        if (Auth::impersonate((int)($_POST['id'] ?? 0))) Helpers::redirect('/');
        Helpers::flash('err','Cannot switch to that account'); Helpers::redirect('/accounts');
    }
    public function impersonateReturn(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/'); }
        Auth::stopImpersonating();
        Helpers::redirect('/accounts');
    }
}
