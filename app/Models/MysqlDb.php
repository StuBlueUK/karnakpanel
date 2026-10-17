<?php
declare(strict_types=1);
// Customer MariaDB databases: the ONE place that turns panel requests into
// real DDL (web UI, API and account teardown all call here, so validation
// can't drift between copies).
// DDL runs on the provision handle, tracking rows on the app handle.
// Identifiers are regex-allowlisted (DDL cannot bind them); passwords always
// go through PDO::quote().
class MysqlDb {
    private const SYSTEM = ['mysql','information_schema','performance_schema','sys','skypanel'];

    // '' / null = generate. Anything else must be 8+ chars. Returns the
    // password to use, or null when the supplied one is too short.
    public static function password(?string $in): ?string {
        $in = trim((string)$in);
        if ($in === '') return bin2hex(random_bytes(10));
        return strlen($in) >= 8 ? $in : null;
    }
    public static function validRow(array $row): bool {
        return (bool)preg_match('/^[a-z][a-z0-9_]{2,63}$/', (string)$row['db_name'])
            && (bool)preg_match('/^[a-z][a-z0-9_]{2,31}$/', (string)$row['db_user'])
            && !in_array($row['db_name'], self::SYSTEM, true);
    }
    // Creates <user>_<name> + same-named user (truncated to 32). Caller has
    // already checked limits/disk. Returns [db_name, db_user]; throws on failure.
    public static function create(array $owner, string $name, string $pass): array {
        if (!preg_match('/^[a-z0-9_]{3,}$/', $name)) throw new InvalidArgumentException('bad db name (3+ chars a-z0-9_)');
        if (strlen($pass) < 8) throw new InvalidArgumentException('db password 8+ chars');
        $dbname = $owner['username'].'_'.$name;
        $dbuser = substr($dbname, 0, 32);
        if (!self::validRow(['db_name' => $dbname, 'db_user' => $dbuser])) throw new InvalidArgumentException('bad db name');
        $pdo = Database::provision();
        // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string -- identifier regex-allowlisted [a-z0-9_] + backticked (CREATE cannot bind identifiers)
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `".$dbname."` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string -- name allowlisted; password via PDO::quote() string literal
        $pdo->exec("CREATE USER IF NOT EXISTS '".$dbuser."'@'localhost' IDENTIFIED BY ".$pdo->quote($pass));
        $pdo->exec("GRANT ALL PRIVILEGES ON `".$dbname."`.* TO '".$dbuser."'@'localhost'");
        self::applyCaps($pdo, $dbuser, self::caps());
        $pdo->exec("FLUSH PRIVILEGES");
        Database::query("INSERT INTO mysql_dbs(user_id,db_name,db_user) VALUES(?,?,?)", [$owner['id'], $dbname, $dbuser]);
        return [$dbname, $dbuser];
    }
    // Abuse guardrails: a compromised app can't hammer the shared MariaDB
    // without bound. Admin-set (Databases page), these defaults otherwise;
    // 0 = no cap. Mirrored in scripts/db-caps.sh (tests/run.php checks).
    // Every PHP request is one connection: the old 360/hour cap was one
    // request every 10 s and busy sites 500'd with error 1226.
    public const CAP_DEFAULTS = ['db_cap_queries_hour' => 1000000, 'db_cap_updates_hour' => 100000, 'db_cap_conn_hour' => 36000, 'db_cap_user_conn' => 25];
    // Digits only, 0..999999999 each; returns null on any bad field.
    public static function parseCaps(array $in): ?array {
        $out = [];
        foreach (self::CAP_DEFAULTS as $k => $def) {
            $v = trim((string)($in[$k] ?? ''));
            if (!preg_match('/^[0-9]{1,9}$/', $v)) return null;
            $out[$k] = (int)$v;
        }
        return $out;
    }
    public static function caps(): array {
        $c = self::CAP_DEFAULTS;
        try {
            foreach (Database::query("SELECT name, value FROM settings WHERE name LIKE 'db\\_cap\\_%'")->fetchAll() as $r) {
                if (array_key_exists($r['name'], $c) && preg_match('/^[0-9]{1,9}$/', (string)$r['value'])) $c[$r['name']] = (int)$r['value'];
            }
        } catch (Throwable $e) {}
        return $c;
    }
    public static function capsClause(array $c): string {
        return sprintf('MAX_QUERIES_PER_HOUR %d MAX_UPDATES_PER_HOUR %d MAX_CONNECTIONS_PER_HOUR %d MAX_USER_CONNECTIONS %d',
            $c['db_cap_queries_hour'], $c['db_cap_updates_hour'], $c['db_cap_conn_hour'], $c['db_cap_user_conn']);
    }
    public static function applyCaps(PDO $pdo, string $dbuser, array $caps): void {
        if (!preg_match('/^[a-z][a-z0-9_]{2,31}$/', $dbuser)) throw new InvalidArgumentException('bad db user');
        // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string -- user regex-allowlisted [a-z0-9_] + quoted; caps are %d ints
        $pdo->exec("GRANT USAGE ON *.* TO '".$dbuser."'@'localhost' WITH ".self::capsClause($caps));
    }
    // Admin "apply to existing": every panel-tracked database user. Returns
    // [applied, failed]; counters reset so the new caps count from now.
    public static function applyCapsToAll(array $caps): array {
        $pdo = Database::provision();
        $ok = 0; $bad = 0;
        foreach (Database::query("SELECT DISTINCT db_user FROM mysql_dbs")->fetchAll() as $r) {
            try { self::applyCaps($pdo, (string)$r['db_user'], $caps); $ok++; } catch (Throwable $e) { $bad++; }
        }
        $pdo->exec("FLUSH USER_RESOURCES");
        return [$ok, $bad];
    }
    // Drops the database, and its user only when no other row references it.
    public static function drop(array $row): void {
        if (!self::validRow($row)) throw new InvalidArgumentException('bad stored name');
        $pdo = Database::provision();
        // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string -- identifier regex-allowlisted + backticked; row resolved by int id
        $pdo->exec("DROP DATABASE IF EXISTS `".$row['db_name']."`");
        $left = (int)Database::query("SELECT COUNT(*) c FROM mysql_dbs WHERE db_user=? AND id!=?", [$row['db_user'], $row['id']])->fetch()['c'];
        // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string -- identifier regex-allowlisted + quoted; row resolved by int id
        if ($left === 0) $pdo->exec("DROP USER IF EXISTS '".$row['db_user']."'@'localhost'");
        Database::query("DELETE FROM mysql_dbs WHERE id=?", [$row['id']]);
    }
    // Account teardown: real objects only (rows go with the account's row sweep).
    public static function dropAllFor(int $userId): void {
        $dbs = Database::query("SELECT db_name, db_user FROM mysql_dbs WHERE user_id=?", [$userId])->fetchAll();
        if (!$dbs) return;
        $pdo = Database::provision();
        foreach ($dbs as $db) {
            if (!self::validRow($db)) continue;
            // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string -- identifier regex-allowlisted [a-z0-9_] + backticked
            $pdo->exec("DROP DATABASE IF EXISTS `".$db['db_name']."`");
            // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string -- identifier regex-allowlisted [a-z0-9_] + quoted
            $pdo->exec("DROP USER IF EXISTS '".$db['db_user']."'@'localhost'");
        }
    }
    // Passwords are shown once and never stored — this issues a replacement.
    public static function resetPassword(array $row): string {
        if (!preg_match('/^[a-z][a-z0-9_]{2,31}$/', (string)$row['db_user'])) throw new InvalidArgumentException('bad stored username');
        $pass = bin2hex(random_bytes(10));
        $pdo = Database::provision();
        // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string -- name from validated row; password via PDO::quote() string literal
        $pdo->exec("ALTER USER '".$row['db_user']."'@'localhost' IDENTIFIED BY ".$pdo->quote($pass));
        return $pass;
    }
}
