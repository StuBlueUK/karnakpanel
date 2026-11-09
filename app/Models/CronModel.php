<?php
declare(strict_types=1);
/** CronModel — cron_jobs rows (user crontab entries mirrored into the panel). */
class CronModel {
    public static function countFor(int $uid): int {
        return (int)Database::query("SELECT COUNT(*) c FROM cron_jobs WHERE user_id=?", [$uid])->fetch()['c'];
    }
    public static function listFor(int $uid, int $limit, int $offset): array {
        return Database::query(
            "SELECT * FROM cron_jobs WHERE user_id=? ORDER BY id DESC LIMIT ? OFFSET ?", [$uid, $limit, $offset]
        )->fetchAll();
    }
    public static function findOwned(int $id, int $uid): ?array {
        return Database::query("SELECT * FROM cron_jobs WHERE id=? AND user_id=?", [$id, $uid])->fetch() ?: null;
    }
    public static function clearExpect(int $id): void {
        Database::query("UPDATE cron_jobs SET expect_min=NULL WHERE id=?", [$id]);
    }
    public static function setExpect(int $id, int $minutes, int $grace): void {
        Database::query("UPDATE cron_jobs SET expect_min=?, grace_min=? WHERE id=?", [$minutes, $grace, $id]);
    }
    public static function insert(int $uid, string $schedule, string $command): void {
        Database::query("INSERT INTO cron_jobs(user_id,schedule,command) VALUES(?,?,?)", [$uid, $schedule, $command]);
    }
    public static function deleteOwned(int $id, int $uid): void {
        Database::query("DELETE FROM cron_jobs WHERE id=? AND user_id=?", [$id, $uid]);
    }
}
