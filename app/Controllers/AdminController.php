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
        $alertDead = $alertEmail !== '' && self::alertUnroutable($alertEmail);
        $panelDomain = '';
        try { $panelDomain = (string)(Database::query("SELECT value FROM settings WHERE name='panel_domain'")->fetch()['value'] ?? ''); } catch (Throwable $e) {}
        // passkeys are bound to the panel host: changing it strands them
        $pkOnHost = 0;
        try { $pkOnHost = (int)Database::query("SELECT COUNT(*) c FROM user_passkeys WHERE rp_id=?", [strtolower($panelDomain)])->fetch()['c']; } catch (Throwable $e) {}
        // the cert :8090 really serves (one local TLS handshake, no privileges):
        // the dropdown shows THAT, and a panel host it doesn't cover is flagged
        // (browsers refuse passkeys on a certificate error)
        $svcCert = self::servedCert($panelDomain);
        $hostCovered = $panelDomain === '' || $svcCert === null || self::certCovers($svcCert['names'], strtolower($panelDomain));
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
        $this->view('admin_services', compact('services','uptime','alertEmail','alertDead','panelDomain','pkOnHost','malwareOn','malwareHour','watchdogAge','heartbeat','svcCert','hostCovered') + ['ssldoms' => Database::query("SELECT id,domain FROM domains WHERE ssl_enabled=1 ORDER BY domain")->fetchAll()]);
    }
    // Panel host (this box's own domain, e.g. panel.example.com). Powers the
    // setup-checklist glue step: the nightly dns-glue-check probes glue for
    // the panel's nameservers only once it knows which domain is this box's.
    // names on the certificate the panel listener serves; null when unreadable
    private static function servedCert(string $sni): ?array {
        $port = (int)($_SERVER['SERVER_PORT'] ?? 8090);
        if ($port < 1 || $port > 65535) $port = 8090;
        $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false,
            'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $sni !== '' ? $sni : 'localhost']]);
        $c = @stream_socket_client("ssl://127.0.0.1:$port", $en, $es, 2, STREAM_CLIENT_CONNECT, $ctx);
        if (!$c) return null;
        $cert = stream_context_get_params($c)['options']['ssl']['peer_certificate'] ?? null;
        fclose($c);
        $x = $cert ? openssl_x509_parse($cert) : false;
        if (!$x) return null;
        $names = [];
        foreach (explode(',', (string)($x['extensions']['subjectAltName'] ?? '')) as $n) {
            $n = strtolower(trim($n));
            if (str_starts_with($n, 'dns:')) $names[] = substr($n, 4);
        }
        $cn = strtolower((string)($x['subject']['CN'] ?? ''));
        if (!$names && $cn !== '') $names[] = $cn;
        return ['cn' => $cn, 'names' => $names, 'self' => ($x['subject'] ?? []) == ($x['issuer'] ?? [])];
    }
    private static function certCovers(array $names, string $host): bool {
        foreach ($names as $n) {
            if ($n === $host) return true;
            if (str_starts_with($n, '*.') && substr_count($host, '.') >= 2 && substr($host, strpos($host, '.')) === substr($n, 1)) return true;
        }
        return false;
    }
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
                $was = strtolower(trim((string)(Database::query("SELECT value FROM settings WHERE name='panel_domain'")->fetch()['value'] ?? '')));
                $stranded = 0;
                if ($was !== '' && $was !== $dom) {
                    try { $stranded = (int)Database::query("SELECT COUNT(*) c FROM user_passkeys WHERE rp_id=?", [$was])->fetch()['c']; } catch (Throwable $e2) {}
                }
                Database::query("INSERT INTO settings(name,value) VALUES('panel_domain',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [$dom]);
                try { ServiceManager::run('cron-glue.sh', ['on']); } catch (Throwable $e2) {}
                if ($stranded > 0) Helpers::log("panel host $was -> $dom: $stranded passkey(s) no longer usable");
                Helpers::flash('ok',"Panel host set to $dom — glue is re-checked nightly".($stranded > 0 ? ". $stranded passkey(s) made for $was stop working: owners use their app code or need a 2FA reset, then add new ones" : ''));
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
            foreach (ServiceManager::details() as $k => $v) if ($v['state'] === 'down') $svcs[] = $k;   // never "no answer"
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
    // An address on a domain this box hosts (mail handled here) with no
    // mailbox, forwarder or list: Exim refuses it, so the watchdog's alerts
    // and root's forwarded system mail bounce and freeze in the queue.
    private static function alertUnroutable(string $email): bool {
        $at = strrpos($email, '@');
        if ($at === false) return false;
        $lp = substr($email, 0, $at); $dn = substr($email, $at + 1);
        try {
            $d = Database::query("SELECT id, mail_handling FROM domains WHERE domain=?", [$dn])->fetch();
            if (!$d || $d['mail_handling'] !== 'local') return false;
            foreach (['email_accounts', 'email_forwards', 'email_lists'] as $t) {
                if (Database::query("SELECT 1 FROM $t WHERE domain_id=? AND localpart=? LIMIT 1", [$d['id'], $lp])->fetch()) return false;
            }
            return true;
        } catch (Throwable $e) { return false; }
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
                if (self::alertUnroutable($email)) Helpers::flash('err',"Saved, but $email has no mailbox or forwarder on this server — alerts and root's system mail will bounce. Add it under Email, or use an outside address.");
                else Helpers::flash('ok',"Downtime alerts will go to $email");
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
        $this->view('admin_migrate', ['pairings' => TransferController::pairings()]);
    }
    // Abuse board: live per-user CPU/RAM/processes (proc-live.sh, polled) +
    // per-customer quota/send context. Alerts stay with the 5-min
    // user-abuse.sh; its thresholds are mirrored here so "hot" means the same.
    public const ABUSE_CPU = 150;   // % of one core, summed per user
    public const ABUSE_MEM = 25;    // % of RAM per user
    public function abuse(): void {
        Auth::requireRole(['admin']);
        $users = [];
        // quotas table: fullest disk first (cached totals from quota-enforce),
        // 25 per page — the per-row mail/bandwidth reads were ~1000 calls at
        // 1000 accounts
        $q = substr(trim((string)($_GET['q'] ?? '')), 0, 64);
        $where = "role!='admin'".($q !== '' ? " AND username LIKE ?" : '');
        $args = $q !== '' ? ['%'.addcslashes($q, '%_\\').'%'] : [];
        $total = 0;
        try { $total = (int)Database::query("SELECT COUNT(*) c FROM users WHERE $where", $args)->fetch()['c']; } catch (Throwable $e) {}
        $pgr = Helpers::paginate($total, max(1, (int)($_GET['page'] ?? 1)));
        try {
            $rows = Database::query("SELECT * FROM users WHERE $where ORDER BY COALESCE(disk_used_mb,0)/GREATEST(disk_quota_mb,1) DESC, username LIMIT ? OFFSET ?",
                array_merge($args, [(int)$pgr['limit'], (int)$pgr['offset']]))->fetchAll();
            foreach ($rows as $x) {
                try { $x['disk_used_mb'] = Helpers::quota($x)['used_mb']; } catch (Throwable $t) { $x['disk_used_mb'] = 0; }
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
        try { foreach (Database::query("SELECT username, role, status FROM users")->fetchAll() as $x) $acct[$x['username']] = [$x['role'], $x['status']]; } catch (Throwable $e) {}
        $cmds = [];
        try {
            foreach (Database::query("SELECT u.username, d.name, d.command, d.workdir FROM app_daemons d JOIN users u ON u.id=d.user_id")->fetchAll() as $x) $cmds[$x['username'].'/'.$x['name']] = [$x['command'], $x['workdir']];
        } catch (Throwable $e) {}
        $out = self::abuseView($raw, $acct, $cmds);
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
        $valid = ['panel','exim','exim_reject','mail','vsftpd','fail2ban','litespeed','named','upgrade','phpext','update','software','apptoolkit','malware','offsite','certbot','transfer'];
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
        $smtp = self::smtpStatus();
        // accounts that keep filling their PHP workers + who was hitting
        // their sites each time (the dashboard/accounts warning links here).
        // Bot bursts included and labelled: this is where they're seen.
        $workers = [];
        foreach (array_slice(PhpUsage::workerHits(15, false), 0, 5) as [$who, $times, $days, $limit, $rt]) {
            $workers[] = ['user' => $who, 'times' => $times, 'days' => $days, 'limit' => $limit, 'bots' => $times - $rt, 'ev' => PhpUsage::workerCause($who)];
        }
        $blocked = [];
        foreach ($groups['blocked'] as $g) $blocked[$g['from']] = true;
        $this->view('admin_firewall', compact('active', 'groups', 'allow', 'myIp', 'smtp', 'workers', 'blocked'));
    }
    // smtp-restrict.sh status (TSV). ok=false when the script failed or was
    // refused (sudoers not re-rendered yet): callers show "unknown", never off.
    public static function smtpStatus(): array {
        $st = ['ok' => false, 'mode' => '?', 'live' => '?', 'hook' => '?', 'exempt' => [], 'restricted' => [], 'blocked' => 0, 'recent' => []];
        $r = ServiceManager::run('smtp-restrict.sh', ['status']);
        if (($r['code'] ?? 1) !== 0) return $st;
        foreach (preg_split('/\R/', (string)($r['out'] ?? '')) as $ln) {
            $f = explode("\t", $ln);
            switch ($f[0]) {
                case 'mode': case 'live': case 'hook': $st[$f[0]] = (string)($f[1] ?? ''); $st['ok'] = true; break;
                case 'exempt': case 'restricted': $st[$f[0]] = array_values(array_filter(explode(',', (string)($f[1] ?? '')))); break;
                case 'blocked': $st['blocked'] = (int)($f[1] ?? 0); break;
                case 'recent': if (isset($f[2])) $st['recent'][(string)$f[1]] = (int)$f[2]; break;
            }
        }
        return $st;
    }
    // outbound SMTP restriction: mode (off|mx|all) or one account exempt add/remove
    public function firewallSmtp(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/firewall#smtp'); }
        $op = (string)($_POST['op'] ?? '');
        if ($op === 'mode') {
            $mode = (string)($_POST['mode'] ?? '');
            if (!in_array($mode, ['off', 'mx', 'all'], true)) { Helpers::flash('err','Bad mode'); Helpers::redirect('/admin/firewall#smtp'); }
            $args = ['set', $mode];
        } elseif ($op === 'exempt-add' || $op === 'exempt-del') {
            $user = trim((string)($_POST['user'] ?? ''));
            if (!Helpers::validUsername($user)) { Helpers::flash('err','Bad account name'); Helpers::redirect('/admin/firewall#smtp'); }
            $args = [$op, $user];
        } else { Helpers::flash('err','Bad request'); Helpers::redirect('/admin/firewall#smtp'); }
        $r = ServiceManager::run('smtp-restrict.sh', $args, 60);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Not changed: '.trim($r['out']));
        else { Helpers::log('smtp restriction '.implode(' ', $args).' by '.Auth::user()['username']); Helpers::flash('ok', trim($r['out'])); }
        Helpers::redirect('/admin/firewall#smtp');
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
    // one port, one address (OLS WebAdmin 7080 for the owner's IP); a new
    // address is added here, the old one deleted from the Restricted list
    public function firewallAllowFrom(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/firewall'); }
        $port = (int)($_POST['port'] ?? 0);
        $proto = (($_POST['proto'] ?? '') === 'udp') ? 'udp' : 'tcp';
        $net = trim((string)($_POST['net'] ?? ''));
        [$ip, $len] = array_pad(explode('/', $net, 2), 2, null);
        if ($port < 1 || $port > 65535) { Helpers::flash('err','Bad port'); Helpers::redirect('/admin/firewall'); }
        if (!filter_var($ip, FILTER_VALIDATE_IP) || ($len !== null && !preg_match('/^[1-9]\d{0,2}$/', $len))) { Helpers::flash('err','Enter an IP address or network like 203.0.113.0/24'); Helpers::redirect('/admin/firewall'); }
        $r = ServiceManager::run('ufw-manage.sh', ['allow-from', $net, (string)$port, $proto]);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Allow failed: '.trim($r['out']));
        else { Helpers::log("firewall allow $port/$proto from $net by ".Auth::user()['username']); Helpers::flash('ok',"Allowed $port/$proto from $net"); }
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
        'weblogin-slow' => 'Slow WordPress password guessing (wp-login)',
        'webscan' => 'Website scanner probing for .env/.git files',
        'karnak-dav' => 'Repeated wrong calendar/contacts (CalDAV/CardDAV) passwords',
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
    // functions off, open_basedir, memory cap, niced), the account's own
    // master and its CPU/RAM caps. Re-apply regenerates
    // the pool from the hardened template — safe to run any time.
    public function phpPools(): void {
        Auth::requireRole(['admin']);
        $pools = [];
        $err = '';
        $r = ServiceManager::run('fpm-status.sh', [], 45);
        if (($r['code'] ?? 1) !== 0) $err = ($r['code'] ?? 1) === 124 ? 'The server took over 45 s to answer (systemd busy) — reload this page.' : 'Could not read the pools: '.trim((string)($r['out'] ?? ''));
        if (($r['code'] ?? 1) === 0) {
            foreach (explode("\n", trim($r['out'] ?? '')) as $line) {
                $p = explode("\t", $line);
                if (count($p) !== 6 && count($p) !== 11) continue;
                // 11 columns: + own/shared master, state, RAM used/cap MB, CPU cap % (fpm-account.sh)
                $pools[] = ['user' => $p[0], 'ver' => $p[1], 'children' => $p[2], 'hardened' => $p[3], 'memory' => $p[4], 'nice' => $p[5],
                    'master' => $p[6] ?? 'shared', 'state' => $p[7] ?? '-', 'ram' => $p[8] ?? '-', 'ramcap' => $p[9] ?? '-', 'cpucap' => $p[10] ?? '-'];
            }
        }
        // whole-box counts, then problems first + search + 25 per page (1000
        // pools in one table was unusable)
        $sum = ['all' => count($pools), 'active' => 0, 'problem' => 0];
        $bad = fn($p) => $p['hardened'] !== 'yes' || $p['master'] !== 'own' || !in_array($p['state'], ['active', 'idle'], true);
        foreach ($pools as $p) { if ($bad($p)) $sum['problem']++; elseif ($p['state'] === 'active') $sum['active']++; }
        $q = substr(trim((string)($_GET['q'] ?? '')), 0, 64);
        if ($q !== '') $pools = array_values(array_filter($pools, fn($p) => stripos($p['user'], $q) !== false));
        usort($pools, fn($a, $b) => [!$bad($a), $a['state'] !== 'active', $a['user'], $a['ver']] <=> [!$bad($b), $b['state'] !== 'active', $b['user'], $b['ver']]);
        $pg = Helpers::paginate(count($pools), max(1, (int)($_GET['page'] ?? 1)));
        $total = $pg['total']; $pages = $pg['pages'];
        $pools = array_slice($pools, $pg['offset'], $pg['limit']);
        $pg = $pg['pg'];
        $this->view('admin_pools', compact('pools', 'err', 'sum', 'q', 'pg', 'pages', 'total'));
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
        $sys = $this->sysPending();
        $auto = $this->autoUpdates();
        $this->view('admin_update', ['reboot' => $reboot, 'cur' => $cur, 'rels' => $rels, 'integ' => $integ, 'update' => $update, 'staged' => $staged, 'sys' => $sys, 'auto' => $auto]);
    }
    // automatic OS updates (auto-updates.sh get): the distro's unattended-upgrades
    private function autoUpdates(): array {
        $a = ['ok' => false, 'installed' => false, 'security' => true, 'reboot' => false, 'all' => false, 'hour' => 'os', 'next' => 0, 'last' => null];
        try {
            $r = ServiceManager::run('auto-updates.sh', ['get']);
            if (($r['code'] ?? 1) !== 0) return $a;
            $a['ok'] = true;
            foreach (explode("\n", (string)($r['out'] ?? '')) as $line) {
                $p = explode("\t", rtrim($line, "\r"));
                $v = $p[1] ?? '';
                switch ($p[0]) {
                    case 'installed': case 'security': case 'reboot': case 'all': $a[$p[0]] = $v === '1'; break;
                    case 'hour': $a['hour'] = preg_match('/^[0-9]{1,2}$/', $v) ? (int)$v : 'os'; break;
                    case 'next': $a['next'] = (int)$v; break;
                    case 'last': $a['last'] = ['at' => (int)$v, 'result' => in_array($p[2] ?? '', ['installed','failed','nothing'], true) ? $p[2] : 'nothing', 'count' => (int)($p[3] ?? 0)]; break;
                }
            }
        } catch (Throwable $e) {}
        return $a;
    }
    public function updateAuto(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/update'); }
        $sec = !empty($_POST['security']) ? '1' : '0';
        $rb = !empty($_POST['reboot']) ? '1' : '0';
        $all = ($sec === '1' && !empty($_POST['all'])) ? '1' : '0';
        $h = (string)($_POST['hour'] ?? 'os');
        if ($h !== 'os' && !(preg_match('/^[0-9]{1,2}$/', $h) && (int)$h <= 23)) $h = 'os';
        $r = ServiceManager::run('auto-updates.sh', ['set', $sec, $rb, $all, $h]);
        $out = trim((string)($r['out'] ?? ''));
        Helpers::log("automatic updates set security=$sec reboot=$rb all=$all hour=$h by ".Auth::user()['username'].": $out");
        if (($r['code'] ?? 1) === 0) Helpers::flash('ok', 'Automatic update settings saved');
        else Helpers::flash('err', 'Not saved: '.$out);
        Helpers::redirect('/admin/update#auto');
    }
    // OS packages waiting (system-update.sh status: current lists, no network)
    private function sysPending(): array {
        $sys = ['ok' => false, 'pkgs' => [], 'security' => 0, 'kept' => [], 'lists' => 0, 'reboot' => false, 'rebootPkgs' => '', 'auto' => false, 'running' => 'no'];
        try {
            $r = ServiceManager::run('system-update.sh', ['status']);
            if (($r['code'] ?? 1) !== 0) return $sys;
            $sys['ok'] = true;
            foreach (explode("\n", (string)($r['out'] ?? '')) as $line) {
                $p = explode("\t", rtrim($line, "\r"));
                switch ($p[0]) {
                    case 'pkg':
                        if (count($p) < 5) break;
                        $sys['pkgs'][] = ['name' => $p[1], 'from' => $p[2], 'to' => $p[3], 'kind' => $p[4] === 'security' ? 'security' : 'update', 'reboot' => ($p[5] ?? '0') === '1'];
                        if ($p[4] === 'security') $sys['security']++;
                        break;
                    case 'kept': if (isset($p[1])) $sys['kept'][] = $p[1]; break;
                    case 'lists': $sys['lists'] = (int)($p[1] ?? 0); break;
                    case 'reboot': $sys['reboot'] = ($p[1] ?? '') === '1'; $sys['rebootPkgs'] = trim($p[2] ?? ''); break;
                    case 'auto': $sys['auto'] = ($p[1] ?? '') === '1'; break;
                    case 'running': $sys['running'] = in_array($p[1] ?? '', ['panel','auto'], true) ? $p[1] : 'no'; break;
                }
            }
            // security first, then by name
            usort($sys['pkgs'], fn($a, $b) => [$a['kind'] !== 'security', $a['name']] <=> [$b['kind'] !== 'security', $b['name']]);
        } catch (Throwable $e) {}
        return $sys;
    }
    // Live console for a panel-started apt run: new log bytes since ?off=
    // ("last" = the latest run's start). Admin GET, read-only. The text is
    // package output: the page renders it with textContent, never as HTML.
    public function updateLog(): void {
        Auth::requireRole(['admin']);
        $this->tailJson('system-update.sh');
    }
    // Same live console for the panel's own update/rollback (update.log).
    public function panelUpdateLog(): void {
        Auth::requireRole(['admin']);
        $this->tailJson('panel-update.sh');
    }
    private function tailJson(string $script): void {
        $off = (string)($_GET['off'] ?? 'last');
        if ($off !== 'last' && !preg_match('/^[0-9]{1,12}$/', $off)) $off = 'last';
        $r = ServiceManager::run($script, ['tail', $off]);
        $out = (string)($r['out'] ?? '');
        $nl = strpos($out, "\n");
        $head = explode("\t", $nl === false ? $out : substr($out, 0, $nl));
        if (($r['code'] ?? 1) !== 0 || ($head[0] ?? '') !== 'state') $this->json(['error' => 'log unavailable'], 500);
        $text = $nl === false ? '' : substr($out, $nl + 1);
        // a read can end mid-character: hold back a partial UTF-8 tail
        $next = (int)($head[2] ?? 0);
        if ($text !== '' && !mb_check_encoding($text, 'UTF-8')) {
            for ($cut = 1; $cut <= 3 && $cut < strlen($text); $cut++) {
                if (mb_check_encoding(substr($text, 0, -$cut), 'UTF-8')) { $text = substr($text, 0, -$cut); $next -= $cut; break; }
            }
        }
        header('Cache-Control: no-store');
        $this->json(['state' => ($head[1] ?? '') === 'running' ? 'running' : 'idle', 'off' => $next,
            'text' => mb_scrub($text, 'UTF-8')]);
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
        $mode = ($_POST['mode'] ?? '') === 'go' ? 'go' : 'check';
        $r = ServiceManager::run('system-update.sh', [$mode]);
        $out = trim((string)($r['out'] ?? ''));
        Helpers::log("system update started mode=".($mode === 'go' ? 'upgrade' : 'check')." by ".Auth::user()['username'].": $out");
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err', 'Not started: '.$out);
        elseif ($out !== 'started') Helpers::flash('err', $out);
        else Helpers::flash('ok', $mode === 'go' ? 'Upgrade started — follow it live below' : 'Checking for updates — follow it live below');
        Helpers::redirect('/admin/update?live=1');
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
        self::panelRunFlash($r, 'Update');
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
        self::panelRunFlash($r, 'Update');
    }
    // update/rollback run detached: back to the Update page, where the live
    // card (top of the page) follows update.log until it finishes
    private static function panelRunFlash(array $r, string $what): void {
        $out = trim((string)($r['out'] ?? ''));
        if (($r['code'] ?? 1) !== 0) { Helpers::flash('err', "$what not started: $out"); Helpers::redirect('/admin/update'); }
        Helpers::flash('ok', "$what started — progress below");
        Helpers::redirect('/admin/update?plive=1');
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
        self::panelRunFlash($r, 'Rollback');
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
