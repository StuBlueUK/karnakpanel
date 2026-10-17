<?php
declare(strict_types=1);

class DashboardController extends Controller {
    public function index(): void {
        Auth::requireLogin();
        $u = Auth::user();
        $stats = ['domains'=>0,'emails'=>0,'ftps'=>0,'dbs'=>0];
        try {
            $stats['domains'] = (int)Database::query("SELECT COUNT(*) c FROM domains WHERE user_id=?", [$u['id']])->fetch()['c'];
            $stats['emails'] = (int)Database::query("SELECT COUNT(*) c FROM email_accounts WHERE user_id=?", [$u['id']])->fetch()['c'];
            $stats['ftps'] = (int)Database::query("SELECT COUNT(*) c FROM ftp_accounts WHERE user_id=?", [$u['id']])->fetch()['c'];
            $stats['dbs'] = (int)Database::query("SELECT COUNT(*) c FROM mysql_dbs WHERE user_id=?", [$u['id']])->fetch()['c'];
        } catch (Throwable $e) {}
        $services = Auth::role() === 'admin' ? ServiceManager::status() : [];
        $limits = null;
        try {
            $pkgId = Database::query("SELECT package_id FROM users WHERE id=?", [$u['id']])->fetch()['package_id'] ?? null;
            if ($pkgId) $limits = Database::query("SELECT name,max_domains,max_emails,max_dbs,max_ftp FROM packages WHERE id=?", [$pkgId])->fetch() ?: null;
        } catch (Throwable $e) {}
        $recent_domains = [];
        $recent_backups = [];
        $bw_hist = []; $bw_month_mb = 0;
        $server_ip = '';
        try {
            $server_ip = trim(ServiceManager::run('server-ip.sh', [])['out'] ?? '');
            if (!filter_var($server_ip, FILTER_VALIDATE_IP)) $server_ip = '';
        } catch (Throwable $e) {}
        // server disk, straight from PHP (no sudo needed) — admin only, so
        // nobody confuses it with their account quota again
        // available-update banner (daily probe state; persists until installed,
        // urgent when the release notes mention security)
        $update = null;
        if (($u['role'] ?? '') === 'admin') {
            $st = @file('/var/lib/karnakpanel/update-check', FILE_IGNORE_NEW_LINES);
            if ($st && preg_match('/^(\S+) (\S+) (ok|urgent|info|unreachable)$/', trim(implode('', $st)), $m) && $m[3] !== 'ok') {
                $update = ['local' => $m[1], 'remote' => $m[2], 'sev' => $m[3]];
            }
        }
        $disk = null;
        if (($u['role'] ?? '') === 'admin') {
            try {
                $total = disk_total_space('/'); $free = disk_free_space('/');
                if ($total > 0) $disk = ['total' => $total, 'free' => $free, 'used' => $total - $free];
            } catch (Throwable $e) {}
        }
        $server = null;
        if ($u['role'] === 'admin') {
            try {
                $up = (float)explode(' ', (string)@file_get_contents('/proc/uptime'))[0];
                $d = (int)($up / 86400);
                $server = [
                    'uptime' => ($d > 0 ? "$d days, " : '').gmdate('H:i', (int)($up % 86400)),
                    'os' => 'unknown',
                    'load' => trim((string)@file_get_contents('/proc/loadavg')),
                    'cpus' => 1,
                ];
                foreach ((array)@file('/etc/os-release', FILE_IGNORE_NEW_LINES) as $line) {
                    if (str_starts_with($line, 'PRETTY_NAME=')) $server['os'] = trim(trim(substr($line, 12)), '"');
                    if (str_starts_with($line, 'VERSION_ID=')) $server['os_ver'] = trim(trim(substr($line, 11)), '"');
                }
                $server['cpus'] = max(1, substr_count((string)@file_get_contents('/proc/cpuinfo'), 'processor'));
                $server['load'] = implode(' ', array_slice(explode(' ', $server['load']), 0, 3));
                $mem = ['total' => 0, 'avail' => 0];
                foreach ((array)@file('/proc/meminfo', FILE_IGNORE_NEW_LINES) as $line) {
                    if (str_starts_with($line, 'MemTotal:')) $mem['total'] = (int)filter_var($line, FILTER_SANITIZE_NUMBER_INT);
                    if (str_starts_with($line, 'MemAvailable:')) $mem['avail'] = (int)filter_var($line, FILTER_SANITIZE_NUMBER_INT);
                }
                $server['mem_used_mb'] = round(max(0, $mem['total'] - $mem['avail']) / 1024, 0);
                $server['mem_total_mb'] = round($mem['total'] / 1024, 0);
                $server['mem_pct'] = $mem['total'] > 0 ? round(($mem['total'] - $mem['avail']) / $mem['total'] * 100, 1) : 0;
            } catch (Throwable $e) { $server = null; }
        }
        $procs = []; $hist = [];
        if ($u['role'] === 'admin') {
            try {
                // rolling sample for sparklines (one/min max, prune past 24h)
                $l1 = (float)explode(' ', trim((string)@file_get_contents('/proc/loadavg')))[0];
                Database::query("INSERT INTO server_stats(ts,load1,mem_pct) VALUES(DATE_FORMAT(NOW(),'%Y-%m-%d %H:%i:00'),?,?) ON DUPLICATE KEY UPDATE load1=VALUES(load1), mem_pct=VALUES(mem_pct)",
                    [$l1, $server['mem_pct'] ?? 0]);
                Database::query("DELETE FROM server_stats WHERE ts < (NOW() - INTERVAL 1 DAY)");
                $hist = Database::query("SELECT ts,load1,mem_pct FROM server_stats ORDER BY ts DESC LIMIT 96")->fetchAll();
                $hist = array_reverse($hist);
            } catch (Throwable $e) {}
            $po = [];
            exec('ps -eo user:16,pid,pcpu,pmem,comm --sort=-pcpu 2>/dev/null | head -13', $po);
            array_shift($po);
            foreach ($po as $line) {
                $p = preg_split('/\s+/', trim($line), 5);
                if (count($p) === 5) $procs[] = $p;
            }
        }
        try {
            $recent_domains = Database::query("SELECT domain,php_version,ssl_enabled,created_at FROM domains WHERE user_id=? ORDER BY id DESC LIMIT 5", [$u['id']])->fetchAll();
            $recent_backups = Database::query("SELECT filename,size_bytes,created_at FROM backups WHERE user_id=? ORDER BY id DESC LIMIT 5", [$u['id']])->fetchAll();
            // refresh quota limits from DB (admin may have changed package)
            $fresh = Database::query("SELECT disk_quota_mb,bandwidth_quota_gb FROM users WHERE id=?", [$u['id']])->fetch();
            if ($fresh) { $_SESSION['user']['disk_quota_mb'] = $fresh['disk_quota_mb']; $_SESSION['user']['bandwidth_quota_gb'] = $fresh['bandwidth_quota_gb']; $u = Auth::user(); }
        } catch (Throwable $e) {}
        try {
            // 14-day bandwidth history (gaps filled with 0) + month total, MB
            $rows = Database::query("SELECT day, SUM(bytes) b FROM bandwidth_daily WHERE user_id=? AND day >= CURDATE() - INTERVAL 13 DAY GROUP BY day", [$u['id']])->fetchAll();
            $byDay = [];
            foreach ($rows as $r) $byDay[$r['day']] = (float)$r['b'] / 1048576;
            for ($i = 13; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime("-$i days"));
                $bw_hist[] = ['day' => $d, 'mb' => round($byDay[$d] ?? 0, 2)];
            }
            $bw_month_mb = round((Helpers::bandwidthMonthBytes($u) ?? 0) / 1048576, 1);
        } catch (Throwable $e) {}
        // first-run setup guide (admin only, dismissible): live checklist that
        // ticks itself off as the box gets configured — no dead README step
        $setup = null;
        if (($u['role'] ?? '') === 'admin') {
            try {
                $guideOff = (string)(Database::query("SELECT value FROM settings WHERE name='setup_guide'")->fetch()['value'] ?? '');
                if ($guideOff !== 'off') {
                    $nUsers = (int)Database::query("SELECT COUNT(*) c FROM users")->fetch()['c'];
                    // the panel's own hostname (panel.*) isn't a hosted site —
                    // exclude it so step 3 demands a real top-level domain
                    $panelDomain = strtolower(trim((string)(Database::query("SELECT value FROM settings WHERE name='panel_domain'")->fetch()['value'] ?? '')));
                    $nDomains = (int)Database::query("SELECT COUNT(*) c FROM domains")->fetch()['c'];
                    $nSites = ($panelDomain !== '')
                        ? (int)Database::query("SELECT COUNT(*) c FROM domains WHERE domain<>?", [$panelDomain])->fetch()['c']
                        : $nDomains;
                    $nSsl = (int)Database::query("SELECT COUNT(*) c FROM domains WHERE ssl_enabled=1")->fetch()['c'];
                    $totp = (int)Database::query("SELECT COUNT(*) c FROM users WHERE totp_enabled=1")->fetch()['c'];
                    $alert = (string)(Database::query("SELECT value FROM settings WHERE name='alert_email'")->fetch()['value'] ?? '');
                    // glue verdict comes from the nightly dns-glue-check (live
                    // DNS on every page load would be slow); missing cache =
                    // never checked = not done
                    $glueOk = (string)(Database::query("SELECT value FROM settings WHERE name='glue_ok'")->fetch()['value'] ?? '');
                    $glueWhen = (string)(Database::query("SELECT value FROM settings WHERE name='glue_checked_at'")->fetch()['value'] ?? '');
                    $offsite = (string)(Database::query("SELECT value FROM settings WHERE name='offsite_provider'")->fetch()['value'] ?? '');
                    $hb = (string)(Database::query("SELECT value FROM settings WHERE name='watchdog_heartbeat'")->fetch()['value'] ?? '');
                    $glueAuto = ($glueOk === '1') ? 'glue seen'.($glueWhen !== '' ? ' '.$glueWhen : '') : 'no glue seen yet — checked nightly'.($glueWhen !== '' ? ' (last look '.$glueWhen.')' : '');
                    $steps = [
                        ['t' => 'Change the admin password (Accounts → you) and enable 2FA', 'u' => '/2fa', 'done' => $totp > 0, 'auto' => '2FA detected'],
                        ['t' => 'Create a daily-use account — stop browsing as admin', 'u' => '/accounts', 'done' => $nUsers > 1, 'auto' => 'extra account exists'],
                        ['t' => 'Add your top-level domain (the panel\'s own address doesn\'t count)', 'u' => '/domains', 'done' => $nSites > 0, 'auto' => 'site domain exists'],
                        ['t' => 'Issue SSL for it (auto-renews; mail certs follow)', 'u' => '/domains', 'done' => $nSsl > 0, 'auto' => 'SSL on'],
                        ['t' => 'Apply SSL to panel services (:8090/:8091/:8092)', 'u' => '/admin/services', 'done' => self::servicesSecured(), 'auto' => 'services on a real cert'],
                        ['t' => 'At your registrar: glue records ns1/ns2 → this box, then a downtime alert email below', 'u' => '/admin/services', 'done' => $alert !== '' && $glueOk === '1', 'auto' => $glueAuto],
                        ['t' => 'Connect offsite backups (Backups → Offsite copy)', 'u' => '/backups', 'done' => $offsite !== '', 'auto' => 'offsite on'],
                        ['t' => 'Set a watchdog heartbeat (Services) so dead-cron pages you', 'u' => '/admin/services', 'done' => $hb !== '', 'auto' => 'heartbeat on'],
                    ];
                    $done = count(array_filter($steps, fn($s) => $s['done']));
                    if ($done < count($steps)) $setup = ['steps' => $steps, 'done' => $done, 'total' => count($steps)];
                }
            } catch (Throwable $e) {}
        }
        // persistent 2FA nudge: fresh from DB (session copy goes stale the
        // moment 2FA is enabled) — visible until this admin has 2FA on
        $totpNudge = false;
        if (($u['role'] ?? '') === 'admin') {
            try {
                $totpNudge = empty(Database::query("SELECT totp_enabled FROM users WHERE id=?", [$u['id']])->fetch()['totp_enabled']);
            } catch (Throwable $e) {}
        }
        $this->view('dashboard', compact('u','stats','services','recent_domains','recent_backups','limits','bw_hist','bw_month_mb','server','procs','hist','server_ip','disk','setup','update') + ['panelVersion' => Helpers::panelVersion(), 'totpNudge' => $totpNudge,
            // 22.04: fresh installs refused, existing boxes steered to migrate
            'osSunset' => ($server['os_ver'] ?? '') === '22.04']);
    }
    // Live server snapshot for the dashboard auto-refresh (admin only).
    // /proc + disk calls only — microseconds. 5s file cache so N open
    // dashboards share one sample instead of stacking reads.
    public function live(): void {
        Auth::requireRole(['admin']);
        $cache = sys_get_temp_dir().'/karnakpanel-live.json';
        $st = @stat($cache);
        if ($st && (time() - $st['mtime']) < 5 && ($raw = @file_get_contents($cache)) !== false) {
            $this->json(json_decode($raw, true) ?: []);
            return;
        }
        $d = ['uptime' => '?', 'load' => '?', 'cpus' => 1, 'mem_used' => 0, 'mem_total' => 0, 'mem_pct' => 0,
              'disk_used' => 0, 'disk_total' => 0, 'disk_pct' => 0, 'procs' => [], 'version' => Helpers::panelVersion()];
        try {
            $up = (float)explode(' ', (string)@file_get_contents('/proc/uptime'))[0];
            $dd = (int)($up / 86400);
            $d['uptime'] = ($dd > 0 ? "$dd days, " : '').gmdate('H:i', (int)($up % 86400));
            $d['load'] = implode(' ', array_slice(explode(' ', trim((string)@file_get_contents('/proc/loadavg'))), 0, 3));
            $d['cpus'] = max(1, substr_count((string)@file_get_contents('/proc/cpuinfo'), 'processor'));
            $mem = ['total' => 0, 'avail' => 0];
            foreach ((array)@file('/proc/meminfo', FILE_IGNORE_NEW_LINES) as $line) {
                if (str_starts_with($line, 'MemTotal:')) $mem['total'] = (int)filter_var($line, FILTER_SANITIZE_NUMBER_INT);
                if (str_starts_with($line, 'MemAvailable:')) $mem['avail'] = (int)filter_var($line, FILTER_SANITIZE_NUMBER_INT);
            }
            $d['mem_used'] = round(max(0, $mem['total'] - $mem['avail']) / 1024, 0);
            $d['mem_total'] = round($mem['total'] / 1024, 0);
            $d['mem_pct'] = $mem['total'] > 0 ? round(($mem['total'] - $mem['avail']) / $mem['total'] * 100, 1) : 0;
            $total = disk_total_space('/'); $free = disk_free_space('/');
            if ($total > 0) {
                $d['disk_used'] = $total - $free; $d['disk_total'] = $total;
                $d['disk_pct'] = round(($total - $free) / $total * 100, 1);
            }
            $po = [];
            exec('ps -eo user:16,pid,pcpu,pmem,comm --sort=-pcpu 2>/dev/null | head -9', $po);
            array_shift($po);
            foreach ($po as $line) {
                $p = preg_split('/\s+/', trim($line), 5);
                if (count($p) === 5) $d['procs'][] = $p;
            }
        } catch (Throwable $e) {}
        @file_put_contents($cache, json_encode($d), LOCK_EX);
        $this->json($d);
    }
    // finishing everything hides it without needing this.
    // box listeners on a real cert? Fail-open (true): a probe hiccup must
    // never wedge the setup guide. With the issue-time auto-apply, this
    // step mostly ticks itself off.
    private static function servicesSecured(): bool {
        try {
            $st = trim(ServiceManager::run('ssl-services-state.sh', [])['out'] ?? '');
            return $st !== '' && $st !== 'self' && $st !== 'unknown';
        } catch (Throwable $e) { return true; }
    }
    // Dismiss the setup guide (admin). It only shows while steps are open —
    public function setupDismiss(): void {        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/'); }
        try { Database::query("INSERT INTO settings(name,value) VALUES('setup_guide','off') ON DUPLICATE KEY UPDATE value=VALUES(value)"); } catch (Throwable $e) {}
        // the glue probe's only consumer is the checklist — retire its cron
        // line (best-effort: dismiss still lands if this fails)
        try {
            $r = ServiceManager::run('cron-glue.sh', ['off']);
            if (($r['code'] ?? 1) !== 0) Helpers::log('setupDismiss: cron-glue off failed: '.trim($r['out'] ?? ''));
        } catch (Throwable $e) {}
        Helpers::redirect('/');
    }
    // Re-show the setup guide after dismissal (admin). Re-arms the glue
    // probe cron line too (same best-effort terms as dismiss).
    public function setupShow(): void {        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/'); }
        try { Database::query("DELETE FROM settings WHERE name='setup_guide'"); } catch (Throwable $e) {}
        try {
            $r = ServiceManager::run('cron-glue.sh', ['on']);
            if (($r['code'] ?? 1) !== 0) Helpers::log('setupShow: cron-glue on failed: '.trim($r['out'] ?? ''));
        } catch (Throwable $e) {}
        Helpers::redirect('/');
    }
    public function loginForm(): void {
        if (Auth::check()) Helpers::redirect('/');
        // NOTE: never hint at (default) credentials here — this page is
        // visible to unauthenticated visitors. First-login hardening lives
        // in the installer (random per-install admin password, file 0600).
        $this->view('login');
    }
    public function login(): void {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/login'); }
        // brute-force guard: 8 failures / 15 min per IP (table may not exist on old installs -> fail open)
        try {
            $fails = (int)Database::query(
                "SELECT COUNT(*) c FROM login_attempts WHERE ip=? AND success=0 AND attempted_at > (NOW() - INTERVAL 15 MINUTE)",
                [$ip])->fetch()['c'];
            // per-username bucket too: spreading guesses over many IPs must
            // not escape the limit (looser cap — it doubles as a lockout lever)
            $userFails = (int)Database::query(
                "SELECT COUNT(*) c FROM login_attempts WHERE username=? AND success=0 AND attempted_at > (NOW() - INTERVAL 15 MINUTE)",
                [substr((string)($_POST['username'] ?? ''), 0, 64)])->fetch()['c'];
            if ($fails >= 8 || $userFails >= 30) {
                Helpers::log("login throttled for $ip ($fails ip / $userFails user recent failures)");
                Helpers::flash('err','Too many failed attempts — try again in 15 minutes');
                Helpers::redirect('/login');
            }
        } catch (Throwable $e) {}
        try {
            $res = Auth::attempt($_POST['username']??'', $_POST['password']??'');
            if ($res === 'ok') {
                // clear only THIS username's failures: wiping the whole IP
                // bucket let anyone with a valid account reset the counter
                // between guesses at someone else's
                try { Database::query("DELETE FROM login_attempts WHERE ip=? AND username=?", [$ip, substr((string)($_POST['username'] ?? ''), 0, 64)]); } catch (Throwable $e) {}
                Helpers::redirect('/');
            }
            if ($res === 'totp') { Helpers::redirect('/login/totp'); }
        } catch (Throwable $e) {
            Helpers::log("login DB error: ".$e->getMessage());
            Helpers::flash('err','Login unavailable — try again in a minute');
            Helpers::redirect('/login');
        }
        try {
            Database::query("INSERT INTO login_attempts(ip,username,success) VALUES(?,?,0)", [$ip, substr((string)($_POST['username'] ?? ''), 0, 64)]);
        } catch (Throwable $e) {}
        Helpers::flash('err','Invalid login');
        Helpers::log("failed login for '".preg_replace('/[^a-z0-9_.@-]/i','?',$_POST['username']??'')."' from $ip");
        Helpers::notify(null, 'login-fail', "Failed login for '".preg_replace('/[^a-z0-9_.@-]/i','?',substr((string)($_POST['username'] ?? ''),0,32))."'", "from $ip");
        Helpers::redirect('/login');
    }
    public function logout(): void {
        if (!Helpers::checkCsrf()) { Helpers::redirect('/login'); }
        Auth::logout(); Helpers::redirect('/login');
    }
    public function totpForm(): void {
        if (empty($_SESSION['totp_pending'])) Helpers::redirect('/login');
        $this->view('login_totp', []);
    }
    public function totpVerify(): void {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
        $uid = (int)($_SESSION['totp_pending'] ?? 0);
        if ($uid <= 0) Helpers::redirect('/login');
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/login/totp'); }
        // throttle like passwords: 6-digit codes fall in hours unthrottled.
        // (Counts password + totp failures together — one bucket per IP.)
        try {
            $fails = (int)Database::query(
                "SELECT COUNT(*) c FROM login_attempts WHERE ip=? AND success=0 AND attempted_at > (NOW() - INTERVAL 15 MINUTE)",
                [$ip])->fetch()['c'];
            // per-user cap across IPs: 6-digit codes must not fall to a spread run
            $userFails = (int)Database::query(
                "SELECT COUNT(*) c FROM login_attempts WHERE username=? AND success=0 AND attempted_at > (NOW() - INTERVAL 15 MINUTE)",
                ['totp:'.$uid])->fetch()['c'];
            if ($fails >= 8 || $userFails >= 10) {
                Helpers::log("totp throttled for $ip (uid $uid, $fails ip / $userFails user recent failures)");
                Helpers::flash('err','Too many failed attempts — try again in 15 minutes');
                Helpers::redirect('/login/totp');
            }
        } catch (Throwable $e) {}
        $code = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));
        $ok = false;
        try {
            $row = Database::query("SELECT username, totp_secret FROM users WHERE id=? AND totp_enabled=1", [$uid])->fetch();
            $step = $row ? Totp::verifyStep($row['totp_secret'], $code) : null;
            // replay guard: each 30s step is accepted once — the conditional
            // UPDATE is atomic, so a sniffed/shoulder-surfed code can't be
            // reused inside its window (nor raced in parallel)
            if ($step !== null) {
                $claimed = Database::query("UPDATE users SET totp_last_step=? WHERE id=? AND (totp_last_step IS NULL OR totp_last_step < ?)", [$step, $uid, $step])->rowCount() === 1;
                if ($claimed && Auth::finishTotp($uid)) $ok = true;
            }
        } catch (Throwable $e) {}
        if ($ok) {
            try { Database::query("DELETE FROM login_attempts WHERE ip=? AND username IN (?,?)", [$ip, (string)$row['username'], 'totp:'.$uid]); } catch (Throwable $e) {}
            Helpers::redirect('/');
        }
        try {
            Database::query("INSERT INTO login_attempts(ip,username,success) VALUES(?,?,0)", [$ip, 'totp:'.$uid]);
        } catch (Throwable $e) {}
        // hammering a second factor is always hostile — log + alert, unlike a
        // mistyped password (which only notifies).
        Helpers::log("failed totp for uid $uid from $ip");
        Helpers::notify(null, 'login-fail', "Failed second-factor code for user #$uid", "from $ip — repeated codes mean someone holds the password");
        Helpers::flash('err','Invalid code');
        Helpers::redirect('/login/totp');
    }
}
