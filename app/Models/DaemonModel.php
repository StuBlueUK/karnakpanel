<?php
declare(strict_types=1);
/**
 * DaemonModel — app_daemons rows (per-account background services) and the
 * package cap that limits them.
 */
class DaemonModel {

    public static function countFor(int $uid): int {
        return (int)(Database::query("SELECT COUNT(*) c FROM app_daemons WHERE user_id=?", [$uid])->fetch()['c'] ?? 0);
    }
    public static function maxFor(int $uid): ?int {
        $v = Database::query(
            "SELECT p.max_daemons FROM users u JOIN packages p ON p.id=u.package_id WHERE u.id=?", [$uid]
        )->fetch()['max_daemons'] ?? null;
        return $v === null ? null : (int)$v;
    }
    public static function listFor(int $uid): array {
        return Database::query("SELECT * FROM app_daemons WHERE user_id=? ORDER BY name", [$uid])->fetchAll();
    }
    public static function findOwned(int $id, int $uid): ?array {
        return Database::query("SELECT * FROM app_daemons WHERE id=? AND user_id=?", [$id, $uid])->fetch() ?: null;
    }
    public static function insert(int $uid, string $name, string $command, string $workdir, int $maxHours): void {
        Database::query(
            "INSERT INTO app_daemons(user_id,name,command,workdir,max_hours) VALUES(?,?,?,?,?)",
            [$uid, $name, $command, $workdir, $maxHours]
        );
    }
    public static function deleteByName(int $uid, string $name): void {
        Database::query("DELETE FROM app_daemons WHERE user_id=? AND name=?", [$uid, $name]);
    }
    public static function setMaxHours(int $id, int $hours): void {
        Database::query("UPDATE app_daemons SET max_hours=? WHERE id=?", [$hours, $id]);
    }
    public static function delete(int $id): void {
        Database::query("DELETE FROM app_daemons WHERE id=?", [$id]);
    }
    /** username/name => [command, workdir] for the abuse board */
    public static function index(): array {
        $out = [];
        foreach (Database::query(
            "SELECT u.username, d.name, d.command, d.workdir FROM app_daemons d JOIN users u ON u.id=d.user_id"
        )->fetchAll() as $x) {
            $out[$x['username'].'/'.$x['name']] = [$x['command'], $x['workdir']];
        }
        return $out;
    }
}
