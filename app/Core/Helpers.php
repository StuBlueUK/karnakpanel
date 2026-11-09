<?php
declare(strict_types=1);

class Helpers {
    public static function e(?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
    /** Ends the request. Declared never so callers need no dead `return`. */
    public static function redirect(string $url): never {
        header("Location: $url"); exit;
    }
    public static function csrf(): string {
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf'];
    }
    public static function checkCsrf(): bool {
        // fail closed: an unminted token must never equal an empty POST field
        if (empty($_SESSION['csrf']) || !isset($_POST['csrf']) || !is_string($_POST['csrf'])) return false;
        return hash_equals((string)$_SESSION['csrf'], $_POST['csrf']);
    }
    // the box's zone (PHP here runs UTC): ban and log times then match the
    // host's own log lines. One copy — admin controllers and cron both use it.
    public static function hostTz(): DateTimeZone {
        $n = trim((string)@file_get_contents('/etc/timezone'));
        if ($n === '' && preg_match('#zoneinfo/(.+)$#', (string)@readlink('/etc/localtime'), $m)) $n = $m[1];
        try { return new DateTimeZone($n !== '' ? $n : 'UTC'); } catch (Throwable $e) { return new DateTimeZone('UTC'); }
    }
    public static function flash(string $key, ?string $msg = null) {
        if ($msg !== null) { $_SESSION['flash_'.$key] = $msg; return; }
        $m = $_SESSION['flash_'.$key] ?? null;
        unset($_SESSION['flash_'.$key]);
        return $m;
    }
    // $stdin: fed to the command (ServiceManager's Secret args) — never logged
    public static function sysExec(string $cmd, ?string $stdin = null): array {
        // Whitelisted wrapper — all privileged actions go through sudo scripts.
        // ServiceManager prepends `timeout N`; strip it before the whitelist check.
        $check = preg_replace('/^timeout\s+\d+\s+/', '', $cmd);
        $allowed = Config::get('security.allowed_bin', ['/usr/local/skypanel/scripts/']);
        $ok = false;
        foreach ((array)$allowed as $prefix) {
            if (str_starts_with($cmd, $prefix) || str_starts_with($check, $prefix)) { $ok = true; break; }
        }
        if (!$ok) return ['code'=>403,'out'=>'blocked'];
        // nosemgrep: php.lang.security.exec-use.exec-use -- privileged gateway by design: basename + escapeshellarg + prefix whitelist in sysExec
        $p = proc_open($cmd.' 2>&1', [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) return ['code'=>500,'out'=>'exec failed'];
        if ($stdin !== null) fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        // Read until EOF — except after `timeout` fired (124/137): sudo's
        // use_pty leaves the script's children (certbot, tar…) orphaned but
        // holding stdout, and exec() waited for them anyway. Then return now.
        stream_set_blocking($pipes[1], false);
        $raw = ''; $code = null;
        while (true) {
            $r = [$pipes[1]]; $w = $e = null;
            if (@stream_select($r, $w, $e, 1) > 0) {
                $chunk = fread($pipes[1], 65536);
                if ($chunk === false || ($chunk === '' && feof($pipes[1]))) break;
                $raw .= $chunk;
            }
            if ($code === null) {
                $st = proc_get_status($p);
                if (!$st['running']) $code = (int)$st['exitcode'];
            }
            if ($code === 124 || $code === 137) {
                while (($chunk = fread($pipes[1], 65536)) !== false && $chunk !== '') $raw .= $chunk;
                break;
            }
        }
        fclose($pipes[1]);
        $rc = proc_close($p);
        if ($code === null) $code = $rc;
        // same shape as exec(): lines, trailing whitespace stripped
        $out = $raw === '' ? [] : array_map('rtrim', explode("\n", rtrim($raw, "\n")));
        self::log("exec: $cmd => $code");
        return ['code'=>$code,'out'=>implode("\n",$out)];
    }
    public static function log(string $msg): void {
        $f = Config::get('paths.logs', __DIR__.'/../../storage/logs/panel.log');
        @file_put_contents($f, date('c')." $msg\n", FILE_APPEND);
    }
    // RFC 1123 labels (digits/hyphens anywhere but a label's ends, <=63
    // chars) + letters-only TLD — the shape scripts/*.sh accept. The old
    // rule allowed digits/hyphens in the FIRST label only, so
    // ns1.my-brand.com or shop.site2.com were refused.
    public static function validDomain(string $d): bool {
        return (bool)preg_match('/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $d);
    }
    // Host name FTP clients must connect to: vsftpd serves ONE cert to every
    // login (no SNI), so it is the LE lineage in rsa_cert_file. null = not a
    // Let's Encrypt cert (self-signed fallback: no name will match).
    public static function ftpTlsHost(string $vsftpdConf): ?string {
        if (!preg_match('#^rsa_cert_file=/etc/letsencrypt/live/([^/\s]+)/#m', $vsftpdConf, $m)) return null;
        $h = strtolower($m[1]);
        return self::validDomain($h) ? $h : null;
    }
    public static function validUsername(string $u): bool {
        if (!preg_match('/^[a-z][a-z0-9_]{2,31}$/', $u)) return false;
        // reserved: existing system users ('admin' is allowed — it is the
        // default panel owner, created with a safe group). create-account.sh
        // refuses ANY existing Linux user anyway (exit 3); see validNewUsername.
        static $reserved = ['root','daemon','bin','sys','sync','games','man','lp','mail','news','uucp','proxy','www-data','backup','nobody','sshd','messagebus','systemd-network','systemd-resolve','uuidd','dnsmasq','landscape','pollinate','vmail','Debian-exim','clamav','spamd','mysql','bind','named','www','ftp','postfix','dovecot','spamassassin','postgres'];
        return !in_array($u, $reserved, true);
    }
    // creation-only: RFC 2142 role mailboxes — a unix user by these names
    // receives <name>@<box hostname> via exim's localuser router. Kept out of
    // validUsername so accounts created before 1.5.31 stay manageable.
    /** Log-viewer severity ('err'|'warn'|'info') for one line, timestamp already stripped. */
    public static function logLevel(string $l): string {
        // runner lines carry their own verdict: judge by exit code, not by
        // words in script names/args (fail2ban-status, malware-scan, ...)
        if (preg_match('/^exec: .* => (-?\d+)$/', $l, $m)) return $m[1] === '0' ? 'info' : 'err';
        // prefix stems (no trailing \b): catches warning/blocked/rejected.
        // "timeout 15" is the runner's own guard prefix, not a failure —
        // exclude it or every exec line paints red and real errors hide.
        if (preg_match('/\b(error|fail|fatal|critical|deni|reject|refus|block|bann|attack|malware|panic|timeout(?! 15\b)|timed out|unreachable|no such|unknown log)/i', $l)) return 'err';
        elseif (preg_match('/\b(warn|defer|retry|slow|deprecat|insecure|fallback|skipp)/i', $l)) return 'warn';
        return 'info';
    }
    public static function validNewUsername(string $u): bool {
        // + distro/cloud login users and privileged group names: create-account.sh
        // refuses an existing Linux user anyway; this gives the clean message
        // early. Creation-only for the same reason as the role mailboxes.
        static $distro = [
            'ubuntu','debian','centos','fedora','rocky','almalinux','alma','cloud','azureuser','opc','vagrant','lsadm','syslog','tss','tcpdump','polkitd','chrony','usbmux','lxd','sudo','adm','wheel','staff','docker','shadow','disk','redis','memcache','vsftpd','exim','sssd','ntp','avahi','rpc','apache','nginx','lighttpd','mariadb','postgrey','opendkim','roundcube','phpmyadmin','skypanel','karnakpanel'];
        return self::validUsername($u) && !in_array($u, ['postmaster','abuse','hostmaster','webmaster','security'], true)
            && !in_array($u, $distro, true);
    }
    public static function validEmail(string $e): bool {
        if (filter_var($e, FILTER_VALIDATE_EMAIL)) return true;
        // allow single-label/local domains (admin@localhost, dev boxes)
        return (bool)preg_match('/^[^@\s]+@[a-z0-9-]+(\.[a-z0-9-]+)*$/i', $e);
    }
    // Account home root: /home/<user> (layout: /home/<user>/<domain>/public_html).
    // Production: real home. Dev: storage/homes/<user>.
    public static function homedir(string $username): string {
        $prod = "/home/{$username}";
        if (is_dir($prod)) return (string)realpath($prod);
        $dev = __DIR__ . "/../../storage/homes/{$username}";
        if (!is_dir($dev)) {
            @mkdir($dev, 0755, true);
            @file_put_contents($dev.'/index.html', "<h1>Welcome {$username} — KarnakPanel dev home</h1>");
        }
        $real = realpath($dev);
        return $real !== false ? $real : $dev;
    }
    // Plain-English reading of a 5-field cron schedule ("every 5 min",
    // "daily at 02:00", "Sundays at 04:00"); null when it is too unusual to
    // say briefly — callers then show only the raw expression.
    public static function cronHuman(string $sched): ?string {
        $f = preg_split('/\s+/', trim($sched));
        if (count($f) !== 5) return null;
        [$mi, $h, $dom, $mon, $dow] = $f;
        $days = ['Sundays','Mondays','Tuesdays','Wednesdays','Thursdays','Fridays','Saturdays','Sundays'];
        $num = fn($v) => preg_match('/^\d{1,2}$/', $v) === 1;
        $list = fn($v) => preg_match('/^\d{1,2}(,\d{1,2})+$/', $v) === 1;
        $at = fn($hh, $mm) => sprintf('%02d:%02d', (int)$hh, (int)$mm);
        if ($mon !== '*') return null;
        // minute-level schedules: every hour of every day only
        if ($h === '*' && $dom === '*' && $dow === '*') {
            if ($mi === '*') return 'every minute';
            if (preg_match('#^(?:\*|\d{1,2}-59)/(\d{1,2})$#', $mi, $m)) return 'every '.(int)$m[1].' min';
            if ($num($mi)) return 'hourly at :'.sprintf('%02d', (int)$mi);
            if ($list($mi)) { $ms = explode(',', $mi); return count($ms) === 2 ? 'twice an hour (:'.implode(', :', array_map(fn($x) => sprintf('%02d', (int)$x), $ms)).')' : count($ms).' times an hour'; }
            return null;
        }
        if (!$num($mi)) return null;
        if ($dom === '*' && $dow === '*') {
            if (preg_match('#^\*/(\d{1,2})$#', $h, $m)) return 'every '.(int)$m[1].' hours at :'.sprintf('%02d', (int)$mi);
            if ($num($h)) return 'daily at '.$at($h, $mi);
            if ($list($h)) return 'daily at '.implode(' and ', array_map(fn($x) => $at($x, $mi), explode(',', $h)));
            return null;
        }
        if (!$num($h)) return null;
        if ($dom === '*' && preg_match('/^[0-7]$/', $dow)) return $days[(int)$dow].' at '.$at($h, $mi);
        if ($dow === '*' && $num($dom) && (int)$dom >= 1 && (int)$dom <= 31) {
            $d = (int)$dom; $suf = ($d % 100 >= 11 && $d % 100 <= 13) ? 'th' : (['th','st','nd','rd'][$d % 10] ?? 'th');
            return 'monthly on the '.$d.$suf.' at '.$at($h, $mi);
        }
        return null;
    }
    public static function fmtMb(float $mb): string {
        if ($mb >= 1024) return round($mb/1024, 2).' GB';
        // under 0.1 MB "0 MB" read as empty (3 KB backups) — show KB instead
        if ($mb > 0 && $mb < 0.1) return max(1, (int)round($mb*1024)).' KB';
        return round($mb, 1).' MB';
    }
    // Mail local part that is safe as ONE path component under
    // /var/mail/virtual/<domain>/ (email-add.sh, Dovecot %n): no ".", "..",
    // leading/trailing dot or "..". Mirrors lp_ok in scripts/as-user.sh.
    public static function localpartOk(string $l, bool $anyCase = false): bool {
        return (bool)preg_match('/^(?!.*\.\.)[a-z0-9_-]([a-z0-9._-]{0,62}[a-z0-9_-])?$/' . ($anyCase ? 'i' : ''), $l);
    }
    // Panel version for the dashboard footer (panel/VERSION, shipped by
    // panel-deploy + UI packages; '' when absent, e.g. pre-release layouts).
    public static function panelVersion(): string {
        $v = @file_get_contents(dirname(__DIR__, 2).'/VERSION');
        $v = $v === false ? '' : trim($v);
        return preg_match('/^[a-zA-Z0-9._-]{1,16}$/', $v) ? $v : '';
    }
    // PHP pool size for a user from their package (null = script default 10).
    public static function poolChildren(array $user): ?int {
        try {
            $pkgId = Database::query("SELECT package_id FROM users WHERE id=?", [$user['id']])->fetch()['package_id'] ?? null;
            if (!$pkgId) return null;
            $c = (int)(Database::query("SELECT max_children FROM packages WHERE id=?", [$pkgId])->fetch()['max_children'] ?? 10);
            return max(2, min(50, $c));
        } catch (Throwable $e) { return null; }
    }
    // Notification center feed. $userId null = admin/system scope.
    // Hourly dedup on (user,type,title) so brute-force storms log once, not 500x.
    public static function notify(?int $userId, string $type, string $title, string $body = ''): void {
        try {
            $dupe = Database::query(
                "SELECT COUNT(*) c FROM notifications WHERE (user_id <=> ?) AND type=? AND title=? AND created_at > (NOW() - INTERVAL 1 HOUR)",
                [$userId, substr($type,0,32), substr($title,0,190)])->fetch()['c'] ?? 0;
            if ((int)$dupe > 0) return;
            Database::query("INSERT INTO notifications(user_id,type,title,body) VALUES(?,?,?,?)",
                [$userId, substr($type,0,32), substr($title,0,190), substr($body,0,2000)]);
        } catch (Throwable $e) {}
    }
    public static function unreadCount(array $user): int {
        try {
            if ($user['role'] === 'admin') {
                return (int)Database::query("SELECT COUNT(*) c FROM notifications WHERE read_at IS NULL")->fetch()['c'];
            }
            return (int)Database::query("SELECT COUNT(*) c FROM notifications WHERE read_at IS NULL AND user_id=?", [$user['id']])->fetch()['c'];
        } catch (Throwable $e) { return 0; }
    }
    // Service URLs (webmail/phpMyAdmin) derived from the CURRENT host, not a
    // stored IP: certs are issued to domain names, so IP-based links always
    // warn. Falls back to configured URLs only when the host is unusable.
    public static function serviceUrl(int $port, string $fallbackKey): string {
        $host = strtolower(trim(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]));
        if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $host)) return "https://$host:$port";
        $cfg = (string)Config::get($fallbackKey, '');
        return $cfg !== '' ? $cfg : "https://$host:$port";
    }
    // Pagination clamp. Returns pg/pages/total/offset/limit for views.
    // View vars MUST be named pg/pages/total (never $page: Controller::view()
    // takes $page as the template name and extract() would clobber it).
    public const PAGE_SIZE = 25;
    public static function paginate(int $total, int $page, int $per = self::PAGE_SIZE): array {
        $pages = max(1, (int)ceil(max(0, $total) / $per));
        $pg = min(max(1, $page), $pages);
        return ['pg' => $pg, 'pages' => $pages, 'total' => $total, 'offset' => ($pg - 1) * $per, 'limit' => $per];
    }
}
