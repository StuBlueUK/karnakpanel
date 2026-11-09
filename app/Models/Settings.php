<?php
declare(strict_types=1);
/**
 * Settings — the `settings` name/value table. One home for the get/put/drop
 * pair so no controller (or other model) re-spells the upsert.
 */
class Settings {
    public static function get(string $name): ?string {
        $r = Database::query("SELECT value FROM settings WHERE name=?", [$name])->fetch();
        return $r === false ? null : (string)$r['value'];
    }
    public static function put(string $name, string $value): void {
        Database::query("INSERT INTO settings(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [$name, $value]);
    }
    /** upsert several rows in one statement */
    public static function putMany(array $kv): void {
        if (!$kv) return;
        $parts = [];
        $args = [];
        foreach ($kv as $k => $v) { $parts[] = '(?,?)'; $args[] = $k; $args[] = $v; }
        Database::query(
            "INSERT INTO settings(name,value) VALUES " . implode(',', $parts) . " ON DUPLICATE KEY UPDATE value=VALUES(value)",
            $args
        );
    }
    public static function drop(string $name): void {
        Database::query("DELETE FROM settings WHERE name=?", [$name]);
    }
    /** old installs may lack the table — callers retry after this */
    public static function ensureTable(): void {
        Database::query("CREATE TABLE IF NOT EXISTS settings (name VARCHAR(190) PRIMARY KEY, value TEXT NOT NULL)");
    }
}
