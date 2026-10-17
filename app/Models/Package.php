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
}
