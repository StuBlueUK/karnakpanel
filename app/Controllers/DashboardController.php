<?php
declare(strict_types=1);

class DashboardController extends Controller {
    public function index(): void {
        Auth::requireLogin();
        $u = Auth::user();
        $stats = ['domains'=>0,'emails'=>0,'ftps'=>0,'dbs'=>0];
        try { $stats = DashboardModel::counts((int)$u['id']); } catch (Throwable $e) {}
        $services = Auth::role() === 'admin' ? ServiceManager::status() : [];
        $limits = null;
        try {
            $pkgId = DashboardModel::packageId((int)$u['id']);
            if ($pkgId) $limits = DashboardModel::packageLimits($pkgId);
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
                DashboardModel::statsUpsert($l1, (float)($server['mem_pct'] ?? 0));
                DashboardModel::statsPrune();
                $hist = DashboardModel::statsHistory();
            } catch (Throwable $e) {}
            $po = [];
            exec('ps -eo user:16,pid,pcpu,pmem,comm --sort=-pcpu 2>/dev/null | head -9', $po);
            array_shift($po);
            foreach ($po as $line) {
                $p = preg_split('/\s+/', trim($line), 5);
                if (count($p) === 5) $procs[] = $p;
            }
        }
        try {
            $recent_domains = DashboardModel::recentDomains((int)$u['id']);
            $recent_backups = DashboardModel::recentBackups((int)$u['id']);
            // refresh quota limits from DB (admin may have changed package)
            $fresh = DashboardModel::quotaRow((int)$u['id']);
            if ($fresh) {
                Auth::syncQuotas($fresh); // snapshot stays Auth's business
                $u = Auth::user();
            }
        } catch (Throwable $e) {}
        try {
            // 14-day bandwidth history (gaps filled with 0) + month total, MB
            $rows = DashboardModel::bandwidthDays((int)$u['id']);
            $byDay = [];
            foreach ($rows as $r) $byDay[$r['day']] = (float)$r['b'] / 1048576;
            for ($i = 13; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime("-$i days"));
                $bw_hist[] = ['day' => $d, 'mb' => round($byDay[$d] ?? 0, 2)];
            }
            $bw_month_mb = round((Quota::bandwidthMonthBytes($u) ?? 0) / 1048576, 1);
        } catch (Throwable $e) {}
        // first-run setup guide (admin only, dismissible): live checklist that
        // ticks itself off as the box gets configured — no dead README step
        $setup = null;
        if (($u['role'] ?? '') === 'admin') {
            try {
                $guideOff = (string)(DashboardModel::setting('setup_guide') ?? '');
                if ($guideOff !== 'off') {
                    $nUsers = DashboardModel::userCount();
                    // the panel's own hostname (panel.*) isn't a hosted site —
                    // exclude it so step 3 demands a real top-level domain
                    $panelDomain = strtolower(trim((string)(DashboardModel::setting('panel_domain') ?? '')));
                    $nDomains = DashboardModel::domainCount();
                    $nSites = ($panelDomain !== '') ? DashboardModel::domainCount($panelDomain) : $nDomains;
                    $nSsl = DashboardModel::sslCount();
                    $totp = DashboardModel::totpCount();
                    $alert = (string)(DashboardModel::setting('alert_email') ?? '');
                    // glue verdict comes from the nightly dns-glue-check (live
                    // DNS on every page load would be slow); missing cache =
                    // never checked = not done
                    $glueOk = (string)(DashboardModel::setting('glue_ok') ?? '');
                    $glueWhen = (string)(DashboardModel::setting('glue_checked_at') ?? '');
                    $offsite = (string)(DashboardModel::setting('offsite_provider') ?? '');
                    $hb = (string)(DashboardModel::setting('watchdog_heartbeat') ?? '');
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
                $totpNudge = !Auth::has2fa((int)$u['id']); // app OR passkey
            } catch (Throwable $e) {}
        }
        $attention = $this->attention($u, $services);
        // admin: box-wide totals + the accounts using the most (disk, then
        // this calendar month's bandwidth) — the "who's heavy" glance
        $box = null; $busy = [];
        if (($u['role'] ?? '') === 'admin') {
            try {
                $box = DashboardModel::boxTotals();
                $busy = DashboardModel::busyUsers();
            } catch (Throwable $e) {}
        }
        // Karnak Builder card: shown when the fleet switch is on and the
        // plan allows sites (or some already exist)
        $kb = null;
        try {
            if (Builder::enabled()) {
                $kbSites = Builder::forUser((int)$u['id']);
                $kbLimit = Builder::limit($u);
                if ($kbSites || $kbLimit !== 0) $kb = ['sites' => $kbSites, 'limit' => $kbLimit];
            }
        } catch (Throwable $e) {}
        $phpMe = PhpUsage::many([(string)$u['username']])[(string)$u['username']] ?? null;
        if ($phpMe && in_array($phpMe['state'], ['off', 'shared', 'unknown'], true)) $phpMe = null;
        $this->view('dashboard', compact('kb','u','stats','phpMe','services','recent_domains','recent_backups','limits','bw_hist','bw_month_mb','server','procs','hist','server_ip','disk','setup','update','attention','box','busy') + ['panelVersion' => Helpers::panelVersion(), 'totpNudge' => $totpNudge,
            // 22.04: fresh installs refused, existing boxes steered to migrate
            'osSunset' => ($server['os_ver'] ?? '') === '22.04']);
    }
    // "Needs attention" card: things the panel already tracks, gathered in
    // one place. Each item: [level err|warn, text, link]. Admin scope is the
    // whole box; everyone else sees their own domains/account. Every probe is
    // best-effort — a failing check is skipped, never fatal.
    private function attention(array $u, array $services): array {
        $items = [];
        $admin = ($u['role'] ?? '') === 'admin';
        $scope = $admin ? null : (int)$u['id'];
        try {
            foreach (DashboardModel::sslExpiring($scope) as $r) {
                $d = (int)$r['d'];
                $items[] = [$d <= 3 ? 'err' : 'warn', $d < 0 ? "SSL certificate for {$r['domain']} expired ".(-$d)." day(s) ago" : "SSL certificate for {$r['domain']} expires in $d day(s) — renewal should have happened by now", '/domains'];
            }
            foreach (DashboardModel::domExpiring($scope) as $r) {
                $d = (int)$r['d'];
                $items[] = [$d <= 7 ? 'err' : 'warn', $d < 0 ? "Domain registration for {$r['domain']} expired ".(-$d)." day(s) ago" : "Domain registration for {$r['domain']} expires in $d day(s) — renew it at your registrar", '/domains'];
            }
        } catch (Throwable $e) {}
        try {
            if ($admin) {
                $near = DashboardModel::usersNearQuota();
                foreach ($near as $r) {
                    $pct = (int)round((float)$r['disk_used_mb'] / max(1, (int)$r['disk_quota_mb']) * 100);
                    $items[] = [$pct >= 100 ? 'err' : 'warn', "Account {$r['username']} is at $pct% of its disk quota", '/accounts'];
                }
            } else {
                $r = DashboardModel::userDisk((int)$u['id']);
                $pct = ($r && (int)$r['disk_quota_mb'] > 0) ? (int)round((float)$r['disk_used_mb'] / (int)$r['disk_quota_mb'] * 100) : 0;
                // >=100% already has its own red banner above
                if ($pct >= 90 && $pct < 100) $items[] = ['warn', "Your account is at $pct% of its disk quota", '/disk'];
            }
        } catch (Throwable $e) {}
        // accounts that keep filling every PHP worker: their package needs
        // more (admin: whole box; reseller: own customers). Bot bursts don't
        // count (PhpUsage::workerHits). One journal read, cached 5 min per
        // session like probe(); key v2 = 6-field rows.
        try {
            $role = $u['role'] ?? '';
            if ($admin || $role === 'reseller') {
                $c = $_SESSION['attn_workers2'] ?? null;
                if (!is_array($c) || (time() - (int)($c['at'] ?? 0)) >= 300) {
                    $c = ['at' => time(), 'r' => PhpUsage::workerHits()];
                    $_SESSION['attn_workers2'] = $c;
                }
                $hits = $c['r'];
                if (!$admin && $hits) {
                    $mine = DashboardModel::ownedUsernames((int)$u['id']);
                    $hits = array_values(array_filter($hits, fn($h) => in_array($h[0], $mine, true)));
                }
                foreach (array_slice($hits, 0, 6) as [$who, $times, , $limit, $rt, $rd]) {
                    $items[] = ['warn', "Account $who: ".PhpUsage::workersMsg($rt, $rd, $limit).PhpUsage::botsMsg($times - $rt).($admin ? ' — see what caused it' : ' — give its package more PHP workers'), $admin ? '/admin/firewall#workers' : '/accounts?q='.rawurlencode($who)];
                }
                if (count($hits) > 6) $items[] = ['warn', (count($hits) - 6).' more account(s) keep filling their PHP workers', '/accounts'];
            }
        } catch (Throwable $e) {}
        if (!$admin) return $items;
        foreach ($services as $label => $s) {
            if (($s['state'] ?? '') === 'down') $items[] = ['err', "Service $label is not running", '/admin/services'];
        }
        try {
            $r = $this->probe('mail-queue.sh', ['summary']);
            if (($r['code'] ?? 1) === 0) {
                preg_match('/queued: (\d+)/', $r['out'] ?? '', $q); preg_match('/frozen: (\d+)/', $r['out'] ?? '', $f);
                $q = (int)($q[1] ?? 0); $f = (int)($f[1] ?? 0);
                if ($q >= 50) $items[] = ['warn', "$q messages waiting in the outgoing mail queue", '/admin/mail'];
                if ($f > 0) $items[] = ['warn', "$f frozen message(s) in the mail queue (usually dead bounces)", '/admin/mail'];
            }
        } catch (Throwable $e) {}
        try {
            $accounts = DashboardModel::accountCount();
            $s = $this->probe('backup-schedule.sh', ['status']);
            $on = ($s['code'] ?? 1) === 0 && preg_match('/^status=\d\d:\d\d$/', trim($s['out'] ?? ''));
            // restic engine writes snapshots, not backups rows — tarball-only check
            $restic = (string)(DashboardModel::setting('restic_enabled') ?? '') === '1';
            if ($accounts > 0 && $on && !$restic) {
                $h = DashboardModel::hoursSinceBackup();
                if ($h === null) $items[] = ['warn', 'No account backups have been made yet', '/backups'];
                elseif ((int)$h >= 36) $items[] = ['err', 'Last account backup was '.(int)round((int)$h / 24).' day(s) ago — the nightly run may be failing', '/backups'];
            }
        } catch (Throwable $e) {}
        return $items;
    }
    // Privileged attention probe, cached 5 min per session so the dashboard
    // (and its reloads) doesn't pay a sudo round-trip + queue scan each view.
    private function probe(string $script, array $args): array {
        $k = $script.' '.implode(' ', $args);
        $c = $_SESSION['attn_probe'][$k] ?? null;
        if (is_array($c) && (time() - (int)($c['at'] ?? 0)) < 300) return $c['r'];
        $r = ServiceManager::run($script, $args, 5);
        $_SESSION['attn_probe'][$k] = ['at' => time(), 'r' => $r];
        return $r;
    }
    // Live server snapshot for the dashboard auto-refresh (admin only).
    // /proc + disk calls only — microseconds. 3s file cache so N open
    // dashboards share one sample instead of stacking reads.
    public function live(): void {
        Auth::requireRole(['admin']);
        // storage/, not /tmp: a stale /tmp copy owned by another uid would
        // silently block the cache after the worker changes user
        $cache = __DIR__.'/../../storage/live.json';
        $st = @stat($cache);
        if ($st && (time() - $st['mtime']) < 3 && ($raw = @file_get_contents($cache)) !== false) {
            $this->json(json_decode($raw, true) ?: []);
        }
        $d = ['uptime' => '?', 'load' => '?', 'cpus' => 1, 'mem_used' => 0, 'mem_total' => 0, 'mem_pct' => 0,
              'disk_used' => 0, 'disk_total' => 0, 'disk_pct' => 0, 'procs' => [], 'version' => Helpers::panelVersion(),
              'cpu' => null, 'net' => null, 'at' => microtime(true)];
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
            // cumulative counters; the page turns two samples into CPU % and
            // network rates (jiffies: all, idle+iowait; bytes: rx, tx minus lo)
            $cl = preg_split('/\s+/', trim((string)strtok((string)@file_get_contents('/proc/stat'), "\n")));
            if (($cl[0] ?? '') === 'cpu' && count($cl) >= 8) {
                $j = array_map('intval', array_slice($cl, 1, 8));
                $d['cpu'] = [array_sum($j), $j[3] + $j[4]];
            }
            $rx = $tx = 0;
            foreach (array_slice((array)@file('/proc/net/dev', FILE_IGNORE_NEW_LINES), 2) as $line) {
                [$if, $rest] = array_pad(explode(':', $line, 2), 2, '');
                if (trim($if) === 'lo' || $rest === '') continue;
                $f = preg_split('/\s+/', trim($rest));
                $rx += (int)($f[0] ?? 0); $tx += (int)($f[8] ?? 0);
            }
            $d['net'] = [$rx, $tx];
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
        try { DashboardModel::putSetting('setup_guide', 'off'); } catch (Throwable $e) {}
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
        try { DashboardModel::dropSetting('setup_guide'); } catch (Throwable $e) {}
        try {
            $r = ServiceManager::run('cron-glue.sh', ['on']);
            if (($r['code'] ?? 1) !== 0) Helpers::log('setupShow: cron-glue on failed: '.trim($r['out'] ?? ''));
        } catch (Throwable $e) {}
        Helpers::redirect('/');
    }
}
