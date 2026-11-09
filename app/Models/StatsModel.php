<?php
declare(strict_types=1);
/** StatsModel — bandwidth_daily / disk_daily reads for the usage graphs. */
class StatsModel {
    public static function bandwidthDays(int $uid): array {
        return Database::query(
            "SELECT day, SUM(bytes) b FROM bandwidth_daily WHERE user_id=? AND day >= (CURDATE() - INTERVAL 29 DAY) GROUP BY day ORDER BY day", [$uid]
        )->fetchAll();
    }
    public static function diskDays(int $uid): array {
        return Database::query(
            "SELECT day, mb FROM disk_daily WHERE user_id=? AND day >= (CURDATE() - INTERVAL 29 DAY) ORDER BY day", [$uid]
        )->fetchAll();
    }
    public static function monthBytes(int $uid): int {
        return (int)(Database::query(
            "SELECT COALESCE(SUM(bytes),0) b FROM bandwidth_daily WHERE user_id=? AND day >= DATE_FORMAT(CURDATE(),'%Y-%m-01')", [$uid]
        )->fetch()['b'] ?? 0);
    }
    public static function monthByDomain(int $uid): array {
        return Database::query(
            "SELECT domain, SUM(bytes) b FROM bandwidth_daily WHERE user_id=? AND day >= DATE_FORMAT(CURDATE(),'%Y-%m-01') GROUP BY domain ORDER BY b DESC LIMIT 12",
            [$uid]
        )->fetchAll();
    }
}
