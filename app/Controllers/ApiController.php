<?php
declare(strict_types=1);
class ApiController extends Controller {
    private function tokenAuth(): ?array {
        $h = $_SERVER['HTTP_X_API_KEY'] ?? '';
        if (!$h || !preg_match('/^[a-f0-9]{64}$/', $h)) return null;
        // tokens are stored sha256-hashed (a table leak alone grants nothing)
        $u = Database::query("SELECT u.*, t.allowed_ip, t.id AS token_id FROM api_tokens t JOIN users u ON u.id=t.user_id WHERE t.token=SHA2(?,256) AND u.status='active' AND (t.expires_at IS NULL OR t.expires_at > NOW())", [$h])->fetch();
        if (!$u) return null;
        // IP pin: a stolen token is useless off the pinned host (WHMCS IP or
        // same-box server IP). NULL/empty = any IP (previous behaviour).
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if (!empty($u['allowed_ip']) && ($u['allowed_ip'] !== $ip)) return null;
        // last-use audit (at most one write a minute per token; never fatal)
        try { Database::query("UPDATE api_tokens SET last_used_at=NOW(), last_used_ip=? WHERE id=? AND (last_used_at IS NULL OR last_used_at < NOW() - INTERVAL 1 MINUTE OR last_used_ip <> ?)", [$ip, (int)$u['token_id'], $ip]); } catch (Throwable $e) {}
        unset($u['password_hash'], $u['totp_secret']);
        return $u;
    }
    public function accounts(): void {
        $u = $this->tokenAuth();
        if (!$u || !in_array($u['role'],['admin','reseller'])) $this->json(['error'=>'unauthorized'],401);
        $users = ($u['role']==='admin') ? User::all() : User::all((int)$u['id']);
        $this->json($users);
    }
    // ---- provisioning API (WHMCS/billing): admin tokens only ----
    private function adminToken(): ?array {
        $u = $this->tokenAuth();
        return ($u && $u['role'] === 'admin') ? $u : null;
    }
    private function apiInput(): array {
        $in = $_POST;
        if (empty($in)) {
            $raw = (string)file_get_contents('php://input');
            $j = json_decode($raw, true);
            if (is_array($j)) $in = $j;
        }
        return $in;
    }
    public function provCreate(): void {
        $me = $this->adminToken();
        if (!$me) $this->json(['error' => 'unauthorized'], 401);
        $in = $this->apiInput();
        $username = strtolower(trim((string)($in['username'] ?? '')));
        $email = trim((string)($in['email'] ?? ''));
        $password = (string)($in['password'] ?? '');
        $pkgName = trim((string)($in['package'] ?? ''));
        if (!Helpers::validNewUsername($username) || strlen($password) < 8 || !Helpers::validEmail($email)) {
            $this->json(['error' => 'invalid username/email/password (user a-z0-9_, pass 8+ chars, real email)'], 422);
        }
        $pwned = Helpers::passwordPwned($password);
        if ($pwned !== null && $pwned > 0) $this->json(['error' => 'password appears in known breaches — choose an unleaked one'], 422);
        try {
            $pkg = $pkgName !== '' ? Package::findByName($pkgName) : null;
            if ($pkgName !== '' && !$pkg) $this->json(['error' => "unknown package '$pkgName'"], 422);
            [$id] = User::provision([
                'username' => $username, 'email' => $email, 'password' => $password, 'role' => 'user',
                'owner_id' => $me['id'], 'package_id' => $pkg['id'] ?? null,
                'disk_quota_mb' => $pkg['disk_quota_mb'] ?? 5120,
                'bandwidth_quota_gb' => $pkg['bandwidth_quota_gb'] ?? 100,
            ], PhpVersions::orDefault($pkg['php_version'] ?? null), max(2, min(50, (int)($pkg['max_children'] ?? 10))));
            Helpers::log("API account $username created id=$id by {$me['username']}");
            $this->json(['ok' => true, 'id' => $id, 'username' => $username]);
        } catch (DomainException $e) {
            $this->json(['error' => 'create failed: '.$e->getMessage()], 409);
        } catch (Throwable $e) {
            Helpers::log("API new account $username failed: ".$e->getMessage());
            $this->json(['error' => 'create failed: '.($e instanceof PDOException ? 'database error' : $e->getMessage())], 500);
        }
    }
    private function provTarget(string $username): array {
        $t = Database::query("SELECT * FROM users WHERE username=?", [$username])->fetch();
        if (!$t || !Helpers::validUsername($t['username']) || $t['role'] === 'admin') {
            $this->json(['error' => 'account not found'], 404);
        }
        return $t;
    }
    public function provSuspend(): void {
        $me = $this->adminToken();
        if (!$me) $this->json(['error' => 'unauthorized'], 401);
        $t = $this->provTarget(strtolower(trim((string)($this->apiInput()['username'] ?? ''))));
        ServiceManager::run('suspend-account.sh', [$t['username'], 'suspend']);
        Database::query("UPDATE users SET status='suspended', bw_suspended=0 WHERE id=?", [$t['id']]);
        Helpers::log("API account {$t['username']} suspended by {$me['username']}");
        Helpers::notify((int)$t['id'], 'account', "Your account was suspended by {$me['username']}");
        $this->json(['ok' => true, 'status' => 'suspended']);
    }
    public function provUnsuspend(): void {
        $me = $this->adminToken();
        if (!$me) $this->json(['error' => 'unauthorized'], 401);
        $t = $this->provTarget(strtolower(trim((string)($this->apiInput()['username'] ?? ''))));
        ServiceManager::run('suspend-account.sh', [$t['username'], 'unsuspend']);
        Database::query("UPDATE users SET status='active', bw_suspended=0 WHERE id=?", [$t['id']]);
        Helpers::log("API account {$t['username']} unsuspended by {$me['username']}");
        Helpers::notify((int)$t['id'], 'account', "Your account was unsuspended by {$me['username']}");
        $this->json(['ok' => true, 'status' => 'active']);
    }
    public function provTerminate(): void {
        $me = $this->adminToken();
        if (!$me) $this->json(['error' => 'unauthorized'], 401);
        $t = $this->provTarget(strtolower(trim((string)($this->apiInput()['username'] ?? ''))));
        try {
            // same teardown order as AccountController::delete (mail, MySQL, system, rows)
            $uid = (int)$t['id'];
            $mails = Database::query("SELECT e.localpart, d.domain FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE e.user_id=?", [$uid])->fetchAll();
            $domIds = Database::query("SELECT id, domain FROM domains WHERE user_id=?", [$uid])->fetchAll();
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
            foreach (['cron_jobs','email_accounts','ftp_accounts','mysql_dbs','api_tokens','backups'] as $tbl) {
                Database::query("DELETE FROM $tbl WHERE user_id=?", [$uid]);
            }
            foreach ($domIds as $row) Database::query("DELETE FROM dns_records WHERE domain_id=?", [$row['id']]);
            foreach ($domIds as $row) MailAutopilot::purgeDomain((int)$row['id']);
            foreach ($domIds as $row) DomainModel::purgeRows((int)$row['id']);
            ServiceManager::run('email-filters-sync.sh', []);
            ServiceManager::run('list-sync.sh', []);
            Database::query("DELETE FROM domains WHERE user_id=?", [$uid]);
            // LE certs outlive delete-account.sh — drop them (no orphan renewals)
            foreach ($domIds as $row) ServiceManager::run('ssl-remove.sh', [(string)$row['domain']]);
            Database::query("DELETE FROM users WHERE id=?", [$uid]);
            Helpers::log("API account {$t['username']} terminated by {$me['username']}");
            $this->json(['ok' => true, 'terminated' => $t['username']]);
        } catch (Throwable $e) { $this->json(['error' => 'terminate failed: '.$e->getMessage()], 500); }
    }
    public function provPassword(): void {
        $me = $this->adminToken();
        if (!$me) $this->json(['error' => 'unauthorized'], 401);
        $in = $this->apiInput();
        $t = $this->provTarget(strtolower(trim((string)($in['username'] ?? ''))));
        $new = (string)($in['password'] ?? '');
        if (strlen($new) < 10) $this->json(['error' => 'new password must be 10+ characters'], 422);
        $pwned = Helpers::passwordPwned($new);
        if ($pwned !== null && $pwned > 0) $this->json(['error' => 'password appears in known breaches — choose an unleaked one'], 422);
        Database::query("UPDATE users SET password_hash=? WHERE id=?", [password_hash($new, PASSWORD_BCRYPT), $t['id']]);
        ServiceManager::run('password-sync.sh', [$t['username'], new Secret($new)]);
        Helpers::log("API password changed for {$t['username']} by {$me['username']}");
        $this->json(['ok' => true]);
    }
    public function provUsage(): void {        $me = $this->adminToken();
        if (!$me) $this->json(['error' => 'unauthorized'], 401);
        $t = $this->provTarget(strtolower(trim((string)($_GET['username'] ?? ''))));
        try { $quota = Helpers::quota($t); } catch (Throwable $e) { $quota = null; }
        try { $bw = Helpers::bandwidthMonthBytes($t); } catch (Throwable $e) { $bw = null; }
        $this->json(['ok' => true, 'username' => $t['username'], 'status' => $t['status'],
            'disk_used_mb' => $quota['used_mb'] ?? null, 'disk_quota_mb' => $t['disk_quota_mb'],
            'bw_month_mb' => $bw === null ? null : round($bw / 1048576, 1),
            'bw_quota_gb' => $t['bandwidth_quota_gb']]);
    }
    public function provPackages(): void {
        $me = $this->adminToken();
        if (!$me) $this->json(['error' => 'unauthorized'], 401);
        $out = [];
        foreach (Package::all() as $p) {
            $out[] = ['name' => $p['name'], 'disk_quota_mb' => $p['disk_quota_mb'],
                'bandwidth_quota_gb' => $p['bandwidth_quota_gb'], 'max_domains' => $p['max_domains'],
                'max_emails' => $p['max_emails'], 'max_dbs' => $p['max_dbs'],
                'max_ftp' => $p['max_ftp'], 'max_builder_sites' => (int)($p['max_builder_sites'] ?? 0), 'php_version' => $p['php_version']];
        }
        $this->json(['ok' => true, 'packages' => $out]);
    }
    public function tokens(): void {
        Auth::requireRole(['admin','reseller']);
        $u = Auth::user();
        $p = Helpers::paginate((int)Database::query("SELECT COUNT(*) c FROM api_tokens WHERE user_id=?", [$u['id']])->fetch()['c'], max(1, (int)($_GET['page'] ?? 1)));
        $pg = $p['pg']; $pages = $p['pages']; $total = $p['total'];
        $tokens = Database::query("SELECT id,label,allowed_ip,created_at,expires_at,last_used_at,last_used_ip FROM api_tokens WHERE user_id=? ORDER BY id DESC LIMIT ? OFFSET ?", [$u['id'], (int)$p['limit'], (int)$p['offset']])->fetchAll();
        $this->view('api_tokens', compact('tokens','pg','pages','total'));
    }
    public function mint(): void {
        Auth::requireRole(['admin','reseller']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/api-tokens'); }
        $u = Auth::user();
        $label = substr(trim($_POST['label'] ?? 'panel'), 0, 80);
        $token = bin2hex(random_bytes(32));
        // optional lifetime: 0 = never (billing integrations), else N days
        $days = (int)($_POST['days'] ?? 0);
        if (!in_array($days, [0, 30, 90, 365], true)) $days = 0;
        Database::query("INSERT INTO api_tokens(user_id,label,token,expires_at) VALUES(?,?,SHA2(?,256),IF(?>0, NOW() + INTERVAL ? DAY, NULL))", [$u['id'], $label, $token, $days, $days]);
        Helpers::flash('ok', "Token minted ($label): $token — send as X-API-KEY header. Shown only once.".($days ? " Expires in $days days." : ''));
        Helpers::redirect('/api-tokens');
    }
    public function revoke(): void {        Auth::requireRole(['admin','reseller']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/api-tokens'); }
        $u = Auth::user();
        Database::query("DELETE FROM api_tokens WHERE id=? AND user_id=?", [(int)($_POST['id'] ?? 0), $u['id']]);
        Helpers::flash('ok','Token revoked');
        Helpers::redirect('/api-tokens');
    }
    public function pin(): void {
        Auth::requireRole(['admin','reseller']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/api-tokens'); }
        $u = Auth::user();
        $ip = trim((string)($_POST['ip'] ?? ''));
        if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP)) {
            Helpers::flash('err','Not a valid IP address'); Helpers::redirect('/api-tokens');
        }
        Database::query("UPDATE api_tokens SET allowed_ip=? WHERE id=? AND user_id=?", [$ip !== '' ? $ip : null, (int)($_POST['id'] ?? 0), $u['id']]);
        Helpers::flash('ok', $ip !== '' ? "Token pinned to $ip" : 'IP pin removed (any host)');
        Helpers::redirect('/api-tokens');
    }
    // ---- resource API: admin = any account, reseller = own subtree (direct
    // owner_id, same convention as ResellerController), user = self only ----
    private function resolveTarget(array $me, string $username): array {
        $username = strtolower(trim($username));
        if (!Helpers::validUsername($username)) $this->json(['error' => 'account not found'], 404);
        $t = Database::query("SELECT * FROM users WHERE username=?", [$username])->fetch();
        if (!$t) $this->json(['error' => 'account not found'], 404);
        $role = $me['role'] ?? '';
        if ($role === 'admin') return $t;
        if ($role === 'reseller' && ((int)$t['id'] === (int)$me['id'] || (int)($t['owner_id'] ?? 0) === (int)$me['id'])) return $t;
        if ($role === 'user' && (int)$t['id'] === (int)$me['id']) return $t;
        $this->json(['error' => 'account not found'], 404);
    }
    private function resolveDomain(array $t, string $domain): array {
        $domain = strtolower(trim($domain));
        if (!Helpers::validDomain($domain)) $this->json(['error' => 'invalid domain'], 422);
        $d = Database::query("SELECT * FROM domains WHERE domain=? AND user_id=?", [$domain, $t['id']])->fetch();
        if (!$d) $this->json(['error' => 'domain not found'], 404);
        return $d;
    }
    private function scopedUser(array $me): array {
        // GET callers pass selectors in the query string, POST callers in the
        // body — accept both (ownership is enforced after resolution anyway).
        $in = array_merge($this->apiInput(), $_GET);
        if (isset($in['username']) && trim((string)$in['username']) !== '') return $this->resolveTarget($me, (string)$in['username']);
        return $this->resolveTarget($me, $me['username']);
    }
    // ---- domains ----
    public function domList(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $t = $this->scopedUser($me);
        $rows = Database::query("SELECT id,domain,docroot,php_version,ssl_enabled,rewrite_rules,rewrite_src_hash FROM domains WHERE user_id=? ORDER BY domain", [$t['id']])->fetchAll();
        foreach ($rows as &$r) {
            [$r['has_rules'], $r['rules_stale']] = DomainController::rewriteFlags($r);
            unset($r['rewrite_rules'], $r['rewrite_src_hash']);
        }
        unset($r);
        $this->json(['ok' => true, 'username' => $t['username'], 'domains' => $rows]);
    }
    public function domCreate(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $in = $this->apiInput();
        $t = $this->resolveTarget($me, (string)($in['username'] ?? $me['username']));
        $domain = strtolower(trim((string)($in['domain'] ?? '')));
        if (!Helpers::validDomain($domain)) $this->json(['error' => 'invalid domain'], 422);
        // mirrors DomainController::create (limits, seeds, scripts)
        $eff = Database::query("SELECT php_version FROM domains WHERE user_id=? LIMIT 1", [$t['id']])->fetch()['php_version'] ?? null;
        $php = PhpVersions::orDefault(is_string($eff) ? $eff : null);
        if ($err = Helpers::limitError($t, 'domains')) $this->json(['error' => $err], 422);
        try {
            $docroot = "/home/{$t['username']}/".$domain."/public_html";
            Database::query("INSERT INTO domains(user_id,domain,docroot,php_version) VALUES(?,?,?,?)", [$t['id'],$domain,$docroot,$php]);
            $domId = (int)Database::conn()->lastInsertId();
            $ip = trim(ServiceManager::run('server-ip.sh', [])['out'] ?? '');
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $ip = '192.0.2.1';
            [$ns1, $ns2] = DnsController::effectiveNs((int)$t['id'], $domain);
            foreach (DnsController::defaultRows($domain, $ip, [$ns1, $ns2]) as $rec) {
                Database::query("INSERT INTO dns_records(domain_id,type,host,value,priority,ttl) VALUES(?,?,?,?,?,?)", [$domId,$rec[0],$rec[1],$rec[2],10,3600]);
            }
            ServiceManager::run('create-account.sh', ['--repair', $t['username'], $php]);
            // re-resolve with the seeded rows: a reseller brand zone (glue seeded
            // above) advertises its own ns1/ns2, and the file must agree
            [$ns1, $ns2] = DnsController::effectiveNs((int)$t['id'], $domain, $domId);
            ServiceManager::run('dns-add-zone.sh', [$domain, $ns1, $ns2]);
            ServiceManager::run('vhost-add.sh', [$domain, $t['username'], $php]);
            ServiceManager::run('mail-autoconfig.sh', [$domain, $t['username']]);
            ServiceManager::run('expiry-check.sh', [$domain]);
            // DKIM from day one, as the Domains page does (best-effort)
            if ($row = Database::query("SELECT * FROM domains WHERE id=?", [$domId])->fetch()) {
                try { MailDns::signDomain($row); } catch (Throwable $e) {}
            }
            Helpers::log("API domain $domain created for {$t['username']} by {$me['username']}");
            $this->json(['ok' => true, 'id' => $domId, 'domain' => $domain, 'docroot' => $docroot]);
        } catch (Throwable $e) { $this->json(['error' => 'create failed: '.$e->getMessage()], 500); }
    }
    public function domDelete(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $in = $this->apiInput();
        $t = $this->resolveTarget($me, (string)($in['username'] ?? $me['username']));
        $d = $this->resolveDomain($t, (string)($in['domain'] ?? ''));
        $owner = Database::query("SELECT username FROM users WHERE id=?", [$d['user_id']])->fetch()['username'] ?? '';
        if (!Helpers::validUsername($owner)) $this->json(['error' => 'owner account invalid'], 500);
        try {
            // mirrors DomainController::delete (mail, DNS, vhost, SSL, lists, rows)
            Database::query("DELETE FROM email_accounts WHERE domain_id=?", [$d['id']]);
            ServiceManager::run('autoreply-sync.sh', []);
            Database::query("DELETE FROM dns_records WHERE domain_id=?", [$d['id']]);
            MailDns::purgeChild($d);
            MailAutopilot::purgeDomain((int)$d['id']);
            Database::query("DELETE FROM domains WHERE id=?", [$d['id']]);
            ServiceManager::run('vhost-remove.sh', [$d['domain'], $owner]);
            ServiceManager::run('dns-remove-zone.sh', [$d['domain']]);
            ServiceManager::run('ssl-remove.sh', [$d['domain']]);
            $als = Database::query("SELECT alias FROM domain_aliases WHERE domain_id=?", [$d['id']])->fetchAll();
            foreach ($als as $a) ServiceManager::run('vhost-unalias.sh', [$d['domain'], $a['alias']]);
            DomainModel::purgeRows((int)$d['id']);
            ServiceManager::run('email-filters-sync.sh', []);
            ServiceManager::run('list-sync.sh', []);
            Helpers::log("API domain {$d['domain']} deleted by {$me['username']}");
            $this->json(['ok' => true, 'deleted' => $d['domain']]);
        } catch (Throwable $e) { $this->json(['error' => 'delete failed: '.$e->getMessage()], 500); }
    }
    // ---- DNS ----
    public function dnsList(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $in = array_merge($this->apiInput(), $_GET);
        $t = $this->resolveTarget($me, (string)($in['username'] ?? $me['username']));
        $d = $this->resolveDomain($t, (string)($in['domain'] ?? ''));
        $rows = Database::query("SELECT id,type,host,value,priority,ttl FROM dns_records WHERE domain_id=? ORDER BY host,type", [$d['id']])->fetchAll();
        $this->json(['ok' => true, 'domain' => $d['domain'], 'records' => $rows]);
    }
    public function dnsAdd(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $in = $this->apiInput();
        $t = $this->resolveTarget($me, (string)($in['username'] ?? $me['username']));
        $d = $this->resolveDomain($t, (string)($in['domain'] ?? ''));
        if ($pz = DnsController::parentZone($d)) $this->json(['error' => "{$d['domain']} has no zone of its own — its records live in the $pz zone"], 422);
        $dns = new DnsController();
        $type = strtoupper(trim((string)($in['type'] ?? '')));
        $host = trim((string)($in['host'] ?? '@')) ?: '@';
        $value = trim((string)($in['value'] ?? ''));
        if ($type === 'TXT' && preg_match('/\sIN\s+TXT\s+(.*)$/is', $value, $m)) $value = trim($m[1], " \t\n\r\"");
        $ttl = max(60, min(2147483, (int)($in['ttl'] ?? 3600)));
        $prio = max(0, min(65535, (int)($in['priority'] ?? 10)));
        if (($err = $dns->validateRecord($type, $host, $value, $prio))) $this->json(['error' => $err], 422);
        $clash = Database::query(
            "SELECT COUNT(*) c FROM dns_records WHERE domain_id=? AND host=? AND ((type='CNAME') OR (?='CNAME'))",
            [$d['id'], $host, $type])->fetch()['c'];
        if ($clash > 0) $this->json(['error' => "$host: CNAME cannot share its name with other records"], 422);
        try {
            Database::query("INSERT INTO dns_records(domain_id,type,host,value,priority,ttl) VALUES(?,?,?,?,?,?)",
                [$d['id'], $type, $host, $value, $prio, $ttl]);
            $newId = (int)Database::conn()->lastInsertId();
            $r = $dns->apply((int)$d['id'], $d['domain']);
            if (($r['code'] ?? 1) !== 0) {
                try { Database::query("DELETE FROM dns_records WHERE id=?", [$newId]); } catch (Throwable $e2) {}
                $this->json(['error' => 'BIND rejected (nothing saved): '.trim($r['out'])], 422);
            }
            Helpers::log("API DNS $type $host added on {$d['domain']} by {$me['username']}");
            $this->json(['ok' => true, 'id' => $newId]);
        } catch (Throwable $e) { $this->json(['error' => 'add failed: '.$e->getMessage()], 500); }
    }
    public function dnsUpdate(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $in = $this->apiInput();
        $t = $this->resolveTarget($me, (string)($in['username'] ?? $me['username']));
        $id = (int)($in['id'] ?? 0);
        $row = Database::query("SELECT r.*, d.domain FROM dns_records r JOIN domains d ON d.id=r.domain_id WHERE r.id=? AND d.user_id=?", [$id, $t['id']])->fetch();
        if (!$row) $this->json(['error' => 'record not found'], 404);
        if ($pz = DnsController::parentZone(['domain' => $row['domain'], 'user_id' => $t['id']])) $this->json(['error' => "{$row['domain']} has no zone of its own — its records live in the $pz zone"], 422);
        $dns = new DnsController();
        $type = strtoupper(trim((string)($in['type'] ?? $row['type'])));
        $host = trim((string)($in['host'] ?? $row['host'])) ?: '@';
        $value = trim((string)($in['value'] ?? $row['value']));
        $ttl = max(60, min(2147483, (int)($in['ttl'] ?? $row['ttl'])));
        $prio = max(0, min(65535, (int)($in['priority'] ?? $row['priority'])));
        if (($err = $dns->validateRecord($type, $host, $value, $prio))) $this->json(['error' => $err], 422);
        $clash = Database::query(
            "SELECT COUNT(*) c FROM dns_records WHERE domain_id=? AND host=? AND id<>? AND ((type='CNAME') OR (?='CNAME'))",
            [$row['domain_id'], $host, $id, $type])->fetch()['c'];
        if ($clash > 0) $this->json(['error' => "$host: CNAME cannot share its name with other records"], 422);
        try {
            Database::query("UPDATE dns_records SET type=?,host=?,value=?,priority=?,ttl=? WHERE id=?",
                [$type, $host, $value, $prio, $ttl, $id]);
            $r = $dns->apply((int)$row['domain_id'], $row['domain']);
            if (($r['code'] ?? 1) !== 0) {
                try { Database::query("UPDATE dns_records SET type=?,host=?,value=?,priority=?,ttl=? WHERE id=?",
                    [$row['type'], $row['host'], $row['value'], $row['priority'], $row['ttl'], $id]); } catch (Throwable $e2) {}
                $this->json(['error' => 'BIND rejected (kept previous values): '.trim($r['out'])], 422);
            }
            Helpers::log("API DNS record $id updated on {$row['domain']} by {$me['username']}");
            $this->json(['ok' => true, 'id' => $id]);
        } catch (Throwable $e) { $this->json(['error' => 'update failed: '.$e->getMessage()], 500); }
    }
    public function dnsDelete(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $in = $this->apiInput();
        $t = $this->resolveTarget($me, (string)($in['username'] ?? $me['username']));
        $id = (int)($in['id'] ?? 0);
        $row = Database::query("SELECT r.*, d.domain FROM dns_records r JOIN domains d ON d.id=r.domain_id WHERE r.id=? AND d.user_id=?", [$id, $t['id']])->fetch();
        if (!$row) $this->json(['error' => 'record not found'], 404);
        Database::query("DELETE FROM dns_records WHERE id=?", [$id]);
        $r = (new DnsController())->apply((int)$row['domain_id'], $row['domain']);
        Helpers::log("API DNS record $id deleted on {$row['domain']} by {$me['username']}");
        $this->json(['ok' => true, 'id' => $id, 'reloaded' => ($r['code'] ?? 1) === 0]);
    }
    // ---- mail ----
    private function resolveMailDomain(array $t, $domainId): array {
        $d = Database::query("SELECT * FROM domains WHERE id=? AND user_id=?", [(int)$domainId, $t['id']])->fetch();
        if (!$d) $this->json(['error' => 'domain not found'], 404);
        return $d;
    }
    public function mailList(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $in = array_merge($this->apiInput(), $_GET);
        $t = $this->resolveTarget($me, (string)($in['username'] ?? $me['username']));
        if (!empty($in['domain'])) {
            $d = $this->resolveDomain($t, (string)$in['domain']);
            $acc = Database::query("SELECT id,localpart,quota_mb FROM email_accounts WHERE domain_id=? ORDER BY localpart", [$d['id']])->fetchAll();
            $fwd = Database::query("SELECT id,localpart,targets FROM email_forwards WHERE domain_id=? ORDER BY localpart", [$d['id']])->fetchAll();
        } else {
            $acc = Database::query("SELECT e.id,e.localpart,e.quota_mb,d.domain FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE e.user_id=? ORDER BY d.domain,e.localpart", [$t['id']])->fetchAll();
            $fwd = Database::query("SELECT f.id,f.localpart,f.targets,d.domain FROM email_forwards f JOIN domains d ON d.id=f.domain_id WHERE f.user_id=? ORDER BY d.domain,f.localpart", [$t['id']])->fetchAll();
        }
        $this->json(['ok' => true, 'username' => $t['username'], 'accounts' => $acc, 'forwards' => $fwd]);
    }
    public function mailCreate(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $in = $this->apiInput();
        $t = $this->resolveTarget($me, (string)($in['username'] ?? $me['username']));
        $local = trim((string)($in['local'] ?? ''));
        $pass = (string)($in['password'] ?? '');
        if (!preg_match('/^[a-z0-9._-]{1,64}$/i', $local) || strlen($pass) < 8) $this->json(['error' => 'bad email/password (local-part charset, pass 8+ chars)'], 422);
        $d = $this->resolveMailDomain($t, $in['domain_id'] ?? 0);
        if (($d['mail_handling'] ?? 'local') === 'remote') $this->json(['error' => 'mail for '.$d['domain'].' routes externally — switch it to Local first'], 422);
        if ($err = Helpers::limitError($t, 'emails')) $this->json(['error' => $err], 422);
        if ($err = Helpers::diskBlocked($t)) $this->json(['error' => $err], 422);
        try {
            $hash = password_hash($pass, PASSWORD_BCRYPT);
            Database::query("INSERT INTO email_accounts(user_id,domain_id,localpart,password_hash,quota_mb) VALUES(?,?,?,?,?)", [$t['id'], $d['id'], $local, $hash, 1024]);
            ServiceManager::run('email-add.sh', [$local.'@'.$d['domain'], $t['username']]);
            Helpers::log("API mailbox $local@{$d['domain']} created by {$me['username']}");
            $this->json(['ok' => true, 'mailbox' => $local.'@'.$d['domain']]);
        } catch (Throwable $e) { $this->json(['error' => 'create failed: '.$e->getMessage()], 500); }
    }
    public function mailDelete(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $in = $this->apiInput();
        $t = $this->resolveTarget($me, (string)($in['username'] ?? $me['username']));
        $id = (int)($in['id'] ?? 0);
        $row = Database::query("SELECT e.*, d.domain FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE e.id=? AND e.user_id=?", [$id, $t['id']])->fetch();
        if (!$row) $this->json(['error' => 'mailbox not found'], 404);
        try {
            Database::query("DELETE FROM email_accounts WHERE id=?", [$row['id']]);
            Database::query("DELETE FROM email_filters WHERE domain_id=? AND localpart=?", [$row['domain_id'], $row['localpart']]);
            Database::query("DELETE FROM mail_imports WHERE domain_id=? AND localpart=?", [$row['domain_id'], $row['localpart']]);
            ServiceManager::run('email-remove.sh', [$row['localpart'].'@'.$row['domain']]);
            Helpers::log("API mailbox {$row['localpart']}@{$row['domain']} deleted by {$me['username']}");
            $this->json(['ok' => true, 'deleted' => $row['localpart'].'@'.$row['domain']]);
        } catch (Throwable $e) { $this->json(['error' => 'delete failed: '.$e->getMessage()], 500); }
    }
    public function mailForward(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $in = $this->apiInput();
        $t = $this->resolveTarget($me, (string)($in['username'] ?? $me['username']));
        $local = strtolower(trim((string)($in['local'] ?? '')));
        $targets = trim((string)($in['targets'] ?? ''));
        if ((!preg_match('/^[a-z0-9._-]{1,64}$/', $local) && $local !== '*') || $targets === '') $this->json(['error' => 'bad forwarder (local charset, non-empty targets)'], 422);
        $d = $this->resolveMailDomain($t, $in['domain_id'] ?? 0);
        $clean = [];
        foreach (explode(',', $targets) as $tg) {
            $tg = strtolower(trim($tg));
            if (!filter_var($tg, FILTER_VALIDATE_EMAIL)) $this->json(['error' => "bad target: $tg"], 422);
            if (str_contains($tg, '|') || str_contains($tg, '/')) $this->json(['error' => 'pipes/paths refused'], 422);
            if ($tg === $local.'@'.$d['domain']) $this->json(['error' => 'self-forward refused (loop)'], 422);
            $clean[] = $tg;
        }
        $joined = implode(', ', array_unique($clean));
        Database::query("INSERT INTO email_forwards(user_id,domain_id,localpart,targets) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE targets=VALUES(targets)",
            [$t['id'], $d['id'], $local, $joined]);
        $r = ServiceManager::run('forward-add.sh', [$local.'@'.$d['domain'], $joined]);
        if (($r['code'] ?? 1) !== 0) {
            Database::query("DELETE FROM email_forwards WHERE domain_id=? AND localpart=?", [$d['id'], $local]);
            $this->json(['error' => 'forwarder refused: '.trim($r['out'])], 422);
        }
        Helpers::log("API forwarder $local@{$d['domain']} set by {$me['username']}");
        $this->json(['ok' => true, 'forwarder' => $local.'@'.$d['domain'], 'targets' => $joined]);
    }
    public function mailForwardDelete(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $in = $this->apiInput();
        $t = $this->resolveTarget($me, (string)($in['username'] ?? $me['username']));
        $id = (int)($in['id'] ?? 0);
        $row = Database::query("SELECT f.*, d.domain FROM email_forwards f JOIN domains d ON d.id=f.domain_id WHERE f.id=? AND f.user_id=?", [$id, $t['id']])->fetch();
        if (!$row) $this->json(['error' => 'forwarder not found'], 404);
        Database::query("DELETE FROM email_forwards WHERE id=?", [$id]);
        ServiceManager::run('forward-remove.sh', [$row['localpart'].'@'.$row['domain']]);
        Helpers::log("API forwarder {$row['localpart']}@{$row['domain']} removed by {$me['username']}");
        $this->json(['ok' => true, 'deleted' => $row['localpart'].'@'.$row['domain']]);
    }
    // ---- databases ----
    public function dbList(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $t = $this->scopedUser($me);
        // names + users only: passwords are shown once at create/reset, never listed
        $rows = Database::query("SELECT id,db_name,db_user,created_at FROM mysql_dbs WHERE user_id=? ORDER BY db_name", [$t['id']])->fetchAll();
        $this->json(['ok' => true, 'username' => $t['username'], 'databases' => $rows]);
    }
    public function dbCreate(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $in = $this->apiInput();
        $t = $this->resolveTarget($me, (string)($in['username'] ?? $me['username']));
        $name = preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string)($in['name'] ?? ''))));
        $pass = MysqlDb::password(isset($in['password']) ? (string)$in['password'] : null);
        if (strlen($name) < 3) $this->json(['error' => 'bad db name (3+ chars a-z0-9_)'], 422);
        if ($pass === null) $this->json(['error' => 'db password 8+ chars (omit for random)'], 422);
        if ($err = Helpers::limitError($t, 'dbs')) $this->json(['error' => $err], 422);
        if ($err = Helpers::diskBlocked($t)) $this->json(['error' => $err], 422);
        try {
            [$dbname, $dbuser] = MysqlDb::create($t, $name, $pass);
            Helpers::log("API database $dbname created for {$t['username']} by {$me['username']}");
            $this->json(['ok' => true, 'db_name' => $dbname, 'db_user' => $dbuser, 'password' => $pass]);
        } catch (Throwable $e) { $this->json(['error' => 'create failed: '.$e->getMessage()], 500); }
    }
    public function dbDelete(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $in = $this->apiInput();
        $t = $this->resolveTarget($me, (string)($in['username'] ?? $me['username']));
        $row = Database::query("SELECT * FROM mysql_dbs WHERE id=? AND user_id=?", [(int)($in['id'] ?? 0), $t['id']])->fetch();
        if (!$row) $this->json(['error' => 'database not found'], 404);
        if (!MysqlDb::validRow($row)) $this->json(['error' => 'bad stored name or system database refused'], 422);
        try {
            MysqlDb::drop($row);
            Helpers::log("API database {$row['db_name']} deleted by {$me['username']}");
            $this->json(['ok' => true, 'deleted' => $row['db_name']]);
        } catch (Throwable $e) { $this->json(['error' => 'delete failed: '.$e->getMessage()], 500); }
    }
    public function dbReset(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $in = $this->apiInput();
        $t = $this->resolveTarget($me, (string)($in['username'] ?? $me['username']));
        $row = Database::query("SELECT * FROM mysql_dbs WHERE id=? AND user_id=?", [(int)($in['id'] ?? 0), $t['id']])->fetch();
        if (!$row) $this->json(['error' => 'database not found'], 404);
        try {
            $pass = MysqlDb::resetPassword($row);
            Helpers::log("API db password reset for {$row['db_user']} by {$me['username']}");
            $this->json(['ok' => true, 'db_user' => $row['db_user'], 'password' => $pass]);
        } catch (Throwable $e) { $this->json(['error' => 'reset failed: '.$e->getMessage()], 500); }
    }
    // ---- backups ----
    public function backupList(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $t = $this->scopedUser($me);
        $rows = Database::query("SELECT id,filename,size_bytes,created_at FROM backups WHERE user_id=? ORDER BY id DESC LIMIT 50", [$t['id']])->fetchAll();
        $this->json(['ok' => true, 'username' => $t['username'], 'backups' => $rows]);
    }
    public function backupCreate(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $t = $this->scopedUser($me);
        // runs in the background (backup-bg.sh); poll backupList until the
        // new row appears — backup-account.sh registers it when done
        $r = ServiceManager::run('backup-bg.sh', ['start', $t['username']]);
        $out = trim($r['out'] ?? '');
        if (($r['code'] ?? 1) !== 0) $this->json(['error' => 'backup not started: '.$out], 500);
        Helpers::log("API backup started for {$t['username']} by {$me['username']}");
        $this->json(['ok' => true, 'running' => true, 'already_running' => $out === 'already running', 'filename' => null]);
    }
    // ---- SSL ----
    public function sslIssue(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $in = $this->apiInput();
        $t = $this->resolveTarget($me, (string)($in['username'] ?? $me['username']));
        $d = $this->resolveDomain($t, (string)($in['domain'] ?? ''));
        $owner = Database::query("SELECT email FROM users WHERE id=?", [$d['user_id']])->fetch();
        $email = strtolower(trim((string)($owner['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/@[^@]+\.[a-z]{2,}$/i', $email) || str_ends_with($email, '@localhost')) {
            $this->json(['error' => 'domain owner needs a real account email first (ACME rejects admin@localhost)'], 422);
        }
        $r = ServiceManager::run('ssl-issue.sh', [$d['domain'], $email], SslController::ISSUE_TIMEOUT);
        if (str_contains($r['out'], 'Congratulations') || ($r['code'] ?? 1) === 0) {
            Database::query("UPDATE domains SET ssl_enabled=1 WHERE domain=?", [$d['domain']]);
            try { ServiceManager::run('expiry-check.sh', [$d['domain']]); } catch (Throwable $e) {}
            Helpers::log("API SSL issued for {$d['domain']} by {$me['username']}");
            $this->json(['ok' => true, 'domain' => $d['domain'], 'ssl' => true]);
        }
        $this->json(['error' => 'SSL failed: '.trim($r['out'])], 502);
    }
    // ---- rewrite rules (.htaccess parity — OLS ignores .htaccess) ----
    public function domRewrite(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $in = $this->apiInput();
        $t = $this->resolveTarget($me, (string)($in['username'] ?? $me['username']));
        $d = $this->resolveDomain($t, (string)($in['domain'] ?? ''));
        [$rules, $err] = DomainController::cleanRules((string)($in['rules'] ?? ''));
        if ($err !== null) $this->json(['error' => $err], 422);
        Database::query("UPDATE domains SET rewrite_rules=?, rewrite_src_hash=NULL WHERE id=?", [$rules, $d['id']]);
        $r = ServiceManager::run('rewrite-sync.sh', [$d['domain']]);
        if (($r['code'] ?? 1) !== 0) $this->json(['error' => 'rules refused: '.trim($r['out'])], 422);
        Helpers::log("API rewrite rules saved for {$d['domain']} by {$me['username']}");
        $this->json(['ok' => true, 'domain' => $d['domain'], 'detail' => trim($r['out'])]);
    }
    public function domHtaccess(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $in = $this->apiInput();
        $t = $this->resolveTarget($me, (string)($in['username'] ?? $me['username']));
        $d = $this->resolveDomain($t, (string)($in['domain'] ?? ''));
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- path from owned domain row + fixed filename (docroot itself is trusted server data)
        $ht = rtrim((string)$d['docroot']).'/.htaccess';
        if (!is_file($ht) || !is_readable($ht)) $this->json(['error' => 'no readable .htaccess in the docroot'], 422);
        if (filesize($ht) > 65536) $this->json(['error' => '.htaccess too big (64KB max)'], 422);
        [$rules, $converted, $skipped, $report] = DomainController::convertHtaccess((string)file_get_contents($ht));
        if ($converted === 0) $this->json(['error' => 'nothing convertible', 'report' => $report], 422);
        [$clean, $err] = DomainController::cleanRules(implode("\n", $rules));
        if ($err !== null) $this->json(['error' => 'converter produced bad output (refused): '.$err], 500);
        $srcHash = hash('sha256', (string)file_get_contents($ht));
        Database::query("UPDATE domains SET rewrite_rules=?, rewrite_src_hash=?, rewrite_imported_at=NOW() WHERE id=?", [$clean, $srcHash, $d['id']]);
        $r = ServiceManager::run('rewrite-sync.sh', [$d['domain']]);
        if (($r['code'] ?? 1) !== 0) $this->json(['error' => 'sync refused: '.trim($r['out']), 'converted' => $converted, 'skipped' => $skipped, 'report' => $report], 422);
        Helpers::log("API .htaccess import for {$d['domain']} by {$me['username']}: $converted converted, $skipped skipped");
        $this->json(['ok' => true, 'domain' => $d['domain'], 'converted' => $converted, 'skipped' => $skipped, 'report' => $report, 'detail' => trim($r['out'])]);
    }
}
