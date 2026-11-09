<?php
declare(strict_types=1);
/**
 * NotificationModel — the notifications table (list/paging, read flags,
 * deletes). Push-channel configuration is Settings.
 */
class NotificationModel {

    public static function countAll(): int {
        return (int)Database::query("SELECT COUNT(*) c FROM notifications")->fetch()['c'];
    }
    public static function countForUser(int $uid): int {
        return (int)Database::query("SELECT COUNT(*) c FROM notifications WHERE user_id=?", [$uid])->fetch()['c'];
    }
    public static function pageAll(int $limit, int $offset): array {
        return Database::query(
            "SELECT n.*, u.username AS who FROM notifications n LEFT JOIN users u ON u.id=n.user_id ORDER BY n.id DESC LIMIT ? OFFSET ?",
            [$limit, $offset]
        )->fetchAll();
    }
    public static function pageForUser(int $uid, int $limit, int $offset): array {
        return Database::query(
            "SELECT * FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT ? OFFSET ?", [$uid, $limit, $offset]
        )->fetchAll();
    }
    /** $uid null = admin scope (any row) */
    public static function markRead(int $id, ?int $uid): void {
        if ($uid === null) {
            Database::query("UPDATE notifications SET read_at=NOW() WHERE id=?", [$id]);
            return;
        }
        Database::query("UPDATE notifications SET read_at=NOW() WHERE id=? AND user_id=?", [$id, $uid]);
    }
    public static function markAllRead(?int $uid): void {
        if ($uid === null) {
            Database::query("UPDATE notifications SET read_at=NOW() WHERE read_at IS NULL");
            return;
        }
        Database::query("UPDATE notifications SET read_at=NOW() WHERE read_at IS NULL AND user_id=?", [$uid]);
    }
    public static function deleteAll(?int $uid): void {
        if ($uid === null) {
            Database::query("DELETE FROM notifications");
            return;
        }
        Database::query("DELETE FROM notifications WHERE user_id=?", [$uid]);
    }
    public static function delete(int $id, ?int $uid): void {
        if ($uid === null) {
            Database::query("DELETE FROM notifications WHERE id=?", [$id]);
            return;
        }
        Database::query("DELETE FROM notifications WHERE id=? AND user_id=?", [$id, $uid]);
    }
}
