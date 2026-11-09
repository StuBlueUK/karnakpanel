<?php
declare(strict_types=1);
/**
 * DashboardModel — the read-only aggregates behind the dashboard: account
 * counters, package limits, recent rows, the server_stats ring, bandwidth
 * history, the box-wide totals and the "needs attention" probes.
 *
 * Verbatim copies of the queries DashboardController used to run inline; the
 * scoped ones take `?int $uid` (null = whole box).
 */
class DashboardModel {

    // ------------------------------------------------------------ own totals
    public static function counts(int $uid): array {
        return [
            'domains' => (int)Database::query("SELECT COUNT(*) c FROM domains WHERE user_id=?", [$uid])->fetch()['c'],
            'emails'  => (int)Database::query("SELECT COUNT(*) c FROM email_accounts WHERE user_id=?", [$uid])->fetch()['c'],
            'ftps'    => (int)Database::query("SELECT COUNT(*) c FROM ftp_accounts WHERE user_id=?", [$uid])->fetch()['c'],
            'dbs'     => (int)Database::query("SELECT COUNT(*) c FROM mysql_dbs WHERE user_id=?", [$uid])->fetch()['c'],
        ];
    }
    public static function packageId(int $uid): ?int {
        $v = Database::query("SELECT package_id FROM users WHERE id=?", [$uid])->fetch()['package_id'] ?? null;
        return $v === null ? null : (int)$v;
    }
    public static function packageLimits(int $pkgId): ?array {
        return Database::query(
            "SELECT name,max_domains,max_emails,max_dbs,max_ftp,max_builder_sites FROM packages WHERE id=?", [$pkgId]
        )->fetch() ?: null;
    }
    public static function recentDomains(int $uid): array {
        return Database::query(
            "SELECT domain,php_version,ssl_enabled,ssl_expires,created_at FROM domains WHERE user_id=? ORDER BY id DESC LIMIT 5", [$uid]
        )->fetchAll();
    }
    public static function recentBackups(int $uid): array {
        return Database::query(
            "SELECT filename,size_bytes,created_at FROM backups WHERE user_id=? ORDER BY id DESC LIMIT 5", [$uid]
        )->fetchAll();
    }
    /** fresh quota values (an admin may have changed the package) */
    public static function quotaRow(int $uid): ?array {
        return Database::query("SELECT disk_quota_mb,bandwidth_quota_gb FROM users WHERE id=?", [$uid])->fetch() ?: null;
    }
    public static function bandwidthDays(int $uid): array {
        return Database::query(
            "SELECT day, SUM(bytes) b FROM bandwidth_daily WHERE user_id=? AND day >= CURDATE() - INTERVAL 13 DAY GROUP BY day", [$uid]
        )->fetchAll();
    }

    // ----------------------------------------------------------- server ring
    public static function statsUpsert(float $load1, float $memPct): void {
        Database::query(
            "INSERT INTO server_stats(ts,load1,mem_pct) VALUES(DATE_FORMAT(NOW(),'%Y-%m-%d %H:%i:00'),?,?) ON DUPLICATE KEY UPDATE load1=VALUES(load1), mem_pct=VALUES(mem_pct)",
            [$load1, $memPct]
        );
    }
    public static function statsPrune(): void {
        Database::query("DELETE FROM server_stats WHERE ts < (NOW() - INTERVAL 1 DAY)");
    }
    public static function statsHistory(): array {
        return array_reverse(Database::query("SELECT ts,load1,mem_pct FROM server_stats ORDER BY ts DESC LIMIT 96")->fetchAll());
    }

    // --------------------------------------------------------------- settings
    public static function setting(string $name): ?string {
        return Settings::get($name);
    }
    public static function putSetting(string $name, string $value): void {
        Settings::put($name, $value);
    }
    public static function dropSetting(string $name): void {
        Settings::drop($name);
    }

    // ------------------------------------------------------ setup checklist
    public static function userCount(): int {
        return (int)Database::query("SELECT COUNT(*) c FROM users")->fetch()['c'];
    }
    public static function domainCount(?string $except = null): int {
        if ($except === null) return (int)Database::query("SELECT COUNT(*) c FROM domains")->fetch()['c'];
        return (int)Database::query("SELECT COUNT(*) c FROM domains WHERE domain<>?", [$except])->fetch()['c'];
    }
    public static function sslCount(): int {
        return (int)Database::query("SELECT COUNT(*) c FROM domains WHERE ssl_enabled=1")->fetch()['c'];
    }
    public static function totpCount(): int {
        return (int)Database::query("SELECT COUNT(*) c FROM users WHERE " . Auth::HAS_2FA)->fetch()['c'];
    }

    // ------------------------------------------------------ attention probes
    /** SSL certs expiring inside 14 days ($uid = null: whole box) */
    public static function sslExpiring(?int $uid): array {
        if ($uid === null) {
            return Database::query(
                "SELECT domain, ssl_expires, DATEDIFF(ssl_expires, CURDATE()) d FROM domains WHERE ssl_enabled=1 AND ssl_expires IS NOT NULL AND ssl_expires <= CURDATE() + INTERVAL 14 DAY ORDER BY ssl_expires LIMIT 10"
            )->fetchAll();
        }
        return Database::query(
            "SELECT domain, ssl_expires, DATEDIFF(ssl_expires, CURDATE()) d FROM domains WHERE ssl_enabled=1 AND ssl_expires IS NOT NULL AND ssl_expires <= CURDATE() + INTERVAL 14 DAY AND user_id=? ORDER BY ssl_expires LIMIT 10",
            [$uid]
        )->fetchAll();
    }
    /** domain registrations expiring inside 30 days */
    public static function domExpiring(?int $uid): array {
        if ($uid === null) {
            return Database::query(
                "SELECT domain, DATEDIFF(dom_expires, CURDATE()) d FROM domains WHERE dom_expires IS NOT NULL AND dom_expires <= CURDATE() + INTERVAL 30 DAY ORDER BY dom_expires LIMIT 10"
            )->fetchAll();
        }
        return Database::query(
            "SELECT domain, DATEDIFF(dom_expires, CURDATE()) d FROM domains WHERE dom_expires IS NOT NULL AND dom_expires <= CURDATE() + INTERVAL 30 DAY AND user_id=? ORDER BY dom_expires LIMIT 10",
            [$uid]
        )->fetchAll();
    }
    public static function usersNearQuota(): array {
        return Database::query(
            "SELECT username, disk_used_mb, disk_quota_mb FROM users WHERE role<>'admin' AND disk_quota_mb>0 AND disk_used_mb >= disk_quota_mb*0.9 ORDER BY disk_used_mb/disk_quota_mb DESC LIMIT 6"
        )->fetchAll();
    }
    public static function userDisk(int $uid): ?array {
        return Database::query("SELECT disk_used_mb, disk_quota_mb FROM users WHERE id=?", [$uid])->fetch() ?: null;
    }
    public static function ownedUsernames(int $ownerId): array {
        return array_column(
            Database::query("SELECT username FROM users WHERE owner_id=?", [$ownerId])->fetchAll(), 'username'
        );
    }
    public static function accountCount(): int {
        return (int)Database::query("SELECT COUNT(*) c FROM users WHERE role<>'admin'")->fetch()['c'];
    }
    /** hours since the newest tarball backup (null = none yet) */
    public static function hoursSinceBackup(): ?int {
        $h = Database::query("SELECT TIMESTAMPDIFF(HOUR, MAX(created_at), NOW()) h FROM backups")->fetch()['h'];
        return $h === null ? null : (int)$h;
    }

    // ------------------------------------------------------- box-wide glance
    public static function boxTotals(): array {
        $c = fn(string $sql) => (int)Database::query($sql)->fetch()['c'];
        return [
            'accounts'  => $c("SELECT COUNT(*) c FROM users WHERE role<>'admin'"),
            'suspended' => $c("SELECT COUNT(*) c FROM users WHERE status<>'active'"),
            'domains'   => $c("SELECT COUNT(*) c FROM domains"),
            'ssl'       => $c("SELECT COUNT(*) c FROM domains WHERE ssl_enabled=1"),
            'mail'      => $c("SELECT COUNT(*) c FROM email_accounts"),
            'dbs'       => $c("SELECT COUNT(*) c FROM mysql_dbs"),
        ];
    }
    public static function busyUsers(): array {
        return Database::query(
            "SELECT u.username, u.status, u.disk_used_mb, u.disk_quota_mb, COALESCE(b.bytes,0) bw,
                    (SELECT COUNT(*) FROM domains d WHERE d.user_id=u.id) nd
             FROM users u LEFT JOIN (SELECT user_id, SUM(bytes) bytes FROM bandwidth_daily WHERE day >= DATE_FORMAT(CURDATE(),'%Y-%m-01') GROUP BY user_id) b ON b.user_id=u.id
             ORDER BY u.disk_used_mb DESC, bw DESC LIMIT 6"
        )->fetchAll();
    }
}
