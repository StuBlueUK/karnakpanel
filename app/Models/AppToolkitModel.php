<?php
declare(strict_types=1);
/**
 * AppToolkitModel — staging clones and their snapshots (site_installs reads,
 * staging_clones/staging_snapshots rows, and the cleanup deletes). DNS/domain
 * rows go through DnsModel/DomainModel; staging database rows through
 * MysqlDb.
 */
class AppToolkitModel {

    // --------------------------------------------------------------- reads
    public static function installsFor(int $uid): array {
        return Database::query(
            "SELECT s.*, d.domain, d.php_version, d.ssl_enabled FROM site_installs s JOIN domains d ON d.id=s.domain_id WHERE s.user_id=? ORDER BY s.id DESC",
            [$uid]
        )->fetchAll();
    }
    public static function install(int $id, int $uid): ?array {
        return Database::query(
            "SELECT s.*, d.domain, d.php_version, d.ssl_enabled FROM site_installs s JOIN domains d ON d.id=s.domain_id WHERE s.id=? AND s.user_id=?",
            [$id, $uid]
        )->fetch() ?: null;
    }
    public static function clonesFor(int $uid): array {
        return Database::query(
            "SELECT c.*, d.domain AS staging_domain FROM staging_clones c JOIN domains d ON d.id=c.staging_domain_id WHERE c.user_id=?", [$uid]
        )->fetchAll();
    }
    /** clone id => snapshot rows, newest first */
    public static function snapshotsByClone(int $uid): array {
        $out = [];
        foreach (Database::query(
            "SELECT * FROM staging_snapshots WHERE clone_id IN (SELECT id FROM staging_clones WHERE user_id=?) ORDER BY id DESC", [$uid]
        )->fetchAll() as $s) {
            $out[(int)$s['clone_id']][] = $s;
        }
        return $out;
    }
    public static function cloneForInstall(int $installId, int $uid): ?array {
        return Database::query(
            "SELECT id, status FROM staging_clones WHERE live_install_id=? AND user_id=?", [$installId, $uid]
        )->fetch() ?: null;
    }
    public static function clone(int $id, int $uid): ?array {
        return Database::query(
            "SELECT c.*, d.domain AS staging_domain FROM staging_clones c JOIN domains d ON d.id=c.staging_domain_id WHERE c.id=? AND c.user_id=?",
            [$id, $uid]
        )->fetch() ?: null;
    }
    /** push path: clone + staging/live domains + live install path */
    public static function pushable(int $id, int $uid): ?array {
        return Database::query(
            "SELECT c.*, d.domain AS staging_domain, ld.domain AS live_domain, s.path AS live_path
             FROM staging_clones c JOIN domains d ON d.id=c.staging_domain_id JOIN site_installs s ON s.id=c.live_install_id
             JOIN domains ld ON ld.id=s.domain_id WHERE c.id=? AND c.user_id=? AND c.status='ready'",
            [$id, $uid]
        )->fetch() ?: null;
    }
    /** restore path: clone + staging/live domains */
    public static function restorable(int $id, int $uid): ?array {
        return Database::query(
            "SELECT c.*, d.domain AS staging_domain, ld.domain AS live_domain
             FROM staging_clones c JOIN domains d ON d.id=c.staging_domain_id JOIN site_installs s ON s.id=c.live_install_id
             JOIN domains ld ON ld.id=s.domain_id WHERE c.id=? AND c.user_id=? AND c.status='ready'",
            [$id, $uid]
        )->fetch() ?: null;
    }
    public static function snapshots(int $cloneId): array {
        return Database::query("SELECT * FROM staging_snapshots WHERE clone_id=? ORDER BY id DESC", [$cloneId])->fetchAll();
    }
    public static function snapshot(int $snapId, int $cloneId): ?array {
        return Database::query(
            "SELECT * FROM staging_snapshots WHERE id=? AND clone_id=?", [$snapId, $cloneId]
        )->fetch() ?: null;
    }
    /** a clone still in its initial 'cloning' state (dismiss path) */
    public static function cloning(int $id, int $uid): ?array {
        return Database::query(
            "SELECT * FROM staging_clones WHERE id=? AND user_id=? AND status='cloning'", [$id, $uid]
        )->fetch() ?: null;
    }

    // --------------------------------------------------------------- writes
    public static function insertClone(int $uid, int $liveInstallId, int $stagingDomainId, string $db, string $dbUser): void {
        Database::query(
            "INSERT INTO staging_clones(user_id,app,live_install_id,staging_domain_id,staging_db,staging_db_user,status) VALUES(?,?,?,?,?,?,'cloning')",
            [$uid, 'wordpress', $liveInstallId, $stagingDomainId, $db, $dbUser]
        );
    }
    public static function setStatus(int $id, string $status): void {
        Database::query("UPDATE staging_clones SET status=? WHERE id=?", [$status, $id]);
    }
    public static function deleteClone(int $id, int $uid): void {
        Database::query("DELETE FROM staging_clones WHERE id=? AND user_id=?", [$id, $uid]);
    }
    public static function deleteCloneAny(int $id): void {
        Database::query("DELETE FROM staging_clones WHERE id=?", [$id]);
    }
    public static function deleteSnapshots(int $cloneId): void {
        Database::query("DELETE FROM staging_snapshots WHERE clone_id=?", [$cloneId]);
    }
}
