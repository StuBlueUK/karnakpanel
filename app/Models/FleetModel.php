<?php
declare(strict_types=1);
/**
 * FleetModel — panel-installed app versions (site_installs + app_versions).
 * Admin scope passes null; resellers get their own clients' rows.
 */
class FleetModel {

    public static function rows(?int $ownerId): array {
        if ($ownerId === null) {
            return Database::query(
                "SELECT s.id, s.app, s.url, u.username, d.domain, v.live_version, v.latest_version, v.update_available, v.plugins_total, v.plugins_available, v.scanned_at, v.scan_note
                 FROM site_installs s JOIN users u ON u.id=s.user_id JOIN domains d ON d.id=s.domain_id
                 LEFT JOIN app_versions v ON v.install_id=s.id
                 ORDER BY COALESCE(v.update_available,0) DESC, s.id DESC"
            )->fetchAll();
        }
        return Database::query(
            "SELECT s.id, s.app, s.url, u.username, d.domain, v.live_version, v.latest_version, v.update_available, v.plugins_total, v.plugins_available, v.scanned_at, v.scan_note
             FROM site_installs s JOIN users u ON u.id=s.user_id JOIN domains d ON d.id=s.domain_id
             LEFT JOIN app_versions v ON v.install_id=s.id WHERE u.owner_id=?
             ORDER BY COALESCE(v.update_available,0) DESC, s.id DESC",
            [$ownerId]
        )->fetchAll();
    }
    /** box-wide scan totals for the security advisor */
    public static function versionSummary(): array {
        return Database::query(
            "SELECT COUNT(*) n, SUM(update_available=1) core, SUM(plugins_available) plug FROM app_versions"
        )->fetch() ?: ['n' => 0, 'core' => 0, 'plug' => 0];
    }
    public static function dryRun(?int $ownerId): array {
        $sql = "SELECT u.username, d.domain, v.live_version, v.latest_version FROM site_installs s
                JOIN users u ON u.id=s.user_id JOIN domains d ON d.id=s.domain_id JOIN app_versions v ON v.install_id=s.id
                WHERE v.update_available=1";
        if ($ownerId === null) return Database::query($sql . " ORDER BY d.domain")->fetchAll();
        return Database::query($sql . " AND u.owner_id=? ORDER BY d.domain", [$ownerId])->fetchAll();
    }
    /** one install in scope (null = admin: any row) */
    public static function install(int $id, ?int $ownerId): ?array {
        if ($ownerId === null) {
            return Database::query("SELECT s.* FROM site_installs s JOIN users u ON u.id=s.user_id WHERE s.id=?", [$id])->fetch() ?: null;
        }
        return Database::query(
            "SELECT s.* FROM site_installs s JOIN users u ON u.id=s.user_id WHERE s.id=? AND u.owner_id=?", [$id, $ownerId]
        )->fetch() ?: null;
    }
}
