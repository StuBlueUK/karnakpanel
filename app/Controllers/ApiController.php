<?php
declare(strict_types=1);
class ApiController extends Controller {
    protected function tokenAuth(): ?array {
        $h = $_SERVER['HTTP_X_API_KEY'] ?? '';
        if (!$h || !preg_match('/^[a-f0-9]{64}$/', $h)) return null;
        // tokens are stored sha256-hashed (a table leak alone grants nothing)
        $u = ApiModel::token($h);
        if (!$u) return null;
        // IP pin: a stolen token is useless off the pinned host (WHMCS IP or
        // same-box server IP). NULL/empty = any IP (previous behaviour).
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if (!empty($u['allowed_ip']) && ($u['allowed_ip'] !== $ip)) return null;
        // last-use audit (at most one write a minute per token; never fatal)
        try { ApiModel::touchToken((int)$u['token_id'], $ip); } catch (Throwable $e) {}
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
    protected function adminToken(): ?array {
        $u = $this->tokenAuth();
        return ($u && $u['role'] === 'admin') ? $u : null;
    }
    protected function apiInput(): array {
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
        $pwned = Auth::passwordPwned($password);
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
    protected function provTarget(string $username): array {
        $t = ApiModel::userByUsername($username);
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
        ApiModel::setStatus((int)$t['id'], 'suspended');
        Helpers::log("API account {$t['username']} suspended by {$me['username']}");
        Helpers::notify((int)$t['id'], 'account', "Your account was suspended by {$me['username']}");
        $this->json(['ok' => true, 'status' => 'suspended']);
    }
    public function provUnsuspend(): void {
        $me = $this->adminToken();
        if (!$me) $this->json(['error' => 'unauthorized'], 401);
        $t = $this->provTarget(strtolower(trim((string)($this->apiInput()['username'] ?? ''))));
        ServiceManager::run('suspend-account.sh', [$t['username'], 'unsuspend']);
        ApiModel::setStatus((int)$t['id'], 'active');
        Helpers::log("API account {$t['username']} unsuspended by {$me['username']}");
        Helpers::notify((int)$t['id'], 'account', "Your account was unsuspended by {$me['username']}");
        $this->json(['ok' => true, 'status' => 'active']);
    }
    public function provTerminate(): void {
        $me = $this->adminToken();
        if (!$me) $this->json(['error' => 'unauthorized'], 401);
        $t = $this->provTarget(strtolower(trim((string)($this->apiInput()['username'] ?? ''))));
        try {
            User::teardown($t);
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
        $pwned = Auth::passwordPwned($new);
        if ($pwned !== null && $pwned > 0) $this->json(['error' => 'password appears in known breaches — choose an unleaked one'], 422);
        ApiModel::setPasswordHash((int)$t['id'], Auth::hash($new));
        ServiceManager::run('password-sync.sh', [$t['username'], new Secret($new)]);
        // a reset means the old session may be stolen — end every one
        $killed = Auth::killSessions($t['username']);
        Helpers::log("API password changed for {$t['username']} by {$me['username']} ($killed sessions killed)");
        $this->json(['ok' => true]);
    }
    public function provUsage(): void {        $me = $this->adminToken();
        if (!$me) $this->json(['error' => 'unauthorized'], 401);
        $t = $this->provTarget(strtolower(trim((string)($_GET['username'] ?? ''))));
        try { $quota = Quota::usage($t); } catch (Throwable $e) { $quota = null; }
        try { $bw = Quota::bandwidthMonthBytes($t); } catch (Throwable $e) { $bw = null; }
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
                'max_ftp' => $p['max_ftp'], 'max_builder_sites' => (int)($p['max_builder_sites'] ?? 0), 'max_daemons' => (int)($p['max_daemons'] ?? 1), 'php_version' => $p['php_version']];
        }
        $this->json(['ok' => true, 'packages' => $out]);
    }
    protected function resolveTarget(array $me, string $username): array {
        $username = strtolower(trim($username));
        if (!Helpers::validUsername($username)) $this->json(['error' => 'account not found'], 404);
        $t = ApiModel::userByUsername($username);
        if (!$t) $this->json(['error' => 'account not found'], 404);
        $role = $me['role'] ?? '';
        if ($role === 'admin') return $t;
        if ($role === 'reseller' && ((int)$t['id'] === (int)$me['id'] || (int)($t['owner_id'] ?? 0) === (int)$me['id'])) return $t;
        if ($role === 'user' && (int)$t['id'] === (int)$me['id']) return $t;
        $this->json(['error' => 'account not found'], 404);
    }
    protected function resolveDomain(array $t, string $domain): array {
        $domain = strtolower(trim($domain));
        if (!Helpers::validDomain($domain)) $this->json(['error' => 'invalid domain'], 422);
        $d = ApiModel::domainByNameForUser($domain, (int)$t['id']);
        if (!$d) $this->json(['error' => 'domain not found'], 404);
        return $d;
    }
    protected function scopedUser(array $me): array {
        // GET callers pass selectors in the query string, POST callers in the
        // body — accept both (ownership is enforced after resolution anyway).
        $in = array_merge($this->apiInput(), $_GET);
        if (isset($in['username']) && trim((string)$in['username']) !== '') return $this->resolveTarget($me, (string)$in['username']);
        return $this->resolveTarget($me, $me['username']);
    }
    // ---- domains ----
    protected function resolveMailDomain(array $t, $domainId): array {
        $d = ApiModel::domainByIdForUser((int)$domainId, (int)$t['id']);
        if (!$d) $this->json(['error' => 'domain not found'], 404);
        return $d;
    }
}
