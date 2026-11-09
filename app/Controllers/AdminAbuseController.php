<?php
declare(strict_types=1);

class AdminAbuseController extends Controller {

    public const ABUSE_MEM = 25;    // % of RAM per user

    // Abuse board: live per-user CPU/RAM/processes (proc-live.sh, polled) +
    // per-customer quota/send context. Alerts stay with the 5-min
    // user-abuse.sh; its thresholds are mirrored here so "hot" means the same.
    public const ABUSE_CPU = 150;   // % of one core, summed per user
    // Nightly malware scan schedule: enabled flag + hour (script runs hourly
    // and fires only in the chosen slot). Creates the settings table on old
    // installs like alertEmail() does.
    public function malware(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/services'); }
        $on = isset($_POST['malware_on']) ? '1' : '0';
        $hour = (int)($_POST['malware_hour'] ?? 3);
        if ($hour < 0 || $hour > 23) { Helpers::flash('err','Hour must be 0-23'); Helpers::redirect('/admin/services'); }
        try {
            Settings::putMany(['malware_enabled' => $on, 'malware_hour' => (string)$hour]);
            Helpers::flash('ok', $on === '1' ? "Malware scan on, daily ~$hour:30" : 'Malware scan disabled');
        } catch (Throwable $e) {
            try {
                Settings::ensureTable();
                Settings::putMany(['malware_enabled' => $on, 'malware_hour' => (string)$hour]);
                Helpers::flash('ok','Malware scan schedule saved');
            } catch (Throwable $e2) { Helpers::flash('err','Save failed: '.$e2->getMessage()); }
        }
        Helpers::redirect('/admin/services');
    }

    // Migrate hub: cPanel import form posts to the existing accounts/import
    // endpoint (kept stable); transfer UI + direct-admin import plug in here.
    public function migrate(): void {
        Auth::requireRole(['admin']);
        $this->view('admin_migrate', ['pairings' => TransferController::pairings()]);
    }

    public function abuse(): void {
        Auth::requireRole(['admin']);
        $users = [];
        // quotas table: fullest disk first (cached totals from quota-enforce),
        // 25 per page — the per-row mail/bandwidth reads were ~1000 calls at
        // 1000 accounts
        $q = substr(trim((string)($_GET['q'] ?? '')), 0, 64);
        $total = 0;
        try { $total = User::abuseCount($q); } catch (Throwable $e) {}
        $pgr = Helpers::paginate($total, max(1, (int)($_GET['page'] ?? 1)));
        try {
            $rows = User::abusePage($q, (int)$pgr['limit'], (int)$pgr['offset']);
            foreach ($rows as $x) {
                try { $x['disk_used_mb'] = Quota::usage($x)['used_mb']; } catch (Throwable $t) { $x['disk_used_mb'] = 0; }
                $bw = Quota::bandwidthMonthBytes($x);
                $x['bw_month_mb'] = $bw === null ? null : round($bw / 1048576, 1);
                $x['sent_today'] = null;
                try {
                    $r = ServiceManager::run('mail-sent.sh', [$x['username']]);
                    if (($r['code'] ?? 1) === 0 && preg_match('/^\d+$/', trim($r['out']))) $x['sent_today'] = (int)trim($r['out']);
                } catch (Throwable $t) {}
                $users[] = $x;
            }
        } catch (Throwable $e) {}
        $pg = $pgr['pg']; $pages = $pgr['pages']; $total = $pgr['total'];
        $this->view('admin_abuse', compact('users', 'q', 'pg', 'pages', 'total'));
    }

    // proc-live.sh snap -> board rows. $acct: username => [role, status].
    // $cmds: "user/name" => [command, workdir] of panel daemons (app_daemons).
    public static function abuseView(array $raw, array $acct, array $cmds = []): array {
        $h = (array)($raw['host'] ?? []);
        $cpus = max(1, (int)($h['cpus'] ?? 1));
        $memt = max(1, (int)($h['mem_total'] ?? 1));
        $cust = array_flip(array_map('strval', (array)($raw['customers'] ?? [])));
        $alerts = (array)($raw['alerts'] ?? []);
        $users = [];
        foreach ((array)($raw['users'] ?? []) as $name => $u) {
            $name = (string)$name;
            if (!preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $name)) continue;
            $cpu = (float)($u['cpu'] ?? 0); $rss = (int)($u['rss'] ?? 0); $n = (int)($u['procs'] ?? 0);
            $mem = round($rss * 100 / $memt, 1);
            $isC = isset($cust[$name]) && (int)($u['uid'] ?? 0) >= 1000;
            $why = [];
            if ($cpu > self::ABUSE_CPU) $why[] = 'CPU over '.self::ABUSE_CPU.'%';
            elseif ($cpus > 1 && $cpu > 75 * $cpus) $why[] = 'over 75% of the server';
            if ($mem > self::ABUSE_MEM) $why[] = 'RAM over '.self::ABUSE_MEM.'%';
            if ($isC && $n > 100) $why[] = $n.' processes';
            $al = trim((string)($alerts[$name] ?? ''));
            $users[] = [
                'name' => $name, 'customer' => $isC, 'role' => $acct[$name][0] ?? '', 'status' => $acct[$name][1] ?? '',
                'cpu' => $cpu, 'box' => round($cpu / $cpus, 1), 'rss' => $rss, 'mem' => $mem, 'procs' => $n,
                'hot' => $isC && $why !== [], 'why' => implode(', ', $why),
                'alert' => $al === 'alerted' ? 'alerted' : ($al === 'once' ? 'watch' : ''),
            ];
        }
        // panel accounts with nothing running still get a (quiet) row
        $seen = array_flip(array_column($users, 'name'));
        foreach (array_keys($cust) as $name) {
            if (isset($seen[$name]) || !preg_match('/^[a-z][a-z0-9_]{2,31}$/', (string)$name)) continue;
            $users[] = ['name' => (string)$name, 'customer' => true, 'role' => $acct[$name][0] ?? '', 'status' => $acct[$name][1] ?? '',
                'cpu' => 0.0, 'box' => 0.0, 'rss' => 0, 'mem' => 0.0, 'procs' => 0, 'hot' => false, 'why' => '', 'alert' => ''];
        }
        usort($users, fn($a, $b) => [$b['customer'], $b['cpu'], $b['rss']] <=> [$a['customer'], $a['cpu'], $a['rss']]);
        $procs = [];
        foreach ((array)($raw['procs'] ?? []) as $p) {
            $user = (string)($p['user'] ?? '');
            $cpu = (float)($p['cpu'] ?? 0); $age = (int)($p['age'] ?? 0); $st = (string)($p['state'] ?? '');
            $flags = [];
            if ($cpu >= 90) $flags[] = 'pegging a core';
            if ($age >= 3600 && $cpu >= 50) $flags[] = 'busy for '.intdiv($age, 3600).' h';
            if ($st === 'Z') $flags[] = 'zombie';
            if ($st === 'D') $flags[] = 'stuck on disk I/O';
            $isC = isset($cust[$user]) && (int)($p['uid'] ?? 0) >= 1000;
            $procs[] = [
                'pid' => (int)($p['pid'] ?? 0), 'start' => (string)(int)($p['start'] ?? 0), 'user' => $user, 'customer' => $isC,
                'cpu' => $cpu, 'rss' => (int)($p['rss'] ?? 0), 'mem' => round((int)($p['rss'] ?? 0) * 100 / $memt, 1),
                'state' => $st, 'age' => $age, 'threads' => (int)($p['threads'] ?? 1),
                'comm' => (string)($p['comm'] ?? ''), 'args' => (string)($p['args'] ?? ''),
                'kind' => (string)($p['kind'] ?? ''), 'flags' => $flags, 'hot' => $isC && ($cpu >= 90 || ($age >= 3600 && $cpu >= 50)),
            ];
        }
        $mysql = [];
        foreach ((array)($raw['mysql'] ?? []) as $q) {
            $mysql[] = ['user' => (string)($q['user'] ?? ''), 'dbuser' => (string)($q['dbuser'] ?? ''), 'db' => (string)($q['db'] ?? ''),
                'time' => (int)($q['time'] ?? 0), 'state' => (string)($q['state'] ?? ''), 'query' => (string)($q['query'] ?? ''),
                'hot' => (int)($q['time'] ?? 0) >= 30];
        }
        [$flags, $daemons] = self::abuseCronDaemon($raw, $cust, $cmds, $acct);
        $load = array_map('floatval', (array)($h['load'] ?? [0, 0, 0]));
        return [
            'host' => [
                'load' => $load, 'cpus' => $cpus, 'busy' => round((float)($h['busy'] ?? 0) / $cpus, 1),
                'mem_used' => $memt - (int)($h['mem_avail'] ?? 0), 'mem_total' => $memt,
                'swap_used' => (int)($h['swap_total'] ?? 0) - (int)($h['swap_free'] ?? 0), 'swap_total' => (int)($h['swap_total'] ?? 0),
                'procs' => (int)($h['procs'] ?? 0), 'uptime' => (int)($h['uptime'] ?? 0),
            ],
            'users' => $users, 'procs' => $procs, 'mysql' => $mysql, 'flags' => $flags, 'daemons' => $daemons,
            'limits' => ['cpu' => self::ABUSE_CPU, 'mem' => self::ABUSE_MEM],
        ];
    }

    // Cron + daemon warnings: hot = flashing red (act now), warn = amber.
    // Sources: cgroup samples (now), app-status (now), abuse-events.log (24 h).
    private static function abuseCronDaemon(array $raw, array $cust, array $cmds, array $acct): array {
        $now = (int)($raw['now'] ?? time());
        $flags = [];
        $flag = function (string $user, bool $hot, string $what, int $when = 0) use (&$flags, $cust) {
            if (isset($cust[$user])) $flags[] = ['user' => $user, 'level' => $hot ? 'hot' : 'warn', 'what' => $what, 'when' => $when];
        };
        $units = [];
        foreach ((array)($raw['groups'] ?? []) as $g) {
            $user = (string)($g['user'] ?? ''); $kind = (string)($g['kind'] ?? '');
            if (!preg_match('/^[a-z][a-z0-9_]{2,31}$/', $user)) continue;
            $cpu = (float)($g['cpu'] ?? 0); $cap = isset($g['cpu_cap']) ? (float)$g['cpu_cap'] : 0.0;
            $mem = (int)($g['mem'] ?? 0); $mmax = isset($g['mem_max']) ? (int)$g['mem_max'] : 0;
            if ($kind === 'unit') { $units[$user.'/'.(string)($g['name'] ?? '')] = $g; continue; }
            $what = $kind === 'cron' ? 'Cron jobs' : 'Daemons';
            if ($cap > 0 && $cpu >= 0.9 * $cap) $flag($user, true, "$what at {$cpu}% CPU — their limit is {$cap}%", $now);
            if ($mmax > 0 && $mem >= 0.9 * $mmax) $flag($user, true, "$what using ".round($mem / 1048576).' MB of their '.round($mmax / 1048576).' MB RAM limit', $now);
        }
        // events: [user][kind] => [last hour, last 24 h, newest ts, newest detail]
        $ev = [];
        foreach ((array)($raw['events'] ?? []) as $e) {
            $user = (string)($e['user'] ?? ''); $k = (string)($e['kind'] ?? ''); $ts = (int)($e['ts'] ?? 0);
            if ($ts < $now - 86400) continue;
            $x = $ev[$user][$k] ?? [0, 0, 0, ''];
            // OOM lines carry a count ("5 killed over the memory limit": one line per check)
            $w = str_ends_with($k, '-oom') && preg_match('/^(\d{1,5}) /', (string)($e['detail'] ?? ''), $m) ? max(1, (int)$m[1]) : 1;
            if ($ts >= $now - 3600) $x[0] += $w;
            $x[1] += $w;
            if ($ts >= $x[2]) { $x[2] = $ts; $x[3] = (string)($e['detail'] ?? ''); }
            $ev[$user][$k] = $x;
        }
        foreach ($ev as $user => $ks) {
            $user = (string)$user;
            foreach (['cron-oom' => 'Cron job', 'daemon-oom' => 'Daemon'] as $k => $w) {
                if (!isset($ks[$k])) continue;
                [$hr, $day, $ts] = $ks[$k];
                if ($hr >= 3) $flag($user, true, "$w killed $hr times in the last hour for running out of memory", $ts);
                else $flag($user, false, "$w killed $day time(s) in 24 h for running out of memory", $ts);
            }
            if (isset($ks['cron-timeout'])) {
                [, $day, $ts, $d] = $ks['cron-timeout'];
                $flag($user, $day >= 2, "$day cron run(s) stopped at the 6 h time limit in 24 h".($d !== '' ? " (latest: $d)" : ''), $ts);
            }
            if (isset($ks['cron-killed'])) { [, $day, $ts, $d] = $ks['cron-killed']; $flag($user, false, "$day cron run(s) killed in 24 h (latest: $d)", $ts); }
            if (isset($ks['cron-skip']) && $ks['cron-skip'][0] >= 10) $flag($user, false, $ks['cron-skip'][0].' cron runs skipped in the last hour — the previous run was still going', $ks['cron-skip'][2]);
            if (isset($ks['daemon-refused'])) { [, $day, $ts, $d] = $ks['daemon-refused']; $flag($user, true, "Tried to run a blocked program as a daemon ($d)".($day > 1 ? ", $day times" : ''), $ts); }
            if (isset($ks['daemon-crashloop'])) { [, $day, $ts, $d] = $ks['daemon-crashloop']; $flag($user, false, "Daemon crash loop(s) in 24 h: $day (latest: $d)", $ts); }
        }
        // an admin's own spool crontab (prod: billing) is theirs to keep: amber
        foreach ((array)($raw['outside'] ?? []) as $user) $flag((string)$user, ($acct[$user][0] ?? '') !== 'admin', 'Has a crontab made outside the panel (no limits, not on the Cron jobs page)', $now);
        $daemons = [];
        foreach ((array)($raw['daemons'] ?? []) as $d) {
            $user = (string)($d['user'] ?? ''); $name = (string)($d['name'] ?? '');
            if (!preg_match('/^[a-z][a-z0-9_]{2,31}$/', $user) || !preg_match('/^[a-z0-9_-]{1,32}$/', $name)) continue;
            $st = (string)($d['state'] ?? ''); $looped = $st === 'failed' && ($d['result'] ?? '') === 'start-limit-hit';
            $restarts = (int)($d['restarts'] ?? 0);
            if ($looped) $flag($user, true, "Daemon $name is crash-looping (5 starts in 5 min) — stopped", $now);
            elseif ($restarts >= 10) $flag($user, false, "Daemon $name restarted $restarts times since it was started", $now);
            $g = $units[$user.'/'.$name] ?? [];
            $daemons[] = [
                'user' => $user, 'name' => $name, 'state' => $st, 'looped' => $looped, 'enabled' => !empty($d['enabled']),
                'since' => (int)($d['since'] ?? 0), 'restarts' => $restarts,
                'cpu' => (float)($g['cpu'] ?? 0), 'cpu_cap' => isset($g['cpu_cap']) ? (float)$g['cpu_cap'] : null,
                'mem' => (int)($g['mem'] ?? 0), 'mem_max' => isset($g['mem_max']) ? (int)$g['mem_max'] : null,
                'command' => (string)($cmds[$user.'/'.$name][0] ?? ''), 'customer' => isset($cust[$user]),
            ];
        }
        usort($flags, fn($a, $b) => [$b['level'] === 'hot', $b['when']] <=> [$a['level'] === 'hot', $a['when']]);
        usort($daemons, fn($a, $b) => [$b['looped'], $b['cpu'], $b['mem']] <=> [$a['looped'], $a['cpu'], $a['mem']]);
        return [$flags, $daemons];
    }

    public function abuseLive(): void {
        Auth::requireRole(['admin']);
        header('Cache-Control: no-store');
        $r = ServiceManager::run('proc-live.sh', ['snap']);
        $raw = json_decode(trim($r['out'] ?? ''), true);
        if (($r['code'] ?? 1) !== 0 || !is_array($raw)) $this->json(['error' => 'live view unavailable: '.substr(trim($r['out'] ?? ''), 0, 200)]);
        $acct = [];
        try { $acct = User::roster(); } catch (Throwable $e) {}
        $cmds = [];
        try {
            $cmds = DaemonModel::index();
        } catch (Throwable $e) {}
        $out = self::abuseView($raw, $acct, $cmds);
        $out['at'] = (new DateTime('now', Helpers::hostTz()))->format('H:i:s');
        $this->json($out);
    }

    // Kill one customer process (pid + start time, so a reused PID is never
    // hit) or all of one customer's. The script re-checks ownership.
    public function abuseKill(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) $this->json(['ok' => false, 'msg' => 'Session expired — reload the page'], 400);
        $op = (string)($_POST['op'] ?? '');
        if ($op === 'kill' && preg_match('/^\d{1,9}$/', (string)($_POST['pid'] ?? '')) && preg_match('/^\d{1,20}$/', (string)($_POST['start'] ?? ''))) {
            $args = ['kill', (string)$_POST['pid'], (string)$_POST['start']];
        } elseif ($op === 'killuser' && Helpers::validUsername((string)($_POST['user'] ?? ''))) {
            $args = ['killuser', (string)$_POST['user']];
        } else $this->json(['ok' => false, 'msg' => 'Bad request'], 400);
        $r = ServiceManager::run('proc-live.sh', $args, 20);
        $msg = trim((string)($r['out'] ?? ''));
        if (($r['code'] ?? 1) === 0) Helpers::log("abuse board: $msg by ".Auth::user()['username']); // nosemgrep -- script output: pid/username only, no args
        $this->json(['ok' => ($r['code'] ?? 1) === 0, 'msg' => $msg]);
    }

    // Stop / start / restart one customer daemon from the Abuse board.
    public function abuseDaemon(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) $this->json(['ok' => false, 'msg' => 'Session expired — reload the page'], 400);
        $op = (string)($_POST['op'] ?? ''); $user = (string)($_POST['user'] ?? ''); $name = (string)($_POST['name'] ?? '');
        if (!in_array($op, ['stop', 'start', 'restart'], true) || !Helpers::validUsername($user) || !preg_match('/^[a-z0-9_-]{1,32}$/', $name)) $this->json(['ok' => false, 'msg' => 'Bad request'], 400);
        $r = ServiceManager::run('app-daemon.sh', [$op, $user, $name], 30);
        $msg = trim((string)($r['out'] ?? ''));
        if (($r['code'] ?? 1) === 0) Helpers::log("abuse board: daemon $user/$name $op by ".Auth::user()['username']); // nosemgrep -- validated user/name/op only
        $this->json(['ok' => ($r['code'] ?? 1) === 0, 'msg' => $msg]);
    }
}
