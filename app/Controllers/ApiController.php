<?php
declare(strict_types=1);
class ApiController extends Controller {
    /** The token/user resolution lives in Core/ApiAuth (logs failures for fail2ban). */
    protected function tokenAuth(): ?array { return ApiAuth::user(); }
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
    /**
     * Client-facing API failure: one generic message + a short ref. Detail
     * (exception text, privileged-script stdout, paths) goes to panel.log only —
     * API clients used to receive raw script output and SQL errors (sweep Low,
     * 2026-11-08). Support reads the ref out of the log line.
     */
    protected function apiFailed(string $what, int $code, ?Throwable $e = null, ?string $detail = null): void {
        $ref = bin2hex(random_bytes(4));
        $line = "API $what failed [ref $ref]";
        if ($e !== null) $line .= ': '.$e->getMessage();
        if ($detail !== null && trim($detail) !== '') $line .= ' — '.trim($detail);
        Helpers::log($line);
        $this->json(['error' => $what.' failed', 'ref' => $ref], $code);
    }
    public function provCreate(): void {
        $me = $this->adminToken();
        if (!$me) $this->json(['error' => 'unauthorized'], 401);
        $in = $this->apiInput();
        $username = strtolower(trim((string)($in['username'] ?? '')));
        $email = trim((string)($in['email'] ?? ''));
        $password = (string)($in['password'] ?? '');
        $pkgName = trim((string)($in['package'] ?? ''));
        if (!Helpers::validNewUsername($username) || strlen($password) < 10 || !Helpers::validEmail($email)) {
            $this->json(['error' => 'invalid username/email/password (user a-z0-9_, pass 10+ chars, real email)'], 422);
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
            $this->apiFailed('create', 500, $e);
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
        } catch (Throwable $e) { $this->apiFailed('terminate', 500, $e); }
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
