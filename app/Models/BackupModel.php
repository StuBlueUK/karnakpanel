<?php
declare(strict_types=1);
/**
 * BackupModel — the backups table plus the small lookups the Backups page
 * needs (mailboxes/DBs to offer for restore, account age for snapshot
 * scoping). Store/script work stays in the controller; this is rows only.
 */
class BackupModel {

    // ------------------------------------------------------------- listings
    public static function countAll(): int {
        return (int)Database::query("SELECT COUNT(*) c FROM backups")->fetch()['c'];
    }
    public static function countForUser(int $uid): int {
        return (int)Database::query("SELECT COUNT(*) c FROM backups WHERE user_id=?", [$uid])->fetch()['c'];
    }
    public static function pageAll(int $limit, int $offset): array {
        return Database::query(
            "SELECT b.*, u.username AS owner FROM backups b JOIN users u ON u.id=b.user_id ORDER BY b.id DESC LIMIT ? OFFSET ?",
            [$limit, $offset]
        )->fetchAll();
    }
    public static function pageForUser(int $uid, int $limit, int $offset): array {
        return Database::query(
            "SELECT * FROM backups WHERE user_id=? ORDER BY id DESC LIMIT ? OFFSET ?", [$uid, $limit, $offset]
        )->fetchAll();
    }
    /** owner names that appear in the backups table (admin "older than" picker) */
    public static function owners(): array {
        return array_column(
            Database::query("SELECT DISTINCT u.username FROM backups b JOIN users u ON u.id=b.user_id ORDER BY u.username")->fetchAll(),
            'username'
        );
    }
    public static function hasPass(int $uid): bool {
        return (bool)Database::query("SELECT backup_pass FROM users WHERE id=?", [$uid])->fetch()['backup_pass'];
    }
    public static function setPass(int $uid, string $key): void {
        Database::query("UPDATE users SET backup_pass=? WHERE id=?", [$key, $uid]);
    }

    // ----------------------------------------------------------- row lookups
    /** plain row (download/restore); $uid null = admin scope */
    public static function byId(int $id, ?int $uid): ?array {
        if ($uid === null) return Database::query("SELECT * FROM backups WHERE id=?", [$id])->fetch() ?: null;
        return Database::query("SELECT * FROM backups WHERE id=? AND user_id=?", [$id, $uid])->fetch() ?: null;
    }
    /** row with its owner's username (browse/restore actions); $uid null = admin */
    public static function withOwner(int $id, ?int $uid): ?array {
        if ($uid === null) {
            return Database::query(
                "SELECT b.*, u.username AS owner FROM backups b JOIN users u ON u.id=b.user_id WHERE b.id=?", [$id]
            )->fetch() ?: null;
        }
        return Database::query(
            "SELECT b.*, u.username AS owner FROM backups b JOIN users u ON u.id=b.user_id WHERE b.id=? AND b.user_id=?",
            [$id, $uid]
        )->fetch() ?: null;
    }
    /** @param list<int> $ids */
    public static function rowsById(array $ids, ?int $uid): array {
        $ids = array_values(array_map('intval', $ids));
        if (!$ids) return [];
        $place = implode(',', array_fill(0, count($ids), '?'));
        // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string -- $place is only ? placeholders built from count()
        $sql = "SELECT b.*, u.username AS owner FROM backups b JOIN users u ON u.id=b.user_id WHERE b.id IN ($place)";
        if ($uid === null) {
            return Database::query($sql . " ORDER BY b.created_at", $ids)->fetchAll();
        }
        return Database::query($sql . " AND b.user_id=? ORDER BY b.created_at", array_merge($ids, [$uid]))->fetchAll();
    }
    public static function rowsOlder(int $days, ?int $uid, string $acct): array {
        $sql = "SELECT b.*, u.username AS owner FROM backups b JOIN users u ON u.id=b.user_id WHERE b.created_at < NOW() - INTERVAL ? DAY";
        $args = [$days];
        if ($uid !== null) { $sql .= " AND b.user_id=?"; $args[] = $uid; }
        elseif ($acct !== '') { $sql .= " AND u.username=?"; $args[] = $acct; }
        return Database::query($sql . " ORDER BY b.created_at LIMIT 200", $args)->fetchAll();
    }
    public static function newestId(int $uid): int {
        return (int)(Database::query("SELECT id FROM backups WHERE user_id=? ORDER BY created_at DESC, id DESC LIMIT 1", [$uid])->fetch()['id'] ?? 0);
    }
    /** @param list<int> $ids */
    public static function deleteMany(array $ids): void {
        $ids = array_values(array_map('intval', $ids));
        if (!$ids) return;
        $place = implode(',', array_fill(0, count($ids), '?'));
        // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string -- $place is only ? placeholders built from count()
        Database::query("DELETE FROM backups WHERE id IN ($place)", $ids);
    }
    public static function insertRemote(int $uid, string $filename): void {
        Database::query("INSERT INTO backups(user_id,filename,size_bytes) VALUES(?,?,?)", [$uid, $filename, 0]);
    }

    // ------------------------------------------------------- restore scoping
    /** epoch seconds the account row was created (null when unknown) */
    public static function accountCreated(int $uid): ?int {
        $c = Database::query("SELECT UNIX_TIMESTAMP(created_at) c FROM users WHERE id=?", [$uid])->fetch();
        return ($c && $c['c'] !== null) ? (int)$c['c'] : null;
    }
    public static function dbsForUser(int $uid): array {
        return Database::query("SELECT * FROM mysql_dbs WHERE user_id=?", [$uid])->fetchAll();
    }
    /** db name + creation epoch, for dump-age filtering */
    public static function dbAges(int $uid): array {
        return Database::query("SELECT db_name, UNIX_TIMESTAMP(created_at) c FROM mysql_dbs WHERE user_id=?", [$uid])->fetchAll();
    }
}
