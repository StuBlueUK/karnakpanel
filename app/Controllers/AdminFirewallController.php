<?php
declare(strict_types=1);

class AdminFirewallController extends Controller {

    // jails guarding the admin surface: any ban there is worth a look
    private const BAN_SERIOUS_JAILS = ['panel', 'phpmyadmin'];

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
}
