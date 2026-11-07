<?php
declare(strict_types=1);
// Admin › Security Advisor: one page of checks, each with a link to its fix.
// Root-only state comes from security-audit.sh (live: sshd -T, ufw,
// iptables); the rest from the DB. A check that can't be read is "unknown"
// and scores nothing — never shown as passing. Known isolation limits are a
// fixed list on the page, never a check.
class SecurityController extends Controller {
    // weight 2 = a hole on its own; 1 = hardening
    private const ROOT = [
        'ssh_root'     => ['SSH root login', '/admin/ssh', 2],
        'ssh_password' => ['SSH password logins', '/admin/ssh', 1],
        'ufw'          => ['Firewall', '/admin/firewall', 2],
        'ols_admin'    => ['LiteSpeed WebAdmin exposure', '/admin/firewall', 1],
        'fail2ban'     => ['Brute-force protection (fail2ban)', '/admin/services', 2],
        'smtp'         => ['Outbound SMTP restriction', '/admin/firewall#smtp', 1],
        'fpm_caps'     => ['Per-account PHP resource caps', '/admin/php-pools', 1],
        'mysql_net'    => ['MariaDB network exposure', '/admin/services', 1],
        'updates'      => ['Security updates', '/admin/update', 1],
        'apt_lists'    => ['Package lists', '/admin/update', 1],
        'auto_updates' => ['Automatic security updates', '/admin/update', 1],
        'reboot'       => ['Pending reboot', '/admin/services', 1],
    ];

    public function index(): void {
        Auth::requireRole(['admin']);
        $checks = [];
        $add = function (string $key, string $title, string $status, string $detail, string $fix, int $w = 1) use (&$checks): void {
            $checks[] = ['key' => $key, 'title' => $title, 'status' => $status, 'detail' => $detail, 'fix' => $fix, 'w' => $w];
        };

        // root-side checks
        $seen = [];
        // 75 s: the script's own per-check timeouts add up to ~65 s on a busy box
        $r = ServiceManager::run('security-audit.sh', [], 75);
        if (($r['code'] ?? 1) === 0) {
            foreach (preg_split('/\R/', (string)($r['out'] ?? '')) as $ln) {
                $f = explode("\t", $ln, 3);
                if (count($f) !== 3 || !isset(self::ROOT[$f[0]])) continue;
                [$t, $fix, $w] = self::ROOT[$f[0]];
                $st = in_array($f[1], ['ok', 'warn', 'bad', 'unknown'], true) ? $f[1] : 'unknown';
                $add($f[0], $t, $st, $f[2], $fix, $w);
                $seen[$f[0]] = true;
            }
        }
        foreach (self::ROOT as $k => [$t, $fix, $w]) {
            if ($k === 'apt_lists' || isset($seen[$k])) continue;   // apt_lists only reports when stale
            $add($k, $t, 'unknown', ($r['code'] ?? 1) === 0 ? 'not reported' : (($r['code'] ?? 1) === 124 ? 'the server checks took over 75 s (server busy) — reload this page' : 'server check failed: '.trim(substr((string)($r['out'] ?? ''), 0, 160))), $fix, $w);
        }

        // accounts: two-factor
        try {
            $req = (string)(Database::query("SELECT value FROM settings WHERE name='totp_required'")->fetch()['value'] ?? '');
            $no = Database::query("SELECT username, role FROM users WHERE role IN ('admin','reseller') AND NOT ".Auth::HAS_2FA." ORDER BY role, username")->fetchAll();
            $adm = array_column(array_filter($no, fn($x) => $x['role'] === 'admin'), 'username');
            $res = array_column(array_filter($no, fn($x) => $x['role'] === 'reseller'), 'username');
            $add('admin_2fa', 'Two-factor on admin accounts', $adm ? 'bad' : 'ok', $adm ? 'without 2FA: '.self::names($adm) : 'every admin uses 2FA', '/2fa', 2);
            $add('reseller_2fa', 'Two-factor on reseller accounts', $res ? 'warn' : 'ok', $res ? 'without 2FA: '.self::names($res) : 'every reseller uses 2FA (or there are none)', '/2fa');
            $add('totp_required', 'Two-factor required for admins and resellers', $req === '1' ? 'ok' : 'warn', $req === '1' ? 'enforced at login' : 'optional: an admin or reseller can skip it', '/2fa');
        } catch (Throwable $e) {
            $add('admin_2fa', 'Two-factor on admin accounts', 'unknown', 'database query failed', '/2fa', 2);
        }

        // sites
        try {
            $d = Database::query("SELECT COUNT(*) n, SUM(sec_headers=0) hdr, SUM(waf=0) waf, SUM(ssl_enabled=0) nossl,
                SUM(ssl_enabled=1 AND ssl_expires IS NOT NULL AND ssl_expires <= CURDATE() + INTERVAL 14 DAY) exp FROM domains")->fetch();
            $n = (int)($d['n'] ?? 0);
            $sites = Database::query("SELECT domain, user_id, sec_headers, waf, ssl_enabled FROM domains ORDER BY domain")->fetchAll();
            $headersOff = (int)$d['hdr'];
            $hdrBad = array_filter($sites, fn($r) => (int)$r['sec_headers'] === 0);
            $add('headers', 'Security headers', $headersOff ? 'warn' : 'ok', $headersOff ? "$headersOff of $n site(s) without the safe headers: ".self::names(array_column($hdrBad, 'domain')) : "all $n site(s) send them", self::domainsTab($hdrBad));
            $add('shield', 'Bot shield', (int)$d['waf'] ? 'warn' : 'ok', (int)$d['waf'] ? (int)$d['waf']." of $n site(s) have the shield off" : "on for all $n site(s)", self::domainsTab(array_filter($sites, fn($r) => (int)$r['waf'] === 0)));
            $add('ssl', 'HTTPS certificates', (int)$d['nossl'] || (int)$d['exp'] ? 'warn' : 'ok',
                ((int)$d['nossl'] ? (int)$d['nossl'].' site(s) without a certificate' : 'every site has a certificate').((int)$d['exp'] ? ', '.(int)$d['exp'].' expiring within 14 days' : ''), self::domainsTab(array_filter($sites, fn($r) => (int)$r['ssl_enabled'] === 0)));
            // legacy PHP (PhpVersions::LEGACY: served, never offered)
            $old = [];
            foreach (PhpVersions::LEGACY as $v => $eol) {
                $c = (int)(Database::query("SELECT COUNT(*) c FROM domains WHERE php_version=?", [$v])->fetch()['c'] ?? 0);
                if ($c) $old[] = "$c on PHP $v (security fixes end $eol)";
            }
            $add('php_legacy', 'Old PHP versions', $old ? 'warn' : 'ok', $old ? 'site(s) '.implode(', ', $old) : 'no site on a legacy PHP version', '/domains');
        } catch (Throwable $e) {
            $add('headers', 'Site settings', 'unknown', 'database query failed', '/domains');
        }
        $noexecOff = 0;
        try {
            $nx = Database::query("SELECT domain, user_id FROM domains WHERE noexec=0")->fetchAll();
            $noexecOff = count($nx);
            $add('noexec', 'PHP blocked in upload folders', $noexecOff ? 'warn' : 'ok', $noexecOff ? "$noexecOff site(s) still run PHP files from upload folders (where webshells are dropped)" : 'blocked on every site', self::domainsTab($nx));
        } catch (Throwable $e) {
            $add('noexec', 'PHP blocked in upload folders', 'unknown', 'needs migration 061 (run the panel update)', '/admin/update');
        }
        try {
            $w = Database::query("SELECT COUNT(*) n, SUM(update_available=1) core, SUM(plugins_available) plug FROM app_versions")->fetch();
            $core = (int)($w['core'] ?? 0); $plug = (int)($w['plug'] ?? 0);
            if ((int)($w['n'] ?? 0) === 0) $add('wp', 'WordPress updates', 'unknown', 'no scan results yet (Fleet Updates scans nightly)', '/fleet');
            else $add('wp', 'WordPress updates', ($core || $plug) ? 'warn' : 'ok', ($core || $plug) ? "$core core and $plug plugin update(s) pending" : 'every scanned install is up to date', '/fleet');
        } catch (Throwable $e) {
            $add('wp', 'WordPress updates', 'unknown', 'database query failed', '/fleet');
        }

        // score: ok = full weight, warn = half, bad and unknown = nothing
        $got = 0.0; $max = 0;
        foreach ($checks as $c) {
            $max += $c['w'];
            $got += $c['status'] === 'ok' ? $c['w'] : ($c['status'] === 'warn' ? $c['w'] / 2 : 0);
        }
        $score = $max ? (int)round(100 * $got / $max) : 0;
        $rank = ['bad' => 0, 'warn' => 1, 'unknown' => 2, 'ok' => 3];
        usort($checks, fn($a, $b) => [$rank[$a['status']], -$a['w']] <=> [$rank[$b['status']], -$b['w']]);
        $counts = array_count_values(array_column($checks, 'status'));
        $headersOff = $headersOff ?? 0;
        $this->view('admin_security', compact('checks', 'score', 'counts', 'noexecOff', 'headersOff'));
    }

    // "Turn on for all sites": every domain still without the upload block.
    // One reload at the end, not one per site.
    public function noexecAll(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/security'); }
        $rows = Database::query("SELECT domain, noexec_paths FROM domains WHERE noexec=0 ORDER BY domain")->fetchAll();
        $ok = 0; $fail = [];
        foreach ($rows as $d) {
            $args = [$d['domain'], 'on'];
            foreach (array_filter(explode(',', (string)$d['noexec_paths'])) as $p) { $args[] = '--path'; $args[] = $p; }
            $args[] = '--no-reload';
            $r = ServiceManager::run('vhost-noexec.sh', $args);
            if (($r['code'] ?? 1) === 0) $ok++; else $fail[] = $d['domain'].': '.trim((string)$r['out']);
        }
        if ($ok) ServiceManager::run('service-control.sh', ['reload', 'openlitespeed'], 60);
        Helpers::log("noexec on for $ok site(s) by ".Auth::user()['username']);
        if ($fail) Helpers::flash('err', "PHP blocked in uploads on $ok site(s); failed: ".implode('; ', array_slice($fail, 0, 5)));
        else Helpers::flash('ok', "PHP blocked in upload folders on $ok site(s)");
        Helpers::redirect('/admin/security');
    }

    // "Turn on for all sites": the three safe headers (nosniff, SAMEORIGIN
    // frames, Referrer-Policy) on every site still without them, domains AND
    // subdomains. HSTS/CSP stay per-site opt-in (they can lock out or break a
    // site). One reload at the end.
    public function headersAll(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/security'); }
        $rows = Database::query("SELECT domain FROM domains WHERE sec_headers=0 ORDER BY domain")->fetchAll();
        $ok = 0; $fail = [];
        foreach ($rows as $d) {
            $r = ServiceManager::run('vhost-headers.sh', [$d['domain'], 'on', '--no-reload']);
            if (($r['code'] ?? 1) === 0) $ok++; else $fail[] = $d['domain'].': '.trim((string)$r['out']);
        }
        if ($ok) ServiceManager::run('service-control.sh', ['reload', 'openlitespeed'], 60);
        Helpers::log("safe headers on for $ok site(s) by ".Auth::user()['username']);
        if ($fail) Helpers::flash('err', "Safe headers on for $ok site(s); failed: ".implode('; ', array_slice($fail, 0, 5)));
        else Helpers::flash('ok', "Safe security headers on for $ok site(s)");
        Helpers::redirect('/admin/security');
    }

    // Domains page tab holding the sites a check flags: a subdomain lives on
    // the Subdomains tab, so "Fix" must land where the problem rows are.
    private static function domainsTab(array $rows): string {
        $sub = false;
        foreach ($rows as $r) {
            if (!DomainModel::parentOf((string)$r['domain'], (int)$r['user_id'])) return '/domains';
            $sub = true;
        }
        return $sub ? '/domains?tab=subdomains' : '/domains';
    }

    private static function names(array $u): string {
        return implode(', ', array_slice($u, 0, 8)).(count($u) > 8 ? ' and '.(count($u) - 8).' more' : '');
    }
}
