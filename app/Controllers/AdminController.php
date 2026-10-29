<?php
declare(strict_types=1);
class AdminController extends Controller {
    public function services(): void {
        Auth::requireRole(['admin']);
        $services = ServiceManager::details();
        $up = (float)(explode(' ', (string)@file_get_contents('/proc/uptime'))[0] ?? 0);
        $uptime = ServiceManager::ago((int)$up);
        $alertEmail = '';
        try { $alertEmail = (string)(Database::query("SELECT value FROM settings WHERE name='alert_email'")->fetch()['value'] ?? ''); } catch (Throwable $e) {}
        $panelDomain = '';
        try { $panelDomain = (string)(Database::query("SELECT value FROM settings WHERE name='panel_domain'")->fetch()['value'] ?? ''); } catch (Throwable $e) {}
        // watchdog liveness: stale heartbeat = cron (or the box) is in trouble
        $watchdogAge = null; $heartbeat = '';
        try {
            $r = ServiceManager::run('watchdog-status.sh', []);
            if (($r['code'] ?? 1) === 0 && preg_match('/^age=(\d+)$/', trim($r['out'] ?? ''), $m)) $watchdogAge = (int)$m[1];
        } catch (Throwable $e) {}
        try { $heartbeat = (string)(Database::query("SELECT value FROM settings WHERE name='watchdog_heartbeat'")->fetch()['value'] ?? ''); } catch (Throwable $e) {}
        $malwareOn = '1'; $malwareHour = '3';
        try {
            $v = Database::query("SELECT value FROM settings WHERE name='malware_enabled'")->fetch()['value'] ?? null;
            if ($v !== null) $malwareOn = $v;
            $h = Database::query("SELECT value FROM settings WHERE name='malware_hour'")->fetch()['value'] ?? null;
            if ($h !== null && preg_match('/^[0-9]{1,2}$/', $h)) $malwareHour = (string)(int)$h;
        } catch (Throwable $e) {}
        $this->view('admin_services', compact('services','uptime','alertEmail','panelDomain','malwareOn','malwareHour','watchdogAge','heartbeat') + ['ssldoms' => Database::query("SELECT id,domain FROM domains WHERE ssl_enabled=1 ORDER BY domain")->fetchAll()]);
    }
    // Panel host (this box's own domain, e.g. panel.example.com). Powers the
    // setup-checklist glue step: the nightly dns-glue-check probes glue for
    // the panel's nameservers only once it knows which domain is this box's.
    public function panelDomain(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/services'); }
        $dom = strtolower(trim((string)($_POST['panel_domain'] ?? '')));
        try {
            if ($dom === '') {
                Database::query("DELETE FROM settings WHERE name='panel_domain'");
                Helpers::flash('ok','Panel host cleared — the checklist glue step stays TODO until you set it');
            } else {
                if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $dom)) {
                    Helpers::flash('err','Give a real domain (letters, digits, dots, hyphens)'); Helpers::redirect('/admin/services');
                }
                Database::query("INSERT INTO settings(name,value) VALUES('panel_domain',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [$dom]);
                try { ServiceManager::run('cron-glue.sh', ['on']); } catch (Throwable $e2) {}
                Helpers::flash('ok',"Panel host set to $dom — glue is re-checked nightly");
            }
        } catch (Throwable $e) {
            try {
                Database::query("CREATE TABLE IF NOT EXISTS settings (name VARCHAR(190) PRIMARY KEY, value TEXT NOT NULL)");
                if ($dom === '') Database::query("DELETE FROM settings WHERE name='panel_domain'");
                else Database::query("INSERT INTO settings(name,value) VALUES('panel_domain',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [$dom]);
                Helpers::flash('ok','Panel host saved');
            } catch (Throwable $e2) { Helpers::flash('err','Save failed: '.$e2->getMessage()); }
        }
        Helpers::redirect('/admin/services');
    }
    // services that may be stopped from the page: nothing the panel, sites,
    // mail delivery, DNS or remote access depend on
    public const STOPPABLE = ['vsftpd', 'spamassassin', 'clamav'];
    public function restart(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/services'); }
        $units = ServiceManager::units();
        $act = (string)($_POST['action'] ?? 'restart');
        if (!in_array($act, ['restart', 'reload', 'start', 'stop'], true)) $act = 'restart';
        $svcs = [];
        if (($_POST['service'] ?? '') === '*down') {
            foreach (ServiceManager::details() as $k => $v) if ($v['state'] !== 'active') $svcs[] = $k;
            $act = 'restart';
            if (!$svcs) { Helpers::flash('ok','Everything is already running'); Helpers::redirect('/admin/services'); }
        } else {
            $svc = (string)($_POST['service'] ?? '');
            if (!isset($units[$svc])) { Helpers::flash('err','Unknown service'); Helpers::redirect('/admin/services'); }
            if ($act === 'stop' && !in_array($svc, self::STOPPABLE, true)) { Helpers::flash('err',"$svc can't be stopped from here — restart it instead"); Helpers::redirect('/admin/services'); }
            $svcs[] = $svc;
        }
        $msg = []; $bad = false;
        foreach ($svcs as $svc) {
            $r = ServiceManager::run('service-control.sh', [$act, $svc], 60);
            $state = trim((string)preg_replace('/\s+/', ' ', (string)($r['out'] ?? '')));
            $ok = $act === 'stop' ? true : (($r['code'] ?? 1) === 0 && str_ends_with($state, 'active') && !str_ends_with($state, 'inactive'));
            $bad = $bad || !$ok;
            $msg[] = "$svc: ".($ok ? ['restart' => 'restarted', 'reload' => 'reloaded', 'start' => 'started', 'stop' => 'stopped'][$act] : 'failed — '.($state !== '' ? $state : 'no output'));
            Helpers::log("service $act $svc by ".Auth::user()['username'].($ok ? '' : ' (failed)'));
        }
        Helpers::flash($bad ? 'err' : 'ok', implode(' · ', $msg));
        Helpers::redirect('/admin/services');
    }
    public function servicesLive(): void {
        Auth::requireRole(['admin']);
        $this->json(['at' => time(), 'services' => ServiceManager::details()]);
    }
    // Watchdog heartbeat URL (external dead-man monitor). Blank = off.
    public function watchdogHeartbeat(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/services'); }
        $url = trim((string)($_POST['url'] ?? ''));
        if ($url !== '' && !preg_match('#^https://[a-zA-Z0-9.-]+(:[0-9]{1,5})?(/[a-zA-Z0-9_./?=&%-]*)?$#', $url)) {
            Helpers::flash('err','Bad URL (https only)'); Helpers::redirect('/admin/services');
        }
        try {
            if ($url === '') Database::query("DELETE FROM settings WHERE name='watchdog_heartbeat'");
            else Database::query("INSERT INTO settings(name,value) VALUES('watchdog_heartbeat',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [$url]);
            Helpers::log("watchdog heartbeat ".($url === '' ? 'cleared' : 'set')." by ".Auth::user()['username']);
            Helpers::flash('ok', $url === '' ? 'External heartbeat off' : 'Heartbeat on — the monitor should see pings within 5 minutes');
        } catch (Throwable $e) { Helpers::flash('err','Save failed: '.$e->getMessage()); }
        Helpers::redirect('/admin/services');
    }
    public function alertEmail(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/services'); }
        $email = strtolower(trim((string)($_POST['alert_email'] ?? '')));
        try {
            if ($email === '') {
                Database::query("DELETE FROM settings WHERE name='alert_email'");
                Helpers::flash('ok','Alert email cleared — watchdog falls back to admin account emails');
            } else {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/@[^@]+\.[a-z]{2,}$/i', $email) || str_ends_with($email, '@localhost')) {
                    Helpers::flash('err','Give a real routable email (not admin@localhost)'); Helpers::redirect('/admin/services');
                }
                Database::query("INSERT INTO settings(name,value) VALUES('alert_email',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [$email]);
                // system mail (root@, cron failures) must reach a human: point
                // the box's root forwarder at the alert address (proven: bare
                // root otherwise dies in local_delivery as uid 0).
                try {
                    $mn = trim(ServiceManager::run('mail-name.sh', [])['out'] ?? '');
                    if ($mn !== '') ServiceManager::run('forward-add.sh', ["root@$mn", $email]);
                } catch (Throwable $e2) {}
                Helpers::flash('ok',"Downtime alerts will go to $email");
            }
        } catch (Throwable $e) {
            // old installs may lack the settings table — create it on demand
            try {
                Database::query("CREATE TABLE IF NOT EXISTS settings (name VARCHAR(190) PRIMARY KEY, value TEXT NOT NULL)");
                if ($email === '') Database::query("DELETE FROM settings WHERE name='alert_email'");
                else Database::query("INSERT INTO settings(name,value) VALUES('alert_email',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [$email]);
                Helpers::flash('ok','Alert email saved');
            } catch (Throwable $e2) { Helpers::flash('err','Save failed: '.$e2->getMessage()); }
        }
        Helpers::redirect('/admin/services');
    }
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
            Database::query("INSERT INTO settings(name,value) VALUES('malware_enabled',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [$on]);
            Database::query("INSERT INTO settings(name,value) VALUES('malware_hour',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [(string)$hour]);
            Helpers::flash('ok', $on === '1' ? "Malware scan on, daily ~$hour:30" : 'Malware scan disabled');
        } catch (Throwable $e) {
            try {
                Database::query("CREATE TABLE IF NOT EXISTS settings (name VARCHAR(190) PRIMARY KEY, value TEXT NOT NULL)");
                Database::query("INSERT INTO settings(name,value) VALUES('malware_enabled',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [$on]);
                Database::query("INSERT INTO settings(name,value) VALUES('malware_hour',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [(string)$hour]);
                Helpers::flash('ok','Malware scan schedule saved');
            } catch (Throwable $e2) { Helpers::flash('err','Save failed: '.$e2->getMessage()); }
        }
        Helpers::redirect('/admin/services');
    }
    // Migrate hub: cPanel import form posts to the existing accounts/import
    // endpoint (kept stable); transfer UI + direct-admin import plug in here.
    public function migrate(): void {
        Auth::requireRole(['admin']);
        $this->view('admin_migrate', []);
    }
    // Abuse board: live per-user CPU/RAM/processes (proc-live.sh, polled) +
    // per-customer quota/send context. Alerts stay with the 5-min
    // user-abuse.sh; its thresholds are mirrored here so "hot" means the same.
    public const ABUSE_CPU = 150;   // % of one core, summed per user
    public const ABUSE_MEM = 25;    // % of RAM per user
    public function abuse(): void {
        Auth::requireRole(['admin']);
        $users = [];
        try {
            foreach (Database::query("SELECT * FROM users WHERE role!='admin' ORDER BY username")->fetchAll() as $x) {
                $x['disk_used_mb'] = 0;
                try { $x['disk_used_mb'] = Helpers::quota($x)['used_mb']; } catch (Throwable $t) {}
                $bw = Helpers::bandwidthMonthBytes($x);
                $x['bw_month_mb'] = $bw === null ? null : round($bw / 1048576, 1);
                $x['sent_today'] = null;
                try {
                    $r = ServiceManager::run('mail-sent.sh', [$x['username']]);
                    if (($r['code'] ?? 1) === 0 && preg_match('/^\d+$/', trim($r['out']))) $x['sent_today'] = (int)trim($r['out']);
                } catch (Throwable $t) {}
                $users[] = $x;
            }
        } catch (Throwable $e) {}
        $this->view('admin_abuse', compact('users'));
    }
    // proc-live.sh snap -> board rows. $acct: username => [role, status].
    public static function abuseView(array $raw, array $acct): array {
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
                'flags' => $flags, 'hot' => $isC && ($cpu >= 90 || ($age >= 3600 && $cpu >= 50)),
            ];
        }
        $mysql = [];
        foreach ((array)($raw['mysql'] ?? []) as $q) {
            $mysql[] = ['user' => (string)($q['user'] ?? ''), 'dbuser' => (string)($q['dbuser'] ?? ''), 'db' => (string)($q['db'] ?? ''),
                'time' => (int)($q['time'] ?? 0), 'state' => (string)($q['state'] ?? ''), 'query' => (string)($q['query'] ?? ''),
                'hot' => (int)($q['time'] ?? 0) >= 30];
        }
        $load = array_map('floatval', (array)($h['load'] ?? [0, 0, 0]));
        return [
            'host' => [
                'load' => $load, 'cpus' => $cpus, 'busy' => round((float)($h['busy'] ?? 0) / $cpus, 1),
                'mem_used' => $memt - (int)($h['mem_avail'] ?? 0), 'mem_total' => $memt,
                'swap_used' => (int)($h['swap_total'] ?? 0) - (int)($h['swap_free'] ?? 0), 'swap_total' => (int)($h['swap_total'] ?? 0),
                'procs' => (int)($h['procs'] ?? 0), 'uptime' => (int)($h['uptime'] ?? 0),
            ],
            'users' => $users, 'procs' => $procs, 'mysql' => $mysql,
            'limits' => ['cpu' => self::ABUSE_CPU, 'mem' => self::ABUSE_MEM],
        ];
    }
    public function abuseLive(): void {
        Auth::requireRole(['admin']);
        header('Cache-Control: no-store');
        $r = ServiceManager::run('proc-live.sh', ['snap']);
        $raw = json_decode(trim($r['out'] ?? ''), true);
        if (($r['code'] ?? 1) !== 0 || !is_array($raw)) $this->json(['error' => 'live view unavailable: '.substr(trim($r['out'] ?? ''), 0, 200)]);
        $acct = [];
        try { foreach (Database::query("SELECT username, role, status FROM users")->fetchAll() as $x) $acct[$x['username']] = [$x['role'], $x['status']]; } catch (Throwable $e) {}
        $out = self::abuseView($raw, $acct);
        $out['at'] = (new DateTime('now', self::hostTz()))->format('H:i:s');
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
    public function ssh(): void {        Auth::requireRole(['admin']);
        $st = ['pw' => '?', 'root' => '?', 'keys' => '0', 'fps' => '', 'keypair' => 'no'];
        try {
            $r = ServiceManager::run('ssh-status.sh', []);
            if (($r['code'] ?? 1) === 0) {
                foreach (explode("\n", trim($r['out'] ?? '')) as $line) {
                    $p = explode("\t", $line, 2);
                    if (count($p) === 2) $st[$p[0]] = $p[1];
                }
            }
        } catch (Throwable $e) {}
        $this->view('admin_ssh', ['st' => $st]);
    }
    // Panel-generated root keypair (no passphrase — for owner use; humans
    // wanting passphrases generate on their own machine per the how-to).
    // Private half printed ONCE in the flash; afterwards server-side only.
    public function sshKeygen(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/ssh'); }
        $r = ServiceManager::run('ssh-keygen.sh', []);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Keygen refused: '.trim($r['out']));
        else { Helpers::log("ssh keypair generated by ".Auth::user()['username']); Helpers::flash('ok', trim($r['out'])); }
        Helpers::redirect('/admin/ssh');
    }
    public function sshAddKey(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/ssh'); }
        $key = trim((string)($_POST['key'] ?? ''));
        if (!preg_match('/^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp[0-9]+) [A-Za-z0-9+\/=]+( [a-zA-Z0-9@._-]+)?$/', $key) || strlen($key) > 2048) {
            Helpers::flash('err','Not a plain public key line'); Helpers::redirect('/admin/ssh');
        }
        $r = ServiceManager::run('ssh-addkey.sh', [$key]);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Not added: '.trim($r['out']));
        else { Helpers::log("ssh key added by ".Auth::user()['username']); Helpers::flash('ok', trim($r['out']).' — verify a key login works BEFORE disabling passwords'); }
        Helpers::redirect('/admin/ssh');
    }
    public function sshPassword(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/ssh'); }
        $mode = (string)($_POST['mode'] ?? '');
        if ($mode !== 'on' && $mode !== 'off') { Helpers::flash('err','Bad mode'); Helpers::redirect('/admin/ssh'); }
        $r = ServiceManager::run('ssh-password.sh', [$mode]);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Not applied: '.trim($r['out']));
        else { Helpers::log("ssh passwords $mode by ".Auth::user()['username']); Helpers::flash('ok', trim($r['out'])); }
        Helpers::redirect('/admin/ssh');
    }
    public function mail(): void {
        Auth::requireRole(['admin']);
        $q = ServiceManager::run('mail-queue.sh', ['summary']);
        $frozen = ServiceManager::run('mail-queue.sh', ['frozen']);
        // queue browser: script emits "id|age|size|sender|recipients|frozen"
        // (caps 500); filter + paginate here.
        $msgs = [];
        $truncated = false;
        $lr = ServiceManager::run('mail-queue.sh', ['list']);
        foreach (explode("\n", $lr['out'] ?? '') as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if ($line === 'truncated=1') { $truncated = true; continue; }
            $p = explode('|', $line);
            if (count($p) !== 6) continue;
            if (!preg_match('/^[A-Za-z0-9]{6}-[A-Za-z0-9]{2,}-[A-Za-z0-9]{2,}$/', $p[0])) continue;
            $msgs[] = ['id'=>$p[0],'age'=>$p[1],'size'=>$p[2],'from'=>$p[3],'to'=>$p[4],'frozen'=>$p[5]==='1'];
        }
        $fq = trim((string)($_GET['q'] ?? ''));
        if ($fq !== '') $msgs = array_values(array_filter($msgs, fn($m) => stripos($m['id'].' '.$m['from'].' '.$m['to'], $fq) !== false));
        $per = 25;
        $pg = max(1, (int)($_GET['pg'] ?? 1));
        $pages = max(1, (int)ceil(count($msgs)/$per));
        $pg = min($pg, $pages);
        $pageMsgs = array_slice($msgs, ($pg-1)*$per, $per);
        // last stuck reason per message on this page (one read-only call)
        $errs = [];
        $ids = array_values(array_unique(array_column($pageMsgs, 'id')));
        if (!empty($ids)) {
            try {
                $er = ServiceManager::run('mail-queue.sh', array_merge(['errors'], $ids));
                foreach (explode("\n", $er['out'] ?? '') as $line) {
                    $line = trim($line);
                    if ($line === '') continue;
                    $p = explode('|', $line, 2);
                    if (count($p) === 2 && $p[1] !== '') $errs[$p[0]] = $p[1];
                }
            } catch (Throwable $e) {}
        }
        // spool totals over the whole (filtered) queue: messages, bytes, oldest
        $tbytes = 0; $told = 0;
        foreach ($msgs as $m) {
            if (preg_match('/^([\d.]+)\s*([KM]?)$/', $m['size'], $sm)) {
                $b = (float)$sm[1];
                if ($sm[2] === 'K') $b *= 1024; elseif ($sm[2] === 'M') $b *= 1048576;
                $tbytes += (int)$b;
            }
            $mins = 0;
            if (preg_match_all('/(\d+)([smhd])/', $m['age'], $am, PREG_SET_ORDER)) {
                foreach ($am as $a) {
                    $v = (int)$a[1];
                    $mins += $a[2] === 'd' ? $v*1440 : ($a[2] === 'h' ? $v*60 : ($a[2] === 'm' ? $v : 0));
                }
            }
            if ($mins > $told) $told = $mins;
        }
        $totals = ['n' => count($msgs), 'bytes' => $tbytes, 'oldest' => $told];
        // sender-domain deliverability: one mail-score per unique domain on
        // this page (cap 5 — each call is bounded, but slow DNS adds up).
        $auth = [];
        $doms = [];
        foreach ($pageMsgs as $m) {
            if (str_contains($m['from'], '@')) { $d = strtolower(substr(strrchr($m['from'], '@'), 1)); if ($d !== '' && !in_array($d, $doms, true)) $doms[] = $d; }
        }
        foreach (array_slice($doms, 0, 5) as $d) {
            try {
                $sr = ServiceManager::run('mail-score.sh', [$d]);
                $score = null; $det = [];
                foreach (explode("\n", $sr['out'] ?? '') as $sl) {
                    $sl = trim($sl);
                    if (preg_match('/^score=(\d{1,3})$/', $sl, $mm)) $score = min(100, (int)$mm[1]);
                    elseif ($sl !== '') $det[] = $sl;
                }
                if ($score !== null) $auth[$d] = ['score'=>$score, 'details'=>implode("\n", $det)];
            } catch (Throwable $e) {}
        }
        // recent outcomes from the Exim log (newest first, cap 200)
        $outs = [];
        $or = ServiceManager::run('mail-outcomes.sh', ['200']);
        foreach (explode("\n", $or['out'] ?? '') as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $p = explode('|', $line);
            if (count($p) !== 5) continue;
            if (!in_array($p[2], ['delivered','bounced','deferred'], true)) continue;
            $outs[] = ['time'=>$p[0],'id'=>$p[1],'result'=>$p[2],'addr'=>$p[3],'detail'=>$p[4]];
        }
        $res = (string)($_GET['res'] ?? '');
        if (in_array($res, ['delivered','bounced','deferred'], true)) $outs = array_values(array_filter($outs, fn($o) => $o['result'] === $res)); else $res = '';
        $opg = max(1, (int)($_GET['opg'] ?? 1));
        $opages = max(1, (int)ceil(count($outs)/$per));
        $opg = min($opg, $opages);
        $pageOuts = array_slice($outs, ($opg-1)*$per, $per);
        // failing recipient domains (whole outcomes window, not just the page):
        // separates "one dead destination" from "our reputation problem"
        $topDoms = [];
        foreach ($outs as $o) {
            if ($o['result'] === 'delivered') continue;
            $at = strrpos($o['addr'], '@');
            if ($at === false) continue;
            $d = strtolower(substr($o['addr'], $at + 1));
            if ($d === '') continue;
            if (!isset($topDoms[$d])) $topDoms[$d] = ['bounced' => 0, 'deferred' => 0];
            $topDoms[$d][$o['result']]++;
        }
        uasort($topDoms, fn($a, $b) => ($b['bounced'] + $b['deferred']) <=> ($a['bounced'] + $a['deferred']));
        $topDoms = array_slice($topDoms, 0, 8, true);
        $nq = 0; $nf = 0;
        if (preg_match('/queued:\s*(\d+)/', $q['out'] ?? '', $m)) $nq = (int)$m[1];
        if (preg_match('/frozen:\s*(\d+)/', $q['out'] ?? '', $m)) $nf = (int)$m[1];
        // single-message inspection (?show=<id>, headers + recent log lines)
        $show = null; $showId = (string)($_GET['show'] ?? '');
        $showRetry = '';
        if ($showId !== '' && preg_match('/^[A-Za-z0-9]{6}-[A-Za-z0-9]{2,}-[A-Za-z0-9]{2,}$/', $showId)) {
            $vr = ServiceManager::run('mail-queue.sh', ['show-one', $showId]);
            $show = ['id'=>$showId, 'out'=>$vr['out'] ?? '(no output)'];
            // next attempt for the first recipient (one bounded call)
            foreach ($msgs as $m) {
                if ($m['id'] !== $showId) continue;
                $first = trim(explode(',', $m['to'])[0] ?? '');
                if (preg_match('/^[^@\s]+@[^@\s]+\.[a-zA-Z]{2,}$/', $first)) {
                    try {
                        $rr = ServiceManager::run('mail-queue.sh', ['retry-when', $first]);
                        $showRetry = trim($rr['out'] ?? '');
                    } catch (Throwable $e) {}
                }
                break;
            }
        }
        // blacklist monitor: cached rows (blacklist-check.sh), never live DNS here
        $bl = Database::query("SELECT * FROM blacklist_status ORDER BY FIELD(state,'listed','unchecked','clean'), kind, target, name")->fetchAll();
        $blAt = (string)(Database::query("SELECT value FROM settings WHERE name='blacklist_checked_at'")->fetch()['value'] ?? '');
        $blRelay = (string)(Database::query("SELECT value FROM settings WHERE name='blacklist_relay'")->fetch()['value'] ?? '') === '1';
        $blRun = trim((string)(ServiceManager::run('blacklist-check.sh', ['running'])['out'] ?? '')) === 'running';
        $this->view('admin_mail', ['bl'=>$bl, 'blAt'=>$blAt, 'blRelay'=>$blRelay, 'blRun'=>$blRun, 'queue'=>$q['out'] ?? '', 'frozen'=>$frozen['out'] ?? '', 'msgs'=>$pageMsgs, 'totalMsgs'=>count($msgs), 'pg'=>$pg, 'pages'=>$pages, 'fq'=>$fq, 'auth'=>$auth, 'truncated'=>$truncated, 'outs'=>$pageOuts, 'totalOuts'=>count($outs), 'opg'=>$opg, 'opages'=>$opages, 'res'=>$res, 'nq'=>$nq, 'nf'=>$nf, 'show'=>$show, 'errs'=>$errs, 'totals'=>$totals, 'topDoms'=>$topDoms, 'showRetry'=>$showRetry]);
    }
    public function logs(): void {
        Auth::requireRole(['admin']);
        $valid = ['panel','exim','exim_reject','mail','vsftpd','fail2ban','litespeed','named','upgrade','phpext','update','software','apptoolkit','malware','offsite','certbot'];
        $svc = (string)($_GET['log'] ?? 'panel');
        if (!in_array($svc, $valid, true)) $svc = 'panel';
        $lines = [];
        $n = (int)($_GET['n'] ?? 200);
        if (!in_array($n, [50, 200, 500], true)) $n = 200;
        try {
            $r = ServiceManager::run('log-read.sh', [$svc, (string)$n]);
            if (($r['code'] ?? 1) === 0) {
                $all = array_filter(array_map('trim', explode("\n", $r['out'] ?? '')));
                $lines = array_values($all);
            } else $lines = ['(reader failed: '.trim($r['out']).')'];
        } catch (Throwable $e) { $lines = ['(reader error)']; }
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q !== '') $lines = array_values(array_filter($lines, fn($l) => stripos($l, $q) !== false));
        $ord = (string)($_GET['ord'] ?? 'new');
        if ($ord !== 'old') { $ord = 'new'; $lines = array_reverse($lines); }
        // readability: split leading timestamp, classify severity for coloring
        $rows = [];
        foreach ($lines as $l) {
            $ts = '';
            if (preg_match('/^(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}|\w{3}\s+\d{1,2} \d{2}:\d{2}:\d{2})\s*/', $l, $m)) {
                $ts = $m[1]; $l = substr($l, strlen($m[0]));
            }
            $lvl = Helpers::logLevel($l);
            $rows[] = ['ts' => $ts, 'msg' => $l, 'lvl' => $lvl];
        }
        $this->view('admin_logs', ['rows' => $rows, 'q' => $q, 'svc' => $svc, 'svcs' => $valid, 'n' => $n, 'ord' => $ord]);
    }
    public function sessions(): void {
        Auth::requireRole(['admin']);
        $list = []; $anon = 0;
        try {
            $r = ServiceManager::run('session-list.sh', []);
            if (($r['code'] ?? 1) === 0) {
                foreach (explode("\n", trim($r['out'] ?? '')) as $line) {
                    $p = explode("\t", $line);
                    if (count($p) < 4 || !preg_match('/^[a-z0-9,-]{22,128}$/i', $p[0])) continue;
                    // not logged in (login-page visit / health check): count only
                    if (($p[4] ?? '1') === '0') { $anon++; continue; }
                    $list[] = ['sid' => $p[0], 'user' => $p[1], 'role' => $p[2],
                        'active' => date('Y-m-d H:i:s', (int)$p[3]),
                        'current' => ($p[0] === session_id())];
                }
            }
        } catch (Throwable $e) {}
        usort($list, fn($a, $b) => strcmp($b['active'], $a['active']));
        $this->view('admin_sessions', compact('list', 'anon'));
    }
    public function sessionKill(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/sessions'); }
        $sid = (string)($_POST['sid'] ?? '');
        if (!preg_match('/^[a-z0-9,-]{22,128}$/i', $sid)) { Helpers::flash('err','Bad session'); Helpers::redirect('/admin/sessions'); }
        if ($sid === session_id()) { Helpers::flash('err','That is your own session'); Helpers::redirect('/admin/sessions'); }
        $r = ServiceManager::run('session-kill.sh', [$sid]);
        if (($r['code'] ?? 1) === 0) { Helpers::log("session killed for sid=$sid by ".Auth::user()['username']); Helpers::flash('ok','Session killed (next click logs them out)'); }
        else Helpers::flash('err','Kill failed: '.trim($r['out']));
        Helpers::redirect('/admin/sessions');
    }
    // well-known ports -> what a human calls them (rules table labels)
    private const FW_SERVICES = [
        '22' => 'SSH', '80' => 'HTTP', '443' => 'HTTPS', '80,443' => 'Websites', '8090' => 'Panel',
        '8091' => 'Webmail', '8092' => 'phpMyAdmin', '7080' => 'LiteSpeed WebAdmin', '3306' => 'MariaDB',
        '53' => 'DNS', '25,143,465,587,993' => 'Mail', '25' => 'SMTP', '587' => 'Submission', '465' => 'SMTPS',
        '143' => 'IMAP', '993' => 'IMAPS', '110' => 'POP3', '995' => 'POP3S', '21' => 'FTP',
        '21,990,40000:50000' => 'FTP / FTPS + passive',
    ];
    // ufw status numbered -> rules, IPv4/IPv6 twins merged into one row
    // (deleting it removes both), grouped open / restricted / closed / blocked
    public static function fwRules(string $out): array {
        $rows = [];
        foreach (explode("\n", $out) as $line) {
            if (!preg_match('/^\[\s*(\d+)\]\s+(.+?)\s+(ALLOW|DENY|REJECT|LIMIT)\s+(IN|OUT|FWD)\s+(.+?)\s*$/', trim($line), $m)) continue;
            $v6 = str_contains($m[2], '(v6)') || str_contains($m[5], '(v6)');
            $to = trim(str_replace('(v6)', '', $m[2])); $from = trim(str_replace('(v6)', '', $m[5]));
            $comment = '';
            if (preg_match('/^(.*?)\s+#\s*(.*)$/', $from, $c)) { $from = $c[1]; $comment = $c[2]; }
            $key = "$to|$m[3]|$m[4]|$from";
            $spec = preg_replace('/\s+/', ' ', "$m[2] $m[3] $m[4] $m[5]");
            if (!isset($rows[$key])) {
                $port = preg_replace('#/(tcp|udp)$#', '', $to);
                $rows[$key] = ['to' => $to, 'action' => $m[3], 'dir' => $m[4], 'from' => $from, 'comment' => $comment,
                    'service' => self::FW_SERVICES[$port] ?? '', 'v4' => false, 'v6' => false, 'specs' => [], 'n' => (int)$m[1],
                    'ssh' => (bool)preg_match('#^22(/|$)#', $to)];
            }
            $rows[$key][$v6 ? 'v6' : 'v4'] = true;
            $rows[$key]['specs'][] = $spec;
        }
        $g = ['open' => [], 'restricted' => [], 'closed' => [], 'blocked' => []];
        foreach ($rows as $r) {
            $any = $r['from'] === 'Anywhere';
            if ($r['action'] === 'ALLOW' || $r['action'] === 'LIMIT') $g[$any ? 'open' : 'restricted'][] = $r;
            else $g[$any ? 'closed' : 'blocked'][] = $r;
        }
        return $g;
    }
    public static function allowList(string $out): array {
        $l = [];
        foreach (explode("\n", trim($out)) as $line) {
            $p = explode("\t", $line, 2);
            if ($p[0] !== '' && preg_match('#^[0-9a-fA-F:.]+(/\d{1,3})?$#', $p[0])) $l[] = ['net' => $p[0], 'label' => $p[1] ?? ''];
        }
        return $l;
    }
    public function firewall(): void {
        Auth::requireRole(['admin']);
        $active = '?';
        $r = ServiceManager::run('ufw-manage.sh', ['status']);
        if (preg_match('/^Status:\s*(\S+)/im', $r['out'] ?? '', $m)) $active = strtolower($m[1]);
        $groups = self::fwRules($r['out'] ?? '');
        $allow = [];
        try { $allow = self::allowList(ServiceManager::run('fail2ban-bans.sh', ['allow-list'])['out'] ?? ''); } catch (Throwable $e) {}
        $myIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $this->view('admin_firewall', compact('active', 'groups', 'allow', 'myIp'));
    }
    // never-ban list (fail2ban ignoreip); add also unbans
    public function firewallAllowIp(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/firewall'); }
        $net = trim((string)($_POST['net'] ?? ''));
        $label = trim((string)($_POST['label'] ?? ''));
        $del = ($_POST['op'] ?? '') === 'del';
        [$ip, $len] = array_pad(explode('/', $net, 2), 2, null);
        if (!filter_var($ip, FILTER_VALIDATE_IP) || ($len !== null && !preg_match('/^\d{1,3}$/', $len))) { Helpers::flash('err','Enter an IP address or network like 203.0.113.0/24'); Helpers::redirect('/admin/firewall'); }
        if (!preg_match('/^[A-Za-z0-9 ._@-]{0,40}$/', $label)) { Helpers::flash('err','Label: letters, digits, spaces, . _ @ - (40 max)'); Helpers::redirect('/admin/firewall'); }
        $r = ServiceManager::run('fail2ban-bans.sh', $del ? ['allow-del', $net] : ['allow-add', $net, $label], 60);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Not changed: '.trim($r['out']));
        else { Helpers::log('fail2ban never-ban '.($del ? 'remove' : 'add')." $net by ".Auth::user()['username']); Helpers::flash('ok', ucfirst(trim($r['out']))); }
        Helpers::redirect('/admin/firewall');
    }
    public function firewallAllow(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/firewall'); }
        $port = (int)($_POST['port'] ?? 0);
        $proto = (($_POST['proto'] ?? '') === 'udp') ? 'udp' : 'tcp';
        if ($port < 1 || $port > 65535) { Helpers::flash('err','Bad port'); Helpers::redirect('/admin/firewall'); }
        $r = ServiceManager::run('ufw-manage.sh', ['allow', (string)$port, $proto]);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Allow failed: '.trim($r['out']));
        else { Helpers::log("firewall allow $port/$proto by ".Auth::user()['username']); Helpers::flash('ok',"Allowed $port/$proto"); }
        Helpers::redirect('/admin/firewall');
    }
    public function firewallDeny(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/firewall'); }
        $port = (int)($_POST['port'] ?? 0);
        $proto = (($_POST['proto'] ?? '') === 'udp') ? 'udp' : 'tcp';
        if ($port < 1 || $port > 65535) { Helpers::flash('err','Bad port'); Helpers::redirect('/admin/firewall'); }
        if ($port === 22) { Helpers::flash('err','Port 22 stays open (lockout protection)'); Helpers::redirect('/admin/firewall'); }
        $r = ServiceManager::run('ufw-manage.sh', ['deny', (string)$port, $proto]);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Deny failed: '.trim($r['out']));
        else { Helpers::log("firewall deny $port/$proto by ".Auth::user()['username']); Helpers::flash('ok',"Denied $port/$proto"); }
        Helpers::redirect('/admin/firewall');
    }
    public function firewallBlock(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/firewall'); }
        $ip = trim((string)($_POST['ip'] ?? ''));
        if (!filter_var($ip, FILTER_VALIDATE_IP)) { Helpers::flash('err','Bad IP address'); Helpers::redirect('/admin/firewall'); }
        $r = ServiceManager::run('ufw-manage.sh', ['block', $ip]);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Block failed: '.trim($r['out']));
        else { Helpers::log("firewall block $ip by ".Auth::user()['username']); Helpers::flash('ok',"Blocked $ip"); }
        Helpers::redirect('/admin/firewall');
    }
    // Firewall page "Recent bans": fail2ban's db (24 h) via fail2ban-bans.sh,
    // with a plain-English reason per jail + what the matched lines tried.
    private const BAN_REASONS = [
        'sshd' => 'SSH login failures', 'panel' => 'Panel login failures',
        'phpmyadmin' => 'phpMyAdmin login failures', 'weblogin' => 'Website login flood (wp-login/xmlrpc)',
        'lsws' => 'Web server auth/forbidden flood', 'exim' => 'Mail (SMTP) login failures',
        'dovecot' => 'Mailbox (IMAP/POP3) login failures', 'vsftpd' => 'FTP login failures',
    ];
    // jails guarding the admin surface: any ban there is worth a look
    private const BAN_SERIOUS_JAILS = ['panel', 'phpmyadmin'];
    // the box's zone (PHP here runs UTC): ban times then match the log lines
    private static function hostTz(): DateTimeZone {
        $n = trim((string)@file_get_contents('/etc/timezone'));
        if ($n === '' && preg_match('#zoneinfo/(.+)$#', (string)@readlink('/etc/localtime'), $m)) $n = $m[1];
        try { return new DateTimeZone($n !== '' ? $n : 'UTC'); } catch (Throwable $e) { return new DateTimeZone('UTC'); }
    }
    public static function banRows(array $raw, string $me = ''): array {
        $tz = self::hostTz();
        $fmt = fn(int $t, string $f) => (new DateTime('@'.$t))->setTimezone($tz)->format($f);
        $times = $jails = [];
        foreach ($raw as $b) {
            $ip = (string)($b['ip'] ?? '');
            $times[$ip] = ($times[$ip] ?? 0) + 1;
            $jails[$ip][(string)($b['jail'] ?? '')] = true;
        }
        $out = [];
        foreach ($raw as $b) {
            $ip = (string)($b['ip'] ?? ''); $jail = (string)($b['jail'] ?? '');
            if (!filter_var($ip, FILTER_VALIDATE_IP)) continue;
            $m = array_values(array_filter(array_map('strval', (array)($b['matches'] ?? []))));
            $users = [];
            foreach ($m as $line) {
                if (preg_match('/\b(?:invalid user|for(?: invalid user)?|user=<?|set_id=|sasl_username=|login=)\s*([A-Za-z0-9._@+-]{1,64})/i', $line, $u)
                    && !in_array(strtolower($u[1]), ['from', 'user', 'invalid'], true)) $users[$u[1]] = true;
            }
            $why = []; $serious = false;
            if (in_array($jail, self::BAN_SERIOUS_JAILS, true)) { $serious = true; $why[] = 'targets the panel'; }
            if (($times[$ip] ?? 0) >= 3) { $serious = true; $why[] = 'banned '.$times[$ip].'× in 24 h'; }
            if (count($jails[$ip] ?? []) >= 2) { $serious = true; $why[] = 'hit '.count($jails[$ip]).' services'; }
            if ((int)($b['count'] ?? 1) >= 3) { $serious = true; $why[] = 'repeat offender'; }
            $at = (int)($b['at'] ?? 0); $for = (int)($b['for'] ?? 0);
            $out[] = [
                'ip' => $ip, 'jail' => $jail, 'active' => !empty($b['active']),
                'at' => $at ? $fmt($at, 'Y-m-d H:i:s') : '', 'ts' => $at,
                'until' => ($at && $for > 0) ? $fmt($at + $for, 'H:i') : ($for < 0 ? 'permanent' : ''),
                'reason' => self::BAN_REASONS[$jail] ?? "fail2ban jail $jail",
                'fails' => (int)($b['fails'] ?? 0), 'users' => array_slice(array_keys($users), 0, 5),
                'lines' => array_slice($m, -5), 'serious' => $serious, 'why' => implode(', ', $why),
                'me' => $me !== '' && $ip === $me,
            ];
        }
        return $out;
    }
    public function firewallBans(): void {
        Auth::requireRole(['admin']);
        header('Cache-Control: no-store');
        $r = ServiceManager::run('fail2ban-bans.sh', ['list']);
        $raw = json_decode(trim($r['out'] ?? ''), true);
        if (($r['code'] ?? 1) !== 0 || !is_array($raw) || !is_array($raw['bans'] ?? null)) $this->json(['error' => trim($r['out'] ?? 'fail2ban unavailable'), 'bans' => [], 'jails' => []]);
        $jails = array_values(array_filter(array_map('strval', (array)($raw['jails'] ?? [])), fn($j) => (bool)preg_match('/^[A-Za-z0-9_.-]+$/', $j)));
        $this->json(['bans' => self::banRows($raw['bans'], (string)($_SERVER['REMOTE_ADDR'] ?? '')), 'jails' => $jails, 'at' => (new DateTime('now', self::hostTz()))->format('H:i:s T')]);
    }
    public function firewallUnban(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/firewall'); }
        $ip = trim((string)($_POST['ip'] ?? ''));
        if (!filter_var($ip, FILTER_VALIDATE_IP)) { Helpers::flash('err','Bad IP address'); Helpers::redirect('/admin/firewall'); }
        $r = ServiceManager::run('fail2ban-bans.sh', ['unban', $ip]);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Unban failed: '.trim($r['out']));
        else { Helpers::log("fail2ban unban $ip by ".Auth::user()['username']); Helpers::flash('ok', ucfirst(trim($r['out'])).' — it can be banned again if the failures continue.'); }
        Helpers::redirect('/admin/firewall');
    }
    public const PHPINI_KEYS = ['memory_limit', 'upload_max_filesize', 'post_max_size', 'max_execution_time', 'display_errors'];
    public function phpini(): void {
        Auth::requireRole(['admin']);
        $ver = preg_replace('/[^0-9]/', '', (string)($_GET['ver'] ?? ''));
        if (!in_array($ver, PhpVersions::knownShort(), true)) $ver = str_replace('.', '', PhpVersions::DEFAULT);
        $vals = [];
        $opcache = null;
        $f = "/etc/php/8.${ver[1]}/fpm/php.ini";
        $oc = "/etc/php/8.${ver[1]}/fpm/conf.d/10-opcache.ini";
        if (is_readable($oc)) {
            foreach (file($oc, FILE_IGNORE_NEW_LINES) as $line) {
                if (preg_match('/^opcache\.enable\s*=\s*(\d)/i', $line, $m)) { $opcache = $m[1] === '1' ? 'on' : 'off'; break; }
            }
        }
        if (is_readable($f)) {
            // active (uncommented) lines only — commented defaults don't apply
            foreach (file($f, FILE_IGNORE_NEW_LINES) as $line) {
                if (preg_match('/^([a-z_.]+)\s*=\s*(.*?)\s*$/i', $line, $m) && in_array($m[1], self::PHPINI_KEYS, true)) {
                    $vals[$m[1]] = $m[2];
                }
            }
        }
        $this->view('admin_phpini', ['ver' => $ver, 'vers' => PhpVersions::knownShort(), 'vals' => $vals, 'keys' => self::PHPINI_KEYS, 'opcache' => $opcache]);
    }
    public function opcacheSet(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/phpini'); }
        $ver = preg_replace('/[^0-9]/', '', (string)($_POST['ver'] ?? ''));
        $state = (string)($_POST['state'] ?? '');
        if (!in_array($ver, PhpVersions::knownShort(), true) || ($state !== 'on' && $state !== 'off')) {
            Helpers::flash('err','Bad version/state'); Helpers::redirect('/admin/phpini?ver='.$ver);
        }
        $r = ServiceManager::run('opcache-set.sh', [$ver, $state]);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Not applied: '.trim($r['out']));
        else { Helpers::log("opcache $state 8.${ver[1]} by ".Auth::user()['username']); Helpers::flash('ok', trim($r['out'])); }
        Helpers::redirect('/admin/phpini?ver='.$ver);
    }
    public function phpiniSet(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/phpini'); }
        $ver = preg_replace('/[^0-9]/', '', (string)($_POST['ver'] ?? ''));
        $key = (string)($_POST['key'] ?? '');
        $val = trim((string)($_POST['value'] ?? ''));
        if (!in_array($ver, PhpVersions::knownShort(), true) || !in_array($key, self::PHPINI_KEYS, true) || $val === '' || strlen($val) > 32) {
            Helpers::flash('err','Bad version/key/value'); Helpers::redirect('/admin/phpini?ver='.$ver);
        }
        $r = ServiceManager::run('phpini-set.sh', [$ver, $key, $val]);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Not applied: '.trim($r['out']));
        else { Helpers::log("php.ini 8.${ver[1]} $key=$val by ".Auth::user()['username']); Helpers::flash('ok', trim($r['out'])); }
        Helpers::redirect('/admin/phpini?ver='.$ver);
    }
    // Per-user FPM pools: version, worker cap, hardening status (shell
    // functions off, open_basedir, memory cap, niced). Re-apply regenerates
    // the pool from the hardened template — safe to run any time.
    public function phpPools(): void {
        Auth::requireRole(['admin']);
        $pools = [];
        $r = ServiceManager::run('fpm-status.sh', []);
        if (($r['code'] ?? 1) === 0) {
            foreach (explode("\n", trim($r['out'] ?? '')) as $line) {
                $p = explode("\t", $line);
                if (count($p) === 6) $pools[] = ['user' => $p[0], 'ver' => $p[1], 'children' => $p[2], 'hardened' => $p[3], 'memory' => $p[4], 'nice' => $p[5]];
            }
        }
        $this->view('admin_pools', compact('pools'));
    }
    public function phpPoolsApply(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/php-pools'); }
        $target = (string)($_POST['target'] ?? '--all');
        if ($target !== '--all' && !preg_match('/^[a-z][a-z0-9_]{2,31}$/', $target)) {
            Helpers::flash('err','Bad user'); Helpers::redirect('/admin/php-pools');
        }
        $r = ServiceManager::run('fpm-harden.sh', [$target]);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Not applied: '.trim($r['out']));
        else { Helpers::log("fpm-harden $target by ".Auth::user()['username']); Helpers::flash('ok', trim($r['out'])); }
        Helpers::redirect('/admin/php-pools');
    }
    // Curated PHP extensions, installed server-wide on every FPM version at
    // once (php-ext.sh). Install/remove run detached; the page polls.
    public function phpExt(): void {
        Auth::requireRole(['admin']);
        $grid = []; $vers = []; $running = false; $list = [];
        $r = ServiceManager::run('php-ext.sh', ['status'], 60);
        if (($r['code'] ?? 1) === 0) {
            foreach (explode("\n", trim($r['out'] ?? '')) as $line) {
                $p = explode("\t", $line, 2);
                if (count($p) !== 2) continue;
                if ($p[0] === 'job') { $running = trim($p[1]) === 'running'; continue; }
                if ($p[0] === 'list') { $list = array_values(array_filter(explode(' ', trim($p[1])))); continue; }
                if (!PhpExtensions::valid($p[0])) continue;
                foreach (explode(' ', trim($p[1])) as $vs) {
                    if (preg_match('/^(8\.\d)=(on|off|missing|na)$/', $vs, $m)) { $grid[$p[0]][$m[1]] = $m[2]; $vers[$m[1]] = true; }
                }
            }
        }
        $vers = array_keys($vers); sort($vers);
        $log = [];
        $lr = ServiceManager::run('log-read.sh', ['phpext', '50']);
        if (($lr['code'] ?? 1) === 0) $log = array_slice(array_filter(explode("\n", $lr['out'] ?? ''), 'strlen'), -14);
        $err = ($r['code'] ?? 1) === 0 ? '' : trim($r['out'] ?? '');
        $this->view('admin_phpext', compact('grid', 'vers', 'running', 'list', 'log', 'err'));
    }
    public function phpExtApply(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/php-ext'); }
        $act = (string)($_POST['act'] ?? '');
        $ext = (string)($_POST['ext'] ?? '');
        if (!in_array($act, ['install', 'remove'], true) || !PhpExtensions::valid($ext)) {
            Helpers::flash('err','Bad extension/action'); Helpers::redirect('/admin/php-ext');
        }
        $r = ServiceManager::run('php-ext.sh', [$act, $ext], 60);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err', trim($r['out'] ?? 'failed'));
        else { Helpers::log("php-ext $act $ext by ".Auth::user()['username']); Helpers::flash('ok', trim($r['out'])); }
        Helpers::redirect('/admin/php-ext');
    }
    public function disk(): void {
        Auth::requireRole(['admin']);
        $usage = [];
        $r = ServiceManager::run('disk-usage.sh', []);
        if (($r['code'] ?? 1) === 0) {
            foreach (explode("\n", trim($r['out'] ?? '')) as $line) {
                $p = explode("\t", $line);
                if (count($p) === 2) $usage[$p[0]] = $p[1];
            }
        }
        $this->view('admin_disk', compact('usage'));
    }
    public function diskClean(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/disk'); }
        $t = (string)($_POST['target'] ?? '');
        if (!in_array($t, ['staging','sessions','releases'], true)) { Helpers::flash('err','Bad target'); Helpers::redirect('/admin/disk'); }
        $r = ServiceManager::run('disk-clean.sh', [$t]);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Clean failed: '.trim($r['out']));
        else { Helpers::log("disk clean $t by ".Auth::user()['username']); Helpers::flash('ok', trim($r['out'])); }
        Helpers::redirect('/admin/disk');
    }
    public function firewallDelete(): void {        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/firewall'); }
        // by rule text, not number: fail2ban inserts bans at #1, shifting numbers under a loaded page
        $specs = array_slice(array_map('strval', (array)($_POST['spec'] ?? [])), 0, 2);
        $specs = array_filter($specs, fn($x) => $x !== '' && strlen($x) <= 200 && preg_match('/^[\x20-\x7e]+$/', $x));
        if (!$specs) { Helpers::flash('err','Bad rule'); Helpers::redirect('/admin/firewall'); }
        $done = []; $errs = [];
        foreach ($specs as $sp) {
            $r = ServiceManager::run('ufw-manage.sh', ['delete-match', $sp]);
            if (($r['code'] ?? 1) !== 0) $errs[] = trim($r['out']); else $done[] = $sp;
        }
        // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string -- not SQL: audit log text; spec is printable-ASCII validated
        if ($done) Helpers::log('firewall delete rule "'.implode('" + "', $done).'" by '.Auth::user()['username']);
        if ($errs) Helpers::flash('err', 'Delete refused/failed: '.implode('; ', array_unique($errs)));
        else Helpers::flash('ok', 'Rule deleted');
        Helpers::redirect('/admin/firewall');
    }
    public function updatePage(): void {        Auth::requireRole(['admin']);
        $reboot = file_exists('/var/run/reboot-required');
        // current release = symlink target; releases = version dirs on disk
        $cur = is_link('/usr/local/skypanel/panel') ? basename(dirname((string)readlink('/usr/local/skypanel/panel'))) : '(pre-release layout)';
        $rels = [];        foreach ((glob('/usr/local/skypanel/releases/*') ?: []) as $d) {
            if (is_dir($d) && preg_match('/^[a-zA-Z0-9._-]+$/', basename($d))) $rels[] = basename($d);
        }
        rsort($rels);
        // available-update banner (same daily probe state as the dashboard)
        $update = null;
        $st = @file('/var/lib/karnakpanel/update-check', FILE_IGNORE_NEW_LINES);
        if ($st && preg_match('/^(\S+) (\S+) (ok|urgent|info|unreachable)$/', trim(implode('', $st)), $m) && $m[3] !== 'ok') {
            $update = ['local' => $m[1], 'remote' => $m[2], 'sev' => $m[3]];
        }
        // file integrity: tripwire-lite over panel code, scripts and control
        // configs. Checked daily by cron; this is the live status + the
        // re-baseline button for after intentional hand edits.
        $integ = 'unknown';
        try {
            $ir = ServiceManager::run('integrity-check.sh', []);
            $integ = (($ir['code'] ?? 1) === 0) ? 'ok' : trim($ir['out'] ?? 'changed');
        } catch (Throwable $e) {}
        // staged release available for one-click install (fetched, verified)
        $staged = null;
        $sdir = realpath('/usr/local/skypanel/panel/storage/tmp-uploads');
        if ($sdir) {
            $cands = glob("$sdir/karnakpanel-panel-*.tar.gz") ?: [];
            if ($cands) {
                usort($cands, fn($a, $b) => filemtime($b) <=> filemtime($a));
                $staged = basename($cands[0]);
            }
        }
        $this->view('admin_update', ['reboot' => $reboot, 'cur' => $cur, 'rels' => $rels, 'integ' => $integ, 'update' => $update, 'staged' => $staged]);
    }
    // Reboot is scheduled +1 min (not instant) so this response + flash land
    // before the box goes down. Admin-only + CSRF like every other POST here.
    public function reboot(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/update'); }
        $r = ServiceManager::run('system-reboot.sh', ['go']);
        Helpers::log("reboot scheduled by ".Auth::user()['username']);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Reboot refused: '.trim($r['out']));
        else Helpers::flash('ok', trim($r['out'])." — panel, sites and mail drop for ~1-2 min");
        Helpers::redirect('/admin/update');
    }
    public function updateStart(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/update'); }
        $mode = ($_POST['mode'] ?? '') === 'go' ? 'go' : '';
        $r = ServiceManager::run('system-update.sh', $mode === 'go' ? ['go'] : []);
        Helpers::log("system update started mode=".($mode === 'go' ? 'upgrade' : 'check')." by ".Auth::user()['username']);
        Helpers::flash('ok', trim($r['out'])." — watch progress in Logs → upgrade");
        Helpers::redirect('/admin/logs?log=upgrade');
    }
    // One-click import: fetch the latest SIGNED release from the feed into
    // staging (checksum-verified; signature verified at install time by
    // panel-update.sh). Install stays a separate deliberate click below.
    public function panelFetch(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/update'); }
        $r = ServiceManager::run('panel-fetch-update.sh', []);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Fetch failed: '.trim($r['out']));
        else Helpers::flash('ok',trim($r['out']).' — install it with the button below');
        Helpers::redirect('/admin/update');
    }
    // Install a fetched (staged) release without re-uploading: newest
    // karnakpanel-panel-*.tar.gz in staging. Same panel-update.sh path (and
    // signature gates) as the upload form — this only skips the upload.
    public function panelInstallStaged(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/update'); }
        $stage = realpath('/usr/local/skypanel/panel/storage/tmp-uploads');
        $cands = $stage ? glob("$stage/karnakpanel-panel-*.tar.gz") : [];
        if (!$cands) { Helpers::flash('err','Nothing staged — fetch first'); Helpers::redirect('/admin/update'); }
        usort($cands, fn($a, $b) => filemtime($b) <=> filemtime($a));
        $r = ServiceManager::run('panel-update.sh', [$cands[0]]);
        Helpers::log("panel self-update (staged) started by ".Auth::user()['username']);
        Helpers::flash('ok', trim($r['out'])." — watch progress in Logs → update");
        Helpers::redirect('/admin/logs?log=update');
    }
    public function panelUpdate(): void {        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/update'); }
        $f = $_FILES['pkg'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== 0 || !str_ends_with(strtolower($f['name'] ?? ''), '.tar.gz')) {
            Helpers::flash('err','Upload a .tar.gz release tarball'); Helpers::redirect('/admin/update');
        }
        $stage = '/usr/local/skypanel/panel/storage/tmp-uploads';
        // panel dir may be a symlink after first update — resolve for staging
        $real = realpath('/usr/local/skypanel/panel/storage/tmp-uploads') ?: $stage;
        @mkdir($real, 0700, true);
        $dest = $real.'/release-'.bin2hex(random_bytes(8)).'.tar.gz';
        if (!@move_uploaded_file($f['tmp_name'], $dest)) { Helpers::flash('err','Staging failed'); Helpers::redirect('/admin/update'); }
        $r = ServiceManager::run('panel-update.sh', [$dest]);
        Helpers::log("panel self-update started by ".Auth::user()['username']);
        Helpers::flash('ok', trim($r['out'])." — watch progress in Logs → update");
        Helpers::redirect('/admin/logs?log=update');
    }
    // Re-baseline file integrity after intentional hand edits (config
    // tuning etc.). Updates and SSL/exim scripts re-baseline themselves.
    public function integrityBaseline(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/update'); }
        $r = ServiceManager::run('integrity-check.sh', ['--baseline']);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Baseline failed: '.trim($r['out']));
        else { Helpers::log("integrity re-baselined by ".Auth::user()['username']); Helpers::flash('ok', trim($r['out'])); }
        Helpers::redirect('/admin/update');
    }
    public function panelRollback(): void {        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/update'); }
        $v = (string)($_POST['release'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $v)) { Helpers::flash('err','Bad release'); Helpers::redirect('/admin/update'); }
        $r = ServiceManager::run('panel-rollback.sh', [$v]);
        Helpers::log("panel rollback to $v started by ".Auth::user()['username']);
        Helpers::flash('ok', trim($r['out'])." — watch progress in Logs → update");
        Helpers::redirect('/admin/logs?log=update');
    }
    public function blacklistCheck(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/mail'); }
        $r = ServiceManager::run('blacklist-check.sh', ['now']);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err', trim($r['out'] ?? 'failed'));
        else { Helpers::log('blacklist check started by '.Auth::user()['username']); Helpers::flash('ok', trim($r['out'])); }
        Helpers::redirect('/admin/mail#blacklists');
    }
    public function mailFlush(): void {        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/mail'); }
        $r = ServiceManager::run('mail-queue.sh', ['flush']);
        Helpers::flash('ok', 'Queue run: '.trim($r['out'] ?? ''));
        Helpers::redirect('/admin/mail');
    }
    // Thaw frozen (retry), drop frozen (dead bounces), drop all (spam floods,
    // 500/batch — press again if the summary still shows queued). All logged.
    public function mailThaw(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/mail'); }
        $r = ServiceManager::run('mail-queue.sh', ['thaw-frozen']);
        Helpers::log("mail thaw-frozen by ".Auth::user()['username'].": ".trim($r['out'] ?? ''));
        Helpers::flash('ok', trim($r['out'] ?? ''));
        Helpers::redirect('/admin/mail');
    }
    public function mailDropFrozen(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/mail'); }
        $r = ServiceManager::run('mail-queue.sh', ['drop-frozen']);
        Helpers::log("mail drop-frozen by ".Auth::user()['username'].": ".trim($r['out'] ?? ''));
        Helpers::flash('ok', trim($r['out'] ?? ''));
        Helpers::redirect('/admin/mail');
    }
    public function mailDropAll(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/mail'); }
        if (($_POST['confirm'] ?? '') !== 'DELETE') { Helpers::flash('err','Type DELETE to confirm'); Helpers::redirect('/admin/mail'); }
        $r = ServiceManager::run('mail-queue.sh', ['drop-all']);
        Helpers::log("mail DROP-ALL by ".Auth::user()['username'].": ".trim($r['out'] ?? ''));
        Helpers::flash('ok', trim($r['out'] ?? ''));
        Helpers::redirect('/admin/mail');
    }
    // Single-message queue ops (id shape enforced here AND in the script).
    public function mailRetryOne(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/mail'); }
        $id = (string)($_POST['id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9]{6}-[A-Za-z0-9]{2,}-[A-Za-z0-9]{2,}$/', $id)) { Helpers::flash('err','Bad message id'); Helpers::redirect('/admin/mail'); }
        $r = ServiceManager::run('mail-queue.sh', ['retry-one', $id]);
        Helpers::log("mail retry-one $id by ".Auth::user()['username']);
        Helpers::flash('ok', trim($r['out'] ?? ''));
        Helpers::redirect('/admin/mail');
    }
    public function mailDropOne(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/mail'); }
        $id = (string)($_POST['id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9]{6}-[A-Za-z0-9]{2,}-[A-Za-z0-9]{2,}$/', $id)) { Helpers::flash('err','Bad message id'); Helpers::redirect('/admin/mail'); }
        $r = ServiceManager::run('mail-queue.sh', ['drop-one', $id]);
        // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string -- not SQL: audit log text; id format-validated
        Helpers::log("mail DROP-ONE $id by ".Auth::user()['username']);
        Helpers::flash('ok', trim($r['out'] ?? ''));
        Helpers::redirect('/admin/mail');
    }
}
