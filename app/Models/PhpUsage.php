<?php
declare(strict_types=1);
// PHP resource use per account (its own FPM master's cgroup), many accounts
// in ONE privileged call: php-usage.sh reads /sys directly. Counters run
// since the account's PHP last started (boot or first request after it), so
// "killed at the RAM cap" means since then. Accounts missing from the result
// render as —.
class PhpUsage {
    /** @param string[] $users  @return array<string,array> */
    public static function many(array $users): array {
        $users = array_values(array_unique(array_filter($users, fn($u) => is_string($u) && Helpers::validUsername($u))));
        if (!$users) return [];
        $out = [];
        try {
            $r = ServiceManager::run('php-usage.sh', $users, 20);
            if (($r['code'] ?? 1) !== 0) return [];
            $n = fn($v) => preg_match('/^\d+$/', $v) ? (int)$v : null;
            foreach (explode("\n", (string)($r['out'] ?? '')) as $line) {
                $p = explode("\t", trim($line));
                // 16 columns; 11/14 = scripts older than the panel mid-update
                if (!in_array(count($p), [11, 14, 16], true) || !in_array($p[0], $users, true)) continue;
                $x = ['state' => preg_match('/^[a-z]+$/', $p[1]) ? $p[1] : 'unknown', 'ram' => $n($p[2]), 'cap' => $n($p[3]),
                    'cpu' => $n($p[4]), 'throttled' => $n($p[6]), 'oom' => $n($p[7]), 'high' => $n($p[8]),
                    'procmax' => $n($p[9]), 'since' => $n($p[10]),
                    'wfull' => $n($p[11] ?? '0') ?? 0, 'wdays' => $n($p[12] ?? '0') ?? 0, 'wlimit' => $n($p[13] ?? '-')];
                // without bot bursts (older scripts: every event counts)
                $x['wreal'] = $n($p[14] ?? '') ?? $x['wfull'];
                $x['wrdays'] = $n($p[15] ?? '') ?? $x['wdays'];
                $x['pct'] = $x['ram'] !== null && $x['cap'] ? min(100, (int)round(100 * $x['ram'] / $x['cap'])) : 0;
                $x['issues'] = self::issues($x);
                $out[$p[0]] = $x;
            }
        } catch (Throwable $e) {}
        return $out;
    }

    // every PHP worker busy (visitors queued) on 2+ days of the last 7, or 5+
    // times: a pattern, not one traffic spike — the package needs more workers
    public static function keepsFillingWorkers(int $times, int $days): bool {
        return $days >= 2 || $times >= 5;
    }
    public static function workersMsg(int $times, int $days, ?int $limit): string {
        return 'all '.($limit ? $limit.' ' : '').'PHP workers busy '.$times.' time(s) on '.$days.' of the last 7 days';
    }
    // bot bursts/warm-ups left out of a count: say so, so the numbers add up
    public static function botsMsg(int $bots): string {
        return $bots > 0 ? ' (+'.$bots.' bot burst/warm-up event'.($bots === 1 ? '' : 's').' not counted)' : '';
    }

    /** Accounts that keep filling their PHP workers, whole server, busiest
     *  first: [[user, times, days, limit|null, real times, real days], …].
     *  "Real" leaves out bot bursts (php-usage.sh: one network sent most of
     *  the requests) and warm-ups (PHP had just started from idle) — more
     *  workers help neither. $real:
     *  only accounts whose REAL events make a pattern (dashboard); false: any
     *  account whose events do, bots included (Firewall page shows them all).
     *  @return array<int,array{0:string,1:int,2:int,3:?int,4:int,5:int}> */
    public static function workerHits(int $timeout = 15, bool $real = true): array {
        $out = [];
        try {
            $r = ServiceManager::run('php-usage.sh', ['--worker-hits'], $timeout);
            if (($r['code'] ?? 1) !== 0) return [];
            foreach (explode("\n", (string)($r['out'] ?? '')) as $line) {
                $p = explode("\t", trim($line));
                // 6 columns; 4 = script older than the panel mid-update (no split)
                if (!in_array(count($p), [4, 6], true) || !Helpers::validUsername($p[0]) || !ctype_digit($p[1]) || !ctype_digit($p[2])) continue;
                $rt = isset($p[5]) && ctype_digit($p[4]) && ctype_digit($p[5]) ? [(int)$p[4], (int)$p[5]] : [(int)$p[1], (int)$p[2]];
                if (!($real ? self::keepsFillingWorkers($rt[0], $rt[1]) : self::keepsFillingWorkers((int)$p[1], (int)$p[2]))) continue;
                $out[] = [$p[0], (int)$p[1], (int)$p[2], ctype_digit($p[3]) ? (int)$p[3] : null, $rt[0], $rt[1]];
            }
        } catch (Throwable $e) {}
        usort($out, fn($a, $b) => [$b[5], $b[4], $b[2], $b[1]] <=> [$a[5], $a[4], $a[2], $a[1]]);
        return $out;
    }

    /** Who was hitting an account's sites each time its workers were all busy
     *  (last 7 days, newest first): [[at, limit|null, age|null (s since PHP
     *  started), reqs, r503, paths, bot (one network sent most of it),
     *  warm (PHP had just started; not a bot),
     *  ips => [[ip, reqs, r503, paths, top, ua]], tops => [[reqs, path]]], …].
     *  Paths/agents are visitor text: escape.
     *  @return array<int,array> */
    public static function workerCause(string $user, int $timeout = 20): array {
        if (!Helpers::validUsername($user)) return [];
        $ev = [];
        try {
            $r = ServiceManager::run('php-usage.sh', ['--worker-cause', $user], $timeout);
            if (($r['code'] ?? 1) !== 0) return [];
            $d = fn($v) => ctype_digit($v) ? (int)$v : null;
            foreach (explode("\n", (string)($r['out'] ?? '')) as $line) {
                $p = explode("\t", rtrim($line, "\r\n"));
                if (!isset($p[1]) || !ctype_digit($p[1])) continue;
                $t = (int)$p[1];
                if ($p[0] === 'ev' && in_array(count($p), [7, 8], true)) {
                    $ev[$t] = ['at' => $t, 'limit' => $d($p[2]), 'age' => $d($p[3]), 'reqs' => (int)$p[4], 'r503' => (int)$p[5], 'paths' => (int)$p[6],
                        'bot' => ($p[7] ?? '0') === '1', 'warm' => ($p[7] ?? '0') === '2', 'ips' => [], 'tops' => []];
                } elseif ($p[0] === 'ip' && count($p) === 8 && isset($ev[$t]) && filter_var($p[2], FILTER_VALIDATE_IP)) {
                    $ev[$t]['ips'][] = ['ip' => $p[2], 'reqs' => (int)$p[3], 'r503' => (int)$p[4], 'paths' => (int)$p[5], 'top' => $p[6], 'ua' => $p[7]];
                } elseif ($p[0] === 'path' && count($p) === 4 && isset($ev[$t])) {
                    $ev[$t]['tops'][] = [(int)$p[2], $p[3]];
                }
            }
        } catch (Throwable $e) {}
        krsort($ev);
        return array_values($ev);
    }

    // the limits an account actually ran into: what says "upgrade the plan".
    // [level, message, short label?] — the accounts table shows the short
    // label (pills don't wrap) with the message as its tooltip
    private static function issues(array $x): array {
        $i = [];
        // bot bursts/warm-ups left out: a bigger plan helps neither
        if (self::keepsFillingWorkers($x['wreal'], $x['wrdays'])) $i[] = ['warn', self::workersMsg($x['wreal'], $x['wrdays'], $x['wlimit']).self::botsMsg($x['wfull'] - $x['wreal']).' — needs more workers', 'workers full '.$x['wreal'].'× / '.$x['wrdays'].'d'];
        if ($x['oom']) $i[] = ['err', $x['oom'].' request(s) stopped at the RAM cap'];
        if ($x['procmax']) $i[] = ['warn', 'hit the process limit '.$x['procmax'].' time(s)'];
        if ($x['high']) $i[] = ['warn', 'slowed near the RAM cap'];
        // CPU: a few seconds of throttling is a burst; a minute or more is a pattern
        if (($x['throttled'] ?? 0) >= 60) $i[] = ['warn', 'slowed by the CPU cap for '.self::dur((int)$x['throttled'])];
        if ($x['state'] === 'failed') $i[] = ['err', 'PHP failed to start (retrying)'];
        return $i;
    }

    public static function dur(int $s): string {
        if ($s < 3600) return max(1, intdiv($s, 60)).' min';
        if ($s < 86400) return round($s / 3600, 1).' h';
        return round($s / 86400, 1).' days';
    }
}
