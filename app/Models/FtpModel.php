<?php
declare(strict_types=1);
/** FtpModel — ftp_accounts rows (extra FTPS logins jailed to the home). */
class FtpModel {
    public static function countFor(int $uid): int {
        return (int)Database::query("SELECT COUNT(*) c FROM ftp_accounts WHERE user_id=?", [$uid])->fetch()['c'];
    }
    public static function listFor(int $uid, int $limit, int $offset): array {
        return Database::query(
            "SELECT * FROM ftp_accounts WHERE user_id=? ORDER BY ftp_user LIMIT ? OFFSET ?", [$uid, $limit, $offset]
        )->fetchAll();
    }
    /** the login may not shadow a panel user or an existing FTP login */
    public static function loginTaken(string $login): bool {
        return (bool)Database::query("SELECT 1 FROM users WHERE username=?", [$login])->fetch()
            || (bool)Database::query("SELECT 1 FROM ftp_accounts WHERE ftp_user=?", [$login])->fetch();
    }
    public static function insert(int $uid, string $login, string $homedir): void {
        Database::query("INSERT INTO ftp_accounts(user_id,ftp_user,homedir) VALUES(?,?,?)", [$uid, $login, $homedir]);
    }
    public static function deleteByName(int $uid, string $login): void {
        Database::query("DELETE FROM ftp_accounts WHERE user_id=? AND ftp_user=?", [$uid, $login]);
    }
    public static function findOwned(int $id, int $uid): ?array {
        return Database::query("SELECT * FROM ftp_accounts WHERE id=? AND user_id=?", [$id, $uid])->fetch() ?: null;
    }
    public static function delete(int $id): void {
        Database::query("DELETE FROM ftp_accounts WHERE id=?", [$id]);
    }
}
