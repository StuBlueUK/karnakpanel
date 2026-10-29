<?php
declare(strict_types=1);

class Helpers {
    public static function e(?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
    public static function redirect(string $url): void {
        header("Location: $url"); exit;
    }
    public static function csrf(): string {
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf'];
    }
    public static function checkCsrf(): bool {
        return isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', (string)$_POST['csrf']);
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
    // File-manager names: one path component. Dotfiles, spaces and ()+@,~
    // allowed; no leading '-', no trailing space, never "." / "..".
    // Keep in sync with FM_NAME_RE in scripts/fm-common.sh.
    public static function fmName(string $n): bool {
        return $n !== '.' && $n !== '..' && !str_ends_with($n, ' ')
            && (bool)preg_match('/^[a-zA-Z0-9_.][a-zA-Z0-9_. ()+@,~-]{0,127}$/', $n);
    }
    // A relative dir path for the file manager (same charset + '/', no "..").
    public static function fmRel(string $r): bool {
        return !str_contains($r, '..') && !str_starts_with($r, '/')
            && (bool)preg_match('#^[a-zA-Z0-9_. ()+@,~/-]{0,1024}$#', $r);
    }
    // Panel-owned dot paths at the home root (fm_panel_path in fm-common.sh):
    // never listed for actions, edited, downloaded or written.
    public static function fmPanelPath(string $rel, string $name = ''): bool {
        $first = explode('/', trim($rel.'/'.$name, '/'))[0];
        return in_array($first, ['.ssh', '.php-sessions', '.skypanel-cron', '.trash'], true);
    }
    // File-manager containment (answers the Semgrep symlink/prefix family):
    // realpath()+str_starts_with($t,$base) is NOT containment — a sibling
    // home sharing the string prefix (/home/bob2 vs /home/bob) passes, and
    // realpath() resolves (then hides) symlinks, so is_link() on the result
    // is a dead check. This walks every component with lstat and refuses
    // symlinks ANYWHERE in the user path, then enforces a trailing-slash
    // boundary. Returns the canonical in-base path, or null on any dodge.
    // Note: the manager therefore doesn't follow user symlinks at all
    // (apps may still use them; manage those targets via FTP/SSH).
    public static function fmResolve(string $base, string $rel, string $name): ?string {
        $base = rtrim($base, '/');
        if ($base === '' || $base === '/') return null;
        if (str_contains($rel, "\0") || str_contains($name, "\0")) return null;
        if (str_contains($rel, '..')) return null;
        // a "." name would resolve to the PARENT dir (delete = whole folder)
        if ($name === '.' || $name === '..') return null;
        $parts = array_values(array_filter(
            explode('/', trim($rel, '/').'/'.$name),
            fn($x) => $x !== '' && $x !== '.'
        ));
        if ($parts === []) return $base;
        $cur = $base;
        $n = count($parts);
        foreach ($parts as $i => $part) {
            if ($part === '..') return null;
            $cur .= '/'.$part;
            if (is_link($cur)) return null;
            if ($i < $n - 1 && !is_dir($cur)) return null;
        }
        if ($cur !== $base && !str_starts_with($cur, $base.'/')) return null;
        return $cur;
    }
    public static function quota(array $user): array {
        $quotaMb = max(1, (int)($user['disk_quota_mb'] ?? 5120));
        // admin owns the box: never quota-enforced (quota-enforce.sh /
        // bandwidth-enforce.sh skip it), so show "unlimited", not the
        // column default
        $unl = ($user['role'] ?? '') === 'admin';
        // display cache: the 5-min enforcer stores totals; use them when fresh
        // (<1h) so pages never walk trees. Enforcement measures live itself.
        // NULL/stale = one live measurement (first run, new user, odd state).
        if (isset($user['disk_used_mb']) && $user['disk_used_mb'] !== null
            && !empty($user['disk_measured_at'])
            && (time() - strtotime((string)$user['disk_measured_at']) < 3600)) {
            $usedMb = (float)$user['disk_used_mb'];
            $pct = $unl ? 0 : min(100, round($usedMb / $quotaMb * 100, 1));
            return ['used_mb'=>$usedMb,'quota_mb'=>$quotaMb,'pct'=>$pct,'mail_mb'=>0.0,
                    'bw_quota_gb'=>(int)($user['bandwidth_quota_gb'] ?? 100),'unlimited'=>$unl];
        }
        $dir = self::homedir($user['username']);
        $usedMb = 0.0;
        if (is_dir($dir)) {
            $out = [];
            // nosemgrep: php.lang.security.exec-use.exec-use -- path escapeshellarg'd; root from session username (homedir-validated)
            exec('du -sm '.escapeshellarg($dir).' 2>/dev/null', $out);
            if (isset($out[0]) && preg_match('/^([\d.]+)/', $out[0], $m)) $usedMb = (float)$m[1];
        }
        // virtual mail lives outside homes (/var/mail/virtual/<domain>) but
        // counts against the same quota; mailbox-level quotas cap the parts
        $mailMb = 0.0;
        try {
            $doms = Database::query("SELECT domain FROM domains WHERE user_id=?", [$user['id']])->fetchAll();
            foreach ($doms as $row) {
                $md = '/var/mail/virtual/'.$row['domain'];
                if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $row['domain']) || !is_dir($md)) continue;
                $o = [];
                // nosemgrep: php.lang.security.exec-use.exec-use -- path escapeshellarg'd; domain regex-validated + is_dir gated
                exec('du -sm '.escapeshellarg($md).' 2>/dev/null', $o);
                if (isset($o[0]) && preg_match('/^([\d.]+)/', $o[0], $m)) $mailMb += (float)$m[0];
            }
        } catch (Throwable $e) {}
        $usedMb += $mailMb;
        $pct = $unl ? 0 : min(100, round($usedMb / $quotaMb * 100, 1));
        return ['used_mb'=>$usedMb,'quota_mb'=>$quotaMb,'pct'=>$pct,'mail_mb'=>$mailMb,
                'bw_quota_gb'=>(int)($user['bandwidth_quota_gb'] ?? 100),'unlimited'=>$unl];
    }
    // Hard gate: error string when the user is at/over disk quota (fresh DB
    // read — session values may be stale). Admins are exempt (they own the box;
    // locking admin out helps nobody). Null = writes allowed.
    public static function diskBlocked(array $user): ?string {
        if (($user['role'] ?? '') === 'admin') return null;
        try {
            $fresh = Database::query("SELECT disk_quota_mb FROM users WHERE id=?", [$user['id']])->fetch();
            $quotaMb = max(1, (int)($fresh['disk_quota_mb'] ?? 5120));
        } catch (Throwable $e) { return null; } // fail open on DB error
        $q = self::quota(array_merge($user, ['disk_quota_mb' => $quotaMb]));
        if ($q['used_mb'] >= $quotaMb) {
            return 'Disk quota exceeded ('.self::fmtMb($q['used_mb']).' / '.self::fmtMb((float)$quotaMb).'). Delete files, mail or backups to add more.';
        }
        return null;
    }
    // Bytes left under the disk quota for archive extraction / copies (zip-bomb
    // and over-quota net handed to the root scripts). 0 = no cap (admins, or
    // the quota can't be read — fail open like diskBlocked).
    public static function diskLeftBytes(array $user): int {
        if (($user['role'] ?? '') === 'admin') return 0;
        try {
            $fresh = Database::query("SELECT disk_quota_mb FROM users WHERE id=?", [$user['id']])->fetch();
            $quotaMb = max(1, (int)($fresh['disk_quota_mb'] ?? 5120));
        } catch (Throwable $e) { return 0; }
        $q = self::quota(array_merge($user, ['disk_quota_mb' => $quotaMb]));
        return max(1, (int)(($quotaMb - $q['used_mb']) * 1048576));
    }
    // "1.2 GB / 5 GB (24%)", or "1.2 GB used / unlimited" for admin
    public static function quotaText(array $q): string {
        if (!empty($q['unlimited'])) return self::fmtMb((float)$q['used_mb']).' used / unlimited';
        return self::fmtMb((float)$q['used_mb']).' / '.self::fmtMb((float)$q['quota_mb']).' ('.$q['pct'].'%)';
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
    // Breached-password check (HaveIBeenPwned k-anonymity): only the first 5
    // SHA1 chars leave the box, full hash never transmitted. Returns breach
    // count, 0 = unseen, null = check unavailable (fail OPEN — never block a
    // password change on a network error). One HTTPS call, only at set-time.
    public static function passwordPwned(string $pass): ?int {
        try {
            $sha = strtoupper(sha1($pass));
            $pre = substr($sha, 0, 5);
            $body = null;
            if (function_exists('curl_init')) {
                $ch = curl_init('https://api.pwnedpasswords.com/range/'.$pre);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
                    CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_USERAGENT => 'KarnakPanel-pwncheck',
                    CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
                $body = curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($body === false || $code !== 200) return null;
            } else {
                $ctx = stream_context_create(['http' => ['timeout' => 5, 'header' => 'User-Agent: KarnakPanel-pwncheck'],
                    'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
                $body = @file_get_contents('https://api.pwnedpasswords.com/range/'.$pre, false, $ctx);
                if ($body === false) return null;
            }
            foreach (explode("\n", (string)$body) as $line) {
                $p = explode(':', trim($line));
                if (count($p) === 2 && strtoupper($p[0]) === substr($sha, 5)) return (int)$p[1];
            }
            return 0;
        } catch (Throwable $e) { return null; }
    }
    // Panel version for the dashboard footer (panel/VERSION, shipped by
    // panel-deploy + UI packages; '' when absent, e.g. pre-release layouts).
    public static function panelVersion(): string {
        $v = @file_get_contents(dirname(__DIR__, 2).'/VERSION');
        $v = $v === false ? '' : trim($v);
        return preg_match('/^[a-zA-Z0-9._-]{1,16}$/', $v) ? $v : '';
    }
    // Reseller allocation: sum of direct clients' quotas must fit the reseller's own.
    // Returns error string when the given package would overflow, null when OK.
    // Admins and packageless resellers are unchecked (null).
    public static function allocationError(array $reseller, array $pkg): ?string {
        if (($reseller['role'] ?? '') !== 'reseller') return null;
        try {
            $me = Database::query("SELECT disk_quota_mb,bandwidth_quota_gb FROM users WHERE id=?", [$reseller['id']])->fetch();
            if (!$me || (int)$me['disk_quota_mb'] <= 0) return null;
            $used = Database::query("SELECT COALESCE(SUM(disk_quota_mb),0) d, COALESCE(SUM(bandwidth_quota_gb),0) b FROM users WHERE owner_id=? AND status='active'", [$reseller['id']])->fetch();
            if ((int)$used['d'] + (int)$pkg['disk_quota_mb'] > (int)$me['disk_quota_mb']) {
                return "Reseller disk allocation exceeded: ".((int)$used['d'])." + ".((int)$pkg['disk_quota_mb'])." > ".((int)$me['disk_quota_mb'])." MB. Ask admin to raise your quota.";
            }
            if ((int)$used['b'] + (int)$pkg['bandwidth_quota_gb'] > (int)$me['bandwidth_quota_gb']) {
                return "Reseller bandwidth allocation exceeded. Ask admin to raise your quota.";
            }
        } catch (Throwable $e) { return null; }
        return null;
    }
    public static function allocationUsage(array $reseller): ?array {
        try {
            $me = Database::query("SELECT disk_quota_mb,bandwidth_quota_gb FROM users WHERE id=?", [$reseller['id']])->fetch();
            $used = Database::query("SELECT COALESCE(SUM(disk_quota_mb),0) d, COALESCE(SUM(bandwidth_quota_gb),0) b, COUNT(*) c FROM users WHERE owner_id=? AND status='active'", [$reseller['id']])->fetch();
            return ['disk_used' => (int)$used['d'], 'disk_quota' => (int)$me['disk_quota_mb'],
                    'bw_used' => (int)$used['b'], 'bw_quota' => (int)$me['bandwidth_quota_gb'],
                    'clients' => (int)$used['c']];
        } catch (Throwable $e) { return null; }
    }
    // Billable bandwidth this calendar month (bytes) for a user, or null on DB error.
    public static function bandwidthMonthBytes(array $user): ?int {
        try {
            $r = Database::query(
                "SELECT COALESCE(SUM(bytes),0) s FROM bandwidth_daily WHERE user_id=? AND day >= DATE_FORMAT(CURDATE(),'%Y-%m-01')",
                [$user['id']])->fetch();
            return (int)$r['s'];
        } catch (Throwable $e) { return null; }
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
    // Package limit check. Returns error string when limit reached, null when allowed.
    public static function limitError(array $user, string $kind): ?string {
        $map = [
            'domains' => ['domains', 'max_domains'],
            'emails'  => ['email_accounts', 'max_emails'],
            'dbs'     => ['mysql_dbs', 'max_dbs'],
            'ftps'    => ['ftp_accounts', 'max_ftp'],
            'builder' => ['builder_sites', 'max_builder_sites'],
        ];
        if (!isset($map[$kind])) return 'Unknown limit kind';
        [$table, $maxCol] = $map[$kind];
        try {
            $pkgId = Database::query("SELECT package_id FROM users WHERE id=?", [$user['id']])->fetch()['package_id'] ?? null;
            if (!$pkgId) return null;
            $pkg = Database::query("SELECT * FROM packages WHERE id=?", [$pkgId])->fetch();
            if (!$pkg) return null;
            $count = (int)Database::query("SELECT COUNT(*) c FROM {$table} WHERE user_id=?", [$user['id']])->fetch()['c'];
            if ($count >= (int)$pkg[$maxCol]) {
                return "Package '{$pkg['name']}' limit reached: {$pkg[$maxCol]} {$kind} used. Ask your reseller to upgrade.";
            }
        } catch (Throwable $e) { return null; } // fail open on DB error; create flow reports its own errors
        return null;
    }
}
