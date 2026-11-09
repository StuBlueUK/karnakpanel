<?php
declare(strict_types=1);
/**
 * ImportJob — the import_jobs progress table (cPanel/Hestia migrations).
 * Rows are written by the root importer; the panel reads status and prunes.
 */
class ImportJob {
    public static function prune(int $days = 7): void {
        Database::query("DELETE FROM import_jobs WHERE updated_at < (NOW() - INTERVAL ? DAY)", [max(1, $days)]);
    }
    public static function status(string $job): ?array {
        return Database::query("SELECT stage,pct,detail FROM import_jobs WHERE job=?", [$job])->fetch() ?: null;
    }
}
