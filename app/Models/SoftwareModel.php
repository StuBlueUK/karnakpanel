<?php
declare(strict_types=1);
/** SoftwareModel — site_installs rows (panel-installed apps). */
class SoftwareModel {
    public static function countFor(int $uid): int {
        return (int)Database::query("SELECT COUNT(*) c FROM site_installs WHERE user_id=?", [$uid])->fetch()['c'];
    }
    public static function listFor(int $uid, int $limit, int $offset): array {
        return Database::query(
            "SELECT s.*, d.domain FROM site_installs s JOIN domains d ON d.id=s.domain_id WHERE s.user_id=? ORDER BY s.id DESC LIMIT ? OFFSET ?",
            [$uid, $limit, $offset]
        )->fetchAll();
    }
    public static function findOwned(int $id, int $uid): ?array {
        return Database::query(
            "SELECT s.*, d.domain FROM site_installs s JOIN domains d ON d.id=s.domain_id WHERE s.id=? AND s.user_id=?", [$id, $uid]
        )->fetch() ?: null;
    }
    public static function findWpOwned(int $id, int $uid): ?array {
        return Database::query(
            "SELECT s.*, d.domain FROM site_installs s JOIN domains d ON d.id=s.domain_id WHERE s.id=? AND s.user_id=? AND s.app='wordpress'",
            [$id, $uid]
        )->fetch() ?: null;
    }
    public static function deleteOwned(int $id, int $uid): void {
        Database::query("DELETE FROM site_installs WHERE id=? AND user_id=?", [$id, $uid]);
    }
}
