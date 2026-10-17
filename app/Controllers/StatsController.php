<?php
declare(strict_types=1);
class StatsController extends Controller {
    private const MAX_LINES = 100000;
    public function index(): void {
        Auth::requireLogin();
        $u = Auth::user();
        $domains = ($u['role']==='admin') ? DomainModel::all() : DomainModel::forUser((int)$u['id']);
        $names = array_column($domains, 'domain');
        $domain = strtolower(trim($_GET['domain'] ?? ''));
        if (!in_array($domain, array_map('strtolower', $names), true)) $domain = strtolower($names[0] ?? '');
        $days = min(90, max(1, (int)($_GET['days'] ?? 14)));
        $stats = null;
        if ($domain !== '' && Helpers::validDomain($domain)) $stats = $this->parse($domain, $days);
        $usage = $this->accountUsage($u);
        $this->view('stats', compact('domains','domain','days','stats','usage'));
    }
    // Per-account usage graphs (bandwidth + disk history vs quotas, with a
    // burn projection). Admin may pass ?user= to inspect a tenant; everyone
    // else sees their own account. All math in whole units, trends only
    // shown with 3+ points and positive slope (else "—", never a fake date).
    private function accountUsage(array $u): array {
        $view = $u;
        if (($u['role'] ?? '') === 'admin' && trim((string)($_GET['user'] ?? '')) !== '') {
            try {
                $r = Database::query("SELECT * FROM users WHERE username=?", [trim((string)$_GET['user'])])->fetch();
                if ($r) $view = $r;
            } catch (Throwable $e) {}
        }
        $uid = (int)$view['id'];
        $out = ['user' => $view['username'], 'bw' => [], 'disk' => [],
            'bwQuotaGb' => (int)($view['bandwidth_quota_gb'] ?? 0), 'diskQuotaMb' => (int)($view['disk_quota_mb'] ?? 0),
            'bwMonthGb' => 0.0, 'bwPace' => null, 'diskBurnDays' => null, 'diskNowMb' => 0];
        try {
            $rows = Database::query("SELECT day, SUM(bytes) b FROM bandwidth_daily WHERE user_id=? AND day >= (CURDATE() - INTERVAL 29 DAY) GROUP BY day ORDER BY day", [$uid])->fetchAll();
            foreach ($rows as $r) $out['bw'][(string)$r['day']] = (int)$r['b'];
            $drows = Database::query("SELECT day, mb FROM disk_daily WHERE user_id=? AND day >= (CURDATE() - INTERVAL 29 DAY) ORDER BY day", [$uid])->fetchAll();
            foreach ($drows as $r) $out['disk'][(string)$r['day']] = (int)$r['mb'];
            $m = Database::query("SELECT COALESCE(SUM(bytes),0) b FROM bandwidth_daily WHERE user_id=? AND day >= DATE_FORMAT(CURDATE(),'%Y-%m-01')", [$uid])->fetch();
            $out['bwMonthGb'] = round((int)($m['b'] ?? 0) / 1073741824, 2);
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
        $s = ['hits'=>0,'ips'=>[],'bytes'=>0,'bots'=>0,'status'=>['2xx'=>0,'3xx'=>0,'4xx'=>0,'5xx'=>0],
              'paths'=>[],'refs'=>[],'hours'=>array_fill(0,24,0),'uas'=>[],'daily'=>[],'notfound'=>[]];
        $f = "/var/log/karnakpanel/$domain.access.log";
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- log path from domain allowlist (user's own domains only)
        if (!is_readable($f)) return $s + ['empty' => true];
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- same allowlisted log path as above
        $size = filesize($f) ?: 0;
        // cap I/O: last 8MB covers ~100k typical lines
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- same allowlisted log path as above
        $tail = file_get_contents($f, false, null, max(0, $size - 8388608));
        $lines = explode("\n", (string)$tail);
        if (count($lines) > self::MAX_LINES) $lines = array_slice($lines, -self::MAX_LINES);
        $cutoff = strtotime("-$days days");
        foreach ($lines as $line) {
            if (!preg_match('/^\x27?(\S+) \S+ \S+ \[([^\]]+)\] "([A-Z]+) (\S+)[^"]*" (\d{3}) (\S+)(?: "([^"]*)" "([^"]*)")?/', $line, $m)) continue;
            $dt = DateTime::createFromFormat('d/M/Y:H:i:s O', $m[2]);
            $t = $dt ? $dt->getTimestamp() : 0;
            if (!$t || $t < $cutoff) continue;
            $s['hits']++;
            $s['ips'][$m[1]] = ($s['ips'][$m[1]] ?? 0) + 1;
            $s['bytes'] += is_numeric($m[6]) ? (int)$m[6] : 0;
            $s['status'][substr($m[5],0,1).'xx']++;
            $s['hours'][(int)date('G', $t)]++;
            $s['daily'][date('Y-m-d', $t)] = ($s['daily'][date('Y-m-d', $t)] ?? 0) + 1;
            if ($m[5] === '404') {
                $nf = strtolower(explode('?', $m[4])[0]);
                $s['notfound'][$nf] = ($s['notfound'][$nf] ?? 0) + 1;
            }
            $path = strtolower(explode('?', $m[4])[0]);
            $s['paths'][$path] = ($s['paths'][$path] ?? 0) + 1;
            $ua = $m[8] ?? '';
            if ($ua !== '' && $ua !== '-') {
                if (preg_match('/bot|crawl|spider|slurp|mediapartners|baidu|yandex|semrush|ahrefs|mj12|dotbot|gptbot|ccbot|anthropic/i', $ua)) $s['bots']++;
                $short = substr($ua, 0, 60);
                $s['uas'][$short] = ($s['uas'][$short] ?? 0) + 1;
            }
            $ref = $m[7] ?? '';
            if ($ref !== '' && $ref !== '-') {
                $host = parse_url($ref, PHP_URL_HOST) ?: $ref;
                $s['refs'][$host] = ($s['refs'][$host] ?? 0) + 1;
            }
        }
        arsort($s['paths']); arsort($s['refs']); arsort($s['uas']);
        $s['paths'] = array_slice($s['paths'], 0, 15, true);
        $s['refs'] = array_slice($s['refs'], 0, 10, true);
        $s['uas'] = array_slice($s['uas'], 0, 10, true);
        arsort($s['notfound']);
        $s['notfound'] = array_slice($s['notfound'], 0, 10, true);
        arsort($s['ips']);
        $s['topips'] = array_slice($s['ips'], 0, 10, true);
        $s['uniq'] = count($s['ips']);
        unset($s['ips']);
        ksort($s['daily']);
        return $s;
    }
}
