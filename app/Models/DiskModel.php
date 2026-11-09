<?php
declare(strict_types=1);
/** DiskModel — the disk_tree_cache row and the users.disk_used_mb cache column. */
class DiskModel {
    /** payload + cache age (null when nothing cached) */
    public static function cache(int $uid): ?array {
        return Database::query(
            "SELECT payload, TIMESTAMPDIFF(SECOND, measured_at, NOW()) elapsed FROM disk_tree_cache WHERE user_id=?", [$uid]
        )->fetch() ?: null;
    }
    public static function cacheElapsed(int $uid): ?int {
        $r = Database::query("SELECT TIMESTAMPDIFF(SECOND, measured_at, NOW()) elapsed FROM disk_tree_cache WHERE user_id=?", [$uid])->fetch();
        return $r === false || $r['elapsed'] === null ? null : (int)$r['elapsed'];
    }
    public static function putCache(int $uid, string $payload): void {
        Database::query(
            "INSERT INTO disk_tree_cache(user_id,payload) VALUES(?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload), measured_at=NOW()",
            [$uid, $payload]
        );
    }
    public static function setUsedMb(int $uid, int $mb): void {
        Database::query("UPDATE users SET disk_used_mb=?, disk_measured_at=NOW() WHERE id=?", [$mb, $uid]);
    }
}
