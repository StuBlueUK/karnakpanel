<?php
declare(strict_types=1);
class StatsController extends Controller {
    private const MAX_LINES = 100000;
    public const RANGES = [1, 7, 14, 30, 90];
    public function index(): void {
        Auth::requireLogin();
        $u = Auth::user();
        $domains = ($u['role']==='admin') ? DomainModel::all() : DomainModel::forUser((int)$u['id']);
        $names = array_column($domains, 'domain');
        $domain = strtolower(trim($_GET['domain'] ?? ''));
        if (!in_array($domain, array_map('strtolower', $names), true)) $domain = strtolower($names[0] ?? '');
        $days = (int)($_GET['days'] ?? 14);
        if (!in_array($days, self::RANGES, true)) $days = 14;
        $stats = null;
        if ($domain !== '' && Helpers::validDomain($domain)) $stats = $this->parse($domain, $days);
        $owner = null;
        foreach ($domains as $d) if (strtolower((string)$d['domain']) === $domain) $owner = (int)$d['user_id'];
        $usage = $this->accountUsage($u, $owner);
        $isAdmin = $u['role'] === 'admin';
        $this->view('stats', compact('domains','domain','days','stats','usage','isAdmin'));
    }
    // Per-account usage graphs (bandwidth + disk history vs quotas, with a
    // burn projection). Admin may pass ?user= to inspect a tenant; everyone
    // else sees their own account. All math in whole units, trends only
    // shown with 3+ points and positive slope (else "—", never a fake date).
    private function accountUsage(array $u, ?int $ownerId): array {
        $view = $u;
        if (($u['role'] ?? '') === 'admin') {
            $want = trim((string)($_GET['user'] ?? ''));
            try {
                $r = $want !== '' ? Database::query("SELECT * FROM users WHERE username=?", [$want])->fetch()
                    : ($ownerId ? Database::query("SELECT * FROM users WHERE id=?", [$ownerId])->fetch() : null);
                if ($r) $view = $r;
            } catch (Throwable $e) {}
        }
        $uid = (int)$view['id'];
        $out = ['user' => $view['username'], 'bw' => [], 'disk' => [],
            'bwQuotaGb' => (int)($view['bandwidth_quota_gb'] ?? 0), 'diskQuotaMb' => (int)($view['disk_quota_mb'] ?? 0),
            'bwMonthGb' => 0.0, 'bwPace' => null, 'diskBurnDays' => null, 'diskNowMb' => 0, 'bwDomains' => []];
        try {
            $rows = Database::query("SELECT day, SUM(bytes) b FROM bandwidth_daily WHERE user_id=? AND day >= (CURDATE() - INTERVAL 29 DAY) GROUP BY day ORDER BY day", [$uid])->fetchAll();
            foreach ($rows as $r) $out['bw'][(string)$r['day']] = (int)$r['b'];
            $drows = Database::query("SELECT day, mb FROM disk_daily WHERE user_id=? AND day >= (CURDATE() - INTERVAL 29 DAY) ORDER BY day", [$uid])->fetchAll();
            foreach ($drows as $r) $out['disk'][(string)$r['day']] = (int)$r['mb'];
            $m = Database::query("SELECT COALESCE(SUM(bytes),0) b FROM bandwidth_daily WHERE user_id=? AND day >= DATE_FORMAT(CURDATE(),'%Y-%m-01')", [$uid])->fetch();
            $out['bwMonthGb'] = round((int)($m['b'] ?? 0) / 1073741824, 2);
            $dr = Database::query("SELECT domain, SUM(bytes) b FROM bandwidth_daily WHERE user_id=? AND day >= DATE_FORMAT(CURDATE(),'%Y-%m-01') GROUP BY domain ORDER BY b DESC LIMIT 12", [$uid])->fetchAll();
            foreach ($dr as $r) $out['bwDomains'][(string)$r['domain']] = (int)$r['b'];
            $elapsed = max(1, (int)date('j'));
            $dim = (int)date('t');
            if ($out['bwQuotaGb'] > 0) $out['bwPace'] = round($out['bwMonthGb'] / $out['bwQuotaGb'] * 100 * $dim / $elapsed, 1);
            $pts = array_values($out['disk']);
            $n = count($pts);
            if ($n >= 3) {
                $slope = ($pts[$n-1] - $pts[0]) / ($n - 1); // MB/day, endpoints (robust enough for a hint)
                if ($slope > 0 && $out['diskQuotaMb'] > 0) {
                    $left = $out['diskQuotaMb'] - end($pts);
                    if ($left > 0) $out['diskBurnDays'] = (int)floor($left / $slope);
                }
            }
            $out['diskNowMb'] = $n ? end($pts) : (int)($view['disk_used_mb'] ?? 0);
        } catch (Throwable $e) {}
        return $out;
    }
    private function parse(string $domain, int $days): array {
        $f = "/var/log/karnakpanel/$domain.access.log";
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- log path from domain allowlist (user's own domains only)
        if (!is_readable($f)) return ['empty' => true];
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- same allowlisted log path as above
        $size = filesize($f) ?: 0;
        // cap I/O: last 8MB covers ~100k typical lines
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- same allowlisted log path as above
        $tail = file_get_contents($f, false, null, max(0, $size - 8388608));
        $lines = explode("\n", (string)$tail);
        if ($size > 8388608) array_shift($lines); // first line is cut mid-way
        if (count($lines) > self::MAX_LINES) $lines = array_slice($lines, -self::MAX_LINES);
        $s = AccessStats::parse($lines, $days, time(), $domain);
        $s['truncated'] = $size > 8388608 || count($lines) >= self::MAX_LINES;
        $s['empty'] = $s['lines'] === 0;
        return $s;
    }
}
