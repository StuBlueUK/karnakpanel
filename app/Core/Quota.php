<?php
declare(strict_types=1);
/**
 * Disk/bandwidth quotas: usage (cached totals or a live measure), the hard
 * write gate, display text, reseller allocation and package limits. Admins
 * own the box and are never quota-blocked.
 */
class Quota {
    public static function usage(array $user): array {
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
        $dir = Helpers::homedir($user['username']);
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
    public static function blocked(array $user): ?string {
        if (($user['role'] ?? '') === 'admin') return null;
        try {
            $fresh = Database::query("SELECT disk_quota_mb FROM users WHERE id=?", [$user['id']])->fetch();
            $quotaMb = max(1, (int)($fresh['disk_quota_mb'] ?? 5120));
        } catch (Throwable $e) { return null; } // fail open on DB error
        $q = self::usage(array_merge($user, ['disk_quota_mb' => $quotaMb]));
        if ($q['used_mb'] >= $quotaMb) {
            return 'Disk quota exceeded ('.Helpers::fmtMb($q['used_mb']).' / '.Helpers::fmtMb((float)$quotaMb).'). Delete files, mail or backups to add more.';
        }
        return null;
    }
    // Bytes left under the disk quota for archive extraction / copies (zip-bomb
    // and over-quota net handed to the root scripts). 0 = no cap (admins, or
    // the quota can't be read — fail open like diskBlocked).
    public static function leftBytes(array $user): int {
        if (($user['role'] ?? '') === 'admin') return 0;
        try {
            $fresh = Database::query("SELECT disk_quota_mb FROM users WHERE id=?", [$user['id']])->fetch();
            $quotaMb = max(1, (int)($fresh['disk_quota_mb'] ?? 5120));
        } catch (Throwable $e) { return 0; }
        $q = self::usage(array_merge($user, ['disk_quota_mb' => $quotaMb]));
        return max(1, (int)(($quotaMb - $q['used_mb']) * 1048576));
    }
    // "1.2 GB / 5 GB (24%)", or "1.2 GB used / unlimited" for admin
    public static function text(array $q): string {
        if (!empty($q['unlimited'])) return Helpers::fmtMb((float)$q['used_mb']).' used / unlimited';
        return Helpers::fmtMb((float)$q['used_mb']).' / '.Helpers::fmtMb((float)$q['quota_mb']).' ('.$q['pct'].'%)';
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
    // Package limit check. Returns error string when limit reached, null when allowed.
    public static function limitError(array $user, string $kind): ?string {
        $map = [
            'domains' => ['domains', 'max_domains'],
            'emails'  => ['email_accounts', 'max_emails'],
            'dbs'     => ['mysql_dbs', 'max_dbs'],
            'ftps'    => ['ftp_accounts', 'max_ftp'],
            'builder' => ['builder_sites', 'max_builder_sites'],
            'daemons' => ['app_daemons', 'max_daemons'],
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
