<?php
declare(strict_types=1);
class Package {
    public static function all(): array {
        return Database::query("SELECT p.*, u.username AS reseller FROM packages p LEFT JOIN users u ON u.id=p.reseller_id ORDER BY p.id DESC")->fetchAll();
    }
    // what a user may pick/see: admin everything; reseller = system plans
    // (reseller_id NULL) + their own — never another reseller's
    public static function visibleTo(array $me): array {
        $cnt = "(SELECT COUNT(*) FROM users x WHERE x.package_id=p.id) AS n_users";
        if (($me['role'] ?? '') === 'admin') {
            return Database::query("SELECT p.*, u.username AS reseller, $cnt FROM packages p LEFT JOIN users u ON u.id=p.reseller_id ORDER BY p.id DESC")->fetchAll();
        }
        return Database::query("SELECT p.*, u.username AS reseller, $cnt FROM packages p LEFT JOIN users u ON u.id=p.reseller_id WHERE p.reseller_id IS NULL OR p.reseller_id=? ORDER BY p.id DESC", [(int)$me['id']])->fetchAll();
    }
    public static function findVisible(int $id, array $me): ?array {
        $p = self::find($id);
        if (!$p) return null;
        if (($me['role'] ?? '') === 'admin' || $p['reseller_id'] === null || (int)$p['reseller_id'] === (int)$me['id']) return $p;
        return null;
    }
    // edit/delete rights: admin = system + own; reseller = own only
    public static function canManage(array $p, array $me): bool {
        if (($me['role'] ?? '') === 'admin') return $p['reseller_id'] === null || (int)$p['reseller_id'] === (int)$me['id'];
        return ($me['role'] ?? '') === 'reseller' && $p['reseller_id'] !== null && (int)$p['reseller_id'] === (int)$me['id'];
    }
    public static function find(int $id): ?array {        $r = Database::query("SELECT * FROM packages WHERE id=?", [$id])->fetch();
        return $r ?: null;
    }
    public static function findByName(string $name): ?array {
        // system plan wins over a same-named reseller plan (name is only unique per owner)
        $r = Database::query("SELECT * FROM packages WHERE name=? ORDER BY (reseller_id IS NOT NULL), id LIMIT 1", [trim($name)])->fetch();
        return $r ?: null;
    }

    public static function nameTaken(string $name, ?int $resellerId, int $exceptId = 0): bool {
        return (bool)Database::query(
            "SELECT id FROM packages WHERE name=? AND reseller_id <=> ? AND id<>? LIMIT 1", [$name, $resellerId, $exceptId]
        )->fetch();
    }
    public static function insert(array $in, ?int $resellerId): void {
        Database::query(
            "INSERT INTO packages(name,reseller_id,disk_quota_mb,bandwidth_quota_gb,max_domains,max_emails,max_dbs,max_ftp,php_version,max_children,max_builder_sites,max_daemons) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)",
            [$in['name'], $resellerId, $in['disk_quota_mb'], $in['bandwidth_quota_gb'], $in['max_domains'], $in['max_emails'],
             $in['max_dbs'], $in['max_ftp'], $in['php_version'], $in['max_children'], $in['max_builder_sites'], $in['max_daemons']]
        );
    }
    public static function update(int $id, array $in): void {
        Database::query(
            "UPDATE packages SET name=?,disk_quota_mb=?,bandwidth_quota_gb=?,max_domains=?,max_emails=?,max_dbs=?,max_ftp=?,php_version=?,max_children=?,max_builder_sites=?,max_daemons=? WHERE id=?",
            [$in['name'], $in['disk_quota_mb'], $in['bandwidth_quota_gb'], $in['max_domains'], $in['max_emails'],
             $in['max_dbs'], $in['max_ftp'], $in['php_version'], $in['max_children'], $in['max_builder_sites'],
             $in['max_daemons'], $id]
        );
    }
    public static function applyQuotas(int $packageId, int $disk, int $bw): void {
        Database::query("UPDATE users SET disk_quota_mb=?, bandwidth_quota_gb=? WHERE package_id=?", [$disk, $bw, $packageId]);
    }
    public static function usersOn(int $packageId): array {
        return Database::query("SELECT * FROM users WHERE package_id=?", [$packageId])->fetchAll();
    }
    public static function usernamesOn(int $packageId): array {
        return Database::query("SELECT username FROM users WHERE package_id=? ORDER BY username", [$packageId])->fetchAll();
    }
    /** delete only when no account still uses it; false = still in use */
    public static function deleteIfUnused(int $id): bool {
        Database::query(
            "DELETE FROM packages WHERE id=? AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM users WHERE package_id=?) x)",
            [$id, $id]
        );
        return (bool)Database::query("SELECT id FROM packages WHERE id=?", [$id])->fetch() === false;
    }
}
