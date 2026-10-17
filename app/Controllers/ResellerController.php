<?php
declare(strict_types=1);
class ResellerController extends Controller {
    public function index(): void {
        Auth::requireRole(['admin','reseller']);
        $me = Auth::user();
        $clients = User::all((int)$me['id']);
        // per-client usage for the table (domains count, disk used, BW month).
        // disk comes from the 5-min display cache (no tree walks per row).
        foreach ($clients as &$c) {
            try {
                $c['n_domains'] = (int)Database::query("SELECT COUNT(*) c FROM domains WHERE user_id=?", [$c['id']])->fetch()['c'];
                $q = Helpers::quota($c);
                $c['disk_used_mb'] = $q['used_mb'];
                $bw = Helpers::bandwidthMonthBytes($c);
                $c['bw_month_mb'] = $bw === null ? null : round($bw / 1048576, 1);
            } catch (Throwable $e) { $c['n_domains'] = 0; $c['disk_used_mb'] = 0; $c['bw_month_mb'] = null; }
            // today's authenticated sends (rate-limiter count; failures show as —)
            $c['sent_today'] = null;
            try {
                $r = ServiceManager::run('mail-sent.sh', [$c['username']]);
                if (($r['code'] ?? 1) === 0 && preg_match('/^\d+$/', trim($r['out']))) $c['sent_today'] = (int)trim($r['out']);
            } catch (Throwable $t) {}
        }
        unset($c);
        $packages = Package::visibleTo($me);
        $limits = $me['role']==='admin' ? [] : Database::query("SELECT * FROM users WHERE id=?", [$me['id']])->fetch();
        $alloc = $me['role']==='reseller' ? Helpers::allocationUsage($me) : null;
        $myns = Database::query("SELECT ns1,ns2 FROM users WHERE id=?", [$me['id']])->fetch() ?: ['ns1'=>'','ns2'=>''];
        $brand = $me['role'] === 'reseller' ? (Branding::find((int)$me['id']) ?? []) : null;
        $this->view('reseller', compact('clients','packages','limits','alloc','myns','brand'));
    }
    public function nsSave(): void {
        Auth::requireRole(['admin','reseller']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/reseller'); }
        $me = Auth::user();
        $ns1 = strtolower(trim($_POST['ns1'] ?? ''));
        $ns2 = strtolower(trim($_POST['ns2'] ?? ''));
        // empty clears back to default; otherwise both must be valid hostnames
        if ($ns1 === '' && $ns2 === '') {
            Database::query("UPDATE users SET ns1=NULL, ns2=NULL WHERE id=?", [$me['id']]);
            Helpers::flash('ok','Nameservers reset to default'); Helpers::redirect('/reseller');
        }
        if (!Helpers::validDomain($ns1) || !Helpers::validDomain($ns2) || $ns1 === $ns2) {
            Helpers::flash('err','Both nameservers must be valid, distinct hostnames'); Helpers::redirect('/reseller');
        }
        Database::query("UPDATE users SET ns1=?, ns2=? WHERE id=?", [$ns1, $ns2, $me['id']]);
        Helpers::flash('ok',"Nameservers set ($ns1 / $ns2) — new zones use them; point glue at this box yourself");
        Helpers::redirect('/reseller');
    }
    // White-label branding for the reseller and their clients (see Branding)
    public function brandSave(): void {
        Auth::requireRole(['reseller']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/reseller'); }
        $me = Auth::user();
        $fail = function (string $m) { Helpers::flash('err', $m); Helpers::redirect('/reseller#branding'); };
        $name = trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', (string)($_POST['name'] ?? '')) ?? '');
        if (mb_strlen($name) > 40) $fail('Panel name: at most 40 characters');
        $accent = strtolower(trim((string)($_POST['accent'] ?? '')));
        if ($accent !== '' && !preg_match('/^#[0-9a-f]{6}$/', $accent)) $fail('Accent colour must look like #0ea5e9');
        $url = trim((string)($_POST['support_url'] ?? ''));
        if ($url !== '' && (strlen($url) > 190 || !filter_var($url, FILTER_VALIDATE_URL) || strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https')) {
            $fail('Support link must be a full https:// address');
        }
        $email = trim((string)($_POST['support_email'] ?? ''));
        if ($email !== '' && (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL))) $fail('Support email is not a valid address');
        $old = Branding::find((int)$me['id']);
        $logoB64 = null; $hash = $old['logo_hash'] ?? ''; $keepLogo = true;
        $f = $_FILES['logo'] ?? null;
        if (!empty($_POST['remove_logo'])) { $keepLogo = false; $hash = ''; }
        elseif (is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ($f['error'] !== UPLOAD_ERR_OK || !is_string($f['tmp_name'] ?? null) || !is_uploaded_file($f['tmp_name'])) $fail('Logo upload failed (max 1 MB)');
            [$png, $err] = Branding::processLogo($f['tmp_name']);
            if ($png === null) $fail((string)$err);
            $logoB64 = base64_encode($png); $hash = substr(hash('sha256', $png), 0, 16); $keepLogo = false;
        }
        if ($keepLogo) {
            Database::query("INSERT INTO branding(reseller_id,name,accent,support_url,support_email) VALUES(?,?,?,?,?)
                ON DUPLICATE KEY UPDATE name=VALUES(name), accent=VALUES(accent), support_url=VALUES(support_url), support_email=VALUES(support_email)",
                [$me['id'], $name, $accent, $url, $email]);
        } else {
            Database::query("INSERT INTO branding(reseller_id,name,accent,support_url,support_email,logo,logo_hash) VALUES(?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE name=VALUES(name), accent=VALUES(accent), support_url=VALUES(support_url), support_email=VALUES(support_email), logo=VALUES(logo), logo_hash=VALUES(logo_hash)",
                [$me['id'], $name, $accent, $url, $email, $logoB64, $hash]);
        }
        Helpers::flash('ok', 'Branding saved — you and your clients now see it');
        Helpers::redirect('/reseller#branding');
    }
    public function brandReset(): void {
        Auth::requireRole(['reseller']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/reseller'); }
        Database::query("DELETE FROM branding WHERE reseller_id=?", [Auth::user()['id']]);
        Helpers::flash('ok', 'Branding removed — back to the standard look');
        Helpers::redirect('/reseller#branding');
    }
    // GET /brand/logo?h=<hash>: only the brand that applies to the viewer,
    // hash-versioned so it can be cached forever
    public function brandLogo(): void {
        Auth::requireLogin();
        $b = Branding::forUser(Auth::user());
        $png = ($b && $b['logo_hash'] !== '' && hash_equals($b['logo_hash'], (string)($_GET['h'] ?? ''))) ? Branding::logo((int)$b['reseller_id']) : null;
        if ($png === null) { http_response_code(404); exit; }
        header('Content-Type: image/png');
        header('Content-Length: '.strlen($png));
        header('Cache-Control: private, max-age=31536000, immutable');
        // nosemgrep: php.lang.security.injection.echoed-request.echoed-request -- re-encoded PNG bytes from the DB, served as image/png + nosniff
        echo $png;
        exit;
    }
    // Move a client to another plan: quotas follow the package, pool is
    // resized to its max_children. Resellers stay allocation-capped (change-
    // aware: the client's old quota frees up first). Downgrades below live
    // usage are refused instead of instantly breaking the account.
    public function changePlan(): void {
        Auth::requireRole(['admin','reseller']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/reseller'); }
        $me = Auth::user();
        $t = Database::query("SELECT * FROM users WHERE id=?", [(int)($_POST['id'] ?? 0)])->fetch();
        if (!$t || ($me['role'] === 'reseller' && (int)$t['owner_id'] !== (int)$me['id'])) {
            Helpers::flash('err','Account not found'); Helpers::redirect('/reseller');
        }
        $pkg = Package::findVisible((int)($_POST['package_id'] ?? 0), $me);
        if (!$pkg) { Helpers::flash('err','Plan not found'); Helpers::redirect('/reseller'); }
        if ($me['role'] === 'reseller') {
            $mine = Database::query("SELECT disk_quota_mb,bandwidth_quota_gb FROM users WHERE id=?", [$me['id']])->fetch();
            $used = Database::query("SELECT COALESCE(SUM(disk_quota_mb),0) d, COALESCE(SUM(bandwidth_quota_gb),0) b FROM users WHERE owner_id=? AND status='active' AND id!=?", [$me['id'], $t['id']])->fetch();
            if ((int)$used['d'] + (int)$pkg['disk_quota_mb'] > (int)($mine['disk_quota_mb'] ?? 0)) {
                Helpers::flash('err','Reseller disk allocation exceeded with this plan — ask admin to raise your quota'); Helpers::redirect('/reseller');
            }
            if ((int)$used['b'] + (int)$pkg['bandwidth_quota_gb'] > (int)($mine['bandwidth_quota_gb'] ?? 0)) {
                Helpers::flash('err','Reseller bandwidth allocation exceeded with this plan — ask admin to raise your quota'); Helpers::redirect('/reseller');
            }
        }
        if ($err = self::planFitError($t, $pkg)) { Helpers::flash('err', "Cannot move: $err"); Helpers::redirect('/reseller'); }
        Database::query("UPDATE users SET package_id=?, disk_quota_mb=?, bandwidth_quota_gb=? WHERE id=?",
            [$pkg['id'], $pkg['disk_quota_mb'], $pkg['bandwidth_quota_gb'], $t['id']]);
        $kids = max(2, min(50, (int)($pkg['max_children'] ?? 10)));
        $r = self::resizePool($t, $kids);
        Helpers::log("plan change: {$t['username']} -> {$pkg['name']} by {$me['username']} pool=".json_encode($r));
        Helpers::notify((int)$t['id'], 'account', "Your plan was changed to {$pkg['name']} by {$me['username']}");
        Helpers::flash('ok',"{$t['username']} moved to {$pkg['name']} (quotas + {$kids}-worker pool). ".trim($r['out'] ?? ''));
        Helpers::redirect('/reseller');
    }
    // live usage above a plan would break the account instantly (null = fits)
    private static function planFitError(array $t, array $pkg): ?string {
        try { $q = Helpers::quota($t); } catch (Throwable $e) { $q = null; }
        if ($q && $q['used_mb'] > (float)$pkg['disk_quota_mb']) {
            return "{$t['username']} uses ".Helpers::fmtMb($q['used_mb'])." disk, over {$pkg['name']}'s ".Helpers::fmtMb((float)$pkg['disk_quota_mb']).". Free space or pick a bigger plan.";
        }
        foreach (['domains' => 'max_domains', 'email_accounts' => 'max_emails', 'mysql_dbs' => 'max_dbs', 'ftp_accounts' => 'max_ftp'] as $table => $col) {
            try { $n = (int)Database::query("SELECT COUNT(*) c FROM {$table} WHERE user_id=?", [$t['id']])->fetch()['c']; }
            catch (Throwable $e) { $n = 0; }
            if ($n > (int)$pkg[$col]) return "{$t['username']} has {$n} ".str_replace('_',' ',$table).", over {$pkg['name']}'s limit ({$pkg[$col]}). Remove some or pick a bigger plan.";
        }
        return null;
    }
    // PHP-FPM pool keeps the account's own version; only the size moves
    private static function resizePool(array $t, int $kids): array {
        $ver = Database::query("SELECT php_version FROM domains WHERE user_id=? LIMIT 1", [$t['id']])->fetch()['php_version'] ?? null;
        $ver = PhpVersions::orDefault(is_string($ver) ? $ver : null);
        return ServiceManager::run('php-pool.sh', [$t['username'], str_replace('.', '', $ver), (string)$kids]);
    }
    // form -> package columns, or an error string
    private static function packageInput(): array|string {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) return 'Package name required (80 characters max)';
        $n = [];
        foreach (['disk' => 5120, 'bw' => 100, 'domains' => 10, 'emails' => 100, 'dbs' => 10, 'ftp' => 10] as $k => $def) {
            $v = trim((string)($_POST[$k] ?? $def));
            if (!preg_match('/^\d{1,9}$/', $v)) return "Bad value for $k (whole number, 0 or more)";
            $n[$k] = (int)$v;
        }
        if ($n['disk'] < 1) return 'Disk must be at least 1 MB';
        return ['name' => $name, 'disk_quota_mb' => $n['disk'], 'bandwidth_quota_gb' => $n['bw'], 'max_domains' => $n['domains'],
            'max_emails' => $n['emails'], 'max_dbs' => $n['dbs'], 'max_ftp' => $n['ftp'],
            'php_version' => PhpVersions::isSelectable((string)($_POST['php'] ?? '')) ? (string)$_POST['php'] : PhpVersions::DEFAULT,
            'max_children' => max(2, min(50, (int)($_POST['kids'] ?? 10)))];
    }
    private static function nameTaken(string $name, ?int $resellerId, int $exceptId = 0): bool {
        // the (name, reseller_id) key can't see NULL owners — check explicitly
        return (bool)Database::query("SELECT id FROM packages WHERE name=? AND reseller_id <=> ? AND id<>? LIMIT 1", [$name, $resellerId, $exceptId])->fetch();
    }
    public function package(): void {        Auth::requireRole(['admin','reseller']);        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/reseller'); }
        $me = Auth::user();
        $in = self::packageInput();
        if (is_string($in)) { Helpers::flash('err', $in); Helpers::redirect('/reseller'); }
        if (self::nameTaken($in['name'], (int)$me['id'])) { Helpers::flash('err','Package name already used (or double-clicked) — check the list below'); Helpers::redirect('/reseller'); }
        try {
            Database::query("INSERT INTO packages(name,reseller_id,disk_quota_mb,bandwidth_quota_gb,max_domains,max_emails,max_dbs,max_ftp,php_version,max_children) VALUES(?,?,?,?,?,?,?,?,?,?)",
                [$in['name'], $me['id'], $in['disk_quota_mb'], $in['bandwidth_quota_gb'], $in['max_domains'], $in['max_emails'], $in['max_dbs'], $in['max_ftp'], $in['php_version'], $in['max_children']]);
            Helpers::flash('ok','Package created');
        } catch (Throwable $e) { Helpers::flash('err','Package name already used (or double-clicked) — check the list below'); }
        Helpers::redirect('/reseller');
    }
    // Edit a plan. Like cPanel/DirectAdmin, accounts on it follow: disk +
    // bandwidth quotas are pushed to them, limits apply live, pools resize
    // when workers change. PHP version only seeds NEW accounts (re-versioning
    // live pools is php-switch's job). Refused while any attached account
    // already exceeds the new limits, or a reseller's allocation would burst.
    public function packageUpdate(): void {
        Auth::requireRole(['admin','reseller']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/reseller'); }
        $me = Auth::user();
        $pkg = Package::find((int)($_POST['id'] ?? 0));
        if (!$pkg || !Package::canManage($pkg, $me)) { Helpers::flash('err','Package not found'); Helpers::redirect('/reseller'); }
        $in = self::packageInput();
        if (is_string($in)) { Helpers::flash('err', $in); Helpers::redirect('/reseller'); }
        $owner = $pkg['reseller_id'] === null ? null : (int)$pkg['reseller_id'];
        if (self::nameTaken($in['name'], $owner, (int)$pkg['id'])) { Helpers::flash('err',"Another package is already called {$in['name']}"); Helpers::redirect('/reseller'); }
        $new = $in + ['id' => $pkg['id']];
        $users = Database::query("SELECT * FROM users WHERE package_id=?", [$pkg['id']])->fetchAll();
        foreach ($users as $t) {
            if ($err = self::planFitError($t, $new)) { Helpers::flash('err', "Not saved: $err"); Helpers::redirect('/reseller'); }
        }
        // reseller plan: the owner's clients must still fit the owner's allocation
        if ($owner !== null) {
            $r = Database::query("SELECT role,disk_quota_mb,bandwidth_quota_gb FROM users WHERE id=?", [$owner])->fetch();
            if ($r && $r['role'] === 'reseller' && (int)$r['disk_quota_mb'] > 0) {
                $u = Database::query("SELECT COALESCE(SUM(CASE WHEN package_id<=>? THEN 0 ELSE disk_quota_mb END),0) d, COALESCE(SUM(CASE WHEN package_id<=>? THEN 0 ELSE bandwidth_quota_gb END),0) b, COALESCE(SUM(package_id<=>?),0) n FROM users WHERE owner_id=? AND status='active'",
                    [$pkg['id'], $pkg['id'], $pkg['id'], $owner])->fetch();
                $d = (int)$u['d'] + (int)$u['n'] * $in['disk_quota_mb'];
                $b = (int)$u['b'] + (int)$u['n'] * $in['bandwidth_quota_gb'];
                if ($d > (int)$r['disk_quota_mb'] || $b > (int)$r['bandwidth_quota_gb']) {
                    Helpers::flash('err', "Not saved: the {$u['n']} account(s) on this plan would need ".Helpers::fmtMb((float)$d)." disk / {$b} GB bandwidth in total, over the reseller allocation (".Helpers::fmtMb((float)$r['disk_quota_mb'])." / {$r['bandwidth_quota_gb']} GB)");
                    Helpers::redirect('/reseller');
                }
            }
        }
        Database::query("UPDATE packages SET name=?,disk_quota_mb=?,bandwidth_quota_gb=?,max_domains=?,max_emails=?,max_dbs=?,max_ftp=?,php_version=?,max_children=? WHERE id=?",
            [$in['name'], $in['disk_quota_mb'], $in['bandwidth_quota_gb'], $in['max_domains'], $in['max_emails'], $in['max_dbs'], $in['max_ftp'], $in['php_version'], $in['max_children'], $pkg['id']]);
        Database::query("UPDATE users SET disk_quota_mb=?, bandwidth_quota_gb=? WHERE package_id=?", [$in['disk_quota_mb'], $in['bandwidth_quota_gb'], $pkg['id']]);
        $pools = 0;
        if ($in['max_children'] !== (int)($pkg['max_children'] ?? 10)) {
            foreach ($users as $t) { if (Helpers::validUsername($t['username']) && (self::resizePool($t, $in['max_children'])['code'] ?? 1) === 0) $pools++; }
        }
        Helpers::log("package {$pkg['id']} '{$pkg['name']}' edited by {$me['username']}: ".json_encode($in)." accounts=".count($users)." pools=$pools");
        Helpers::flash('ok', "Package {$in['name']} saved".($users ? ' — '.count($users).' account(s) updated'.($pools ? ", $pools PHP pool(s) resized" : '') : '')
            .($in['php_version'] !== $pkg['php_version'] ? '. PHP version applies to new accounts only' : '')
            .($in['name'] !== $pkg['name'] ? '. Renamed: update any WHMCS product that used the old name' : ''));
        Helpers::redirect('/reseller');
    }
    // delete only while nothing is on it (any role, admin included)
    public function packageDelete(): void {
        Auth::requireRole(['admin','reseller']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/reseller'); }
        $me = Auth::user();
        $pkg = Package::find((int)($_POST['id'] ?? 0));
        if (!$pkg || !Package::canManage($pkg, $me)) { Helpers::flash('err','Package not found'); Helpers::redirect('/reseller'); }
        $on = Database::query("SELECT username FROM users WHERE package_id=? ORDER BY username", [$pkg['id']])->fetchAll();
        if ($on) {
            $names = implode(', ', array_slice(array_column($on, 'username'), 0, 5)).(count($on) > 5 ? ', …' : '');
            Helpers::flash('err', "{$pkg['name']} is in use by ".count($on)." account(s) ($names) — move them to another plan first");
            Helpers::redirect('/reseller');
        }
        Database::query("DELETE FROM packages WHERE id=? AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM users WHERE package_id=?) x)", [$pkg['id'], $pkg['id']]);
        Helpers::log("package {$pkg['id']} '{$pkg['name']}' deleted by {$me['username']}");
        Helpers::flash('ok', "Package {$pkg['name']} deleted");
        Helpers::redirect('/reseller');
    }
}
