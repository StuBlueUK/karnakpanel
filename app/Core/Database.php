<?php
declare(strict_types=1);

class Database {
    private static ?PDO $pdo = null;
    private static ?PDO $prov = null;

    public static function conn(): PDO {
        if (self::$pdo) return self::$pdo;
        $h = Config::get('db.host','localhost');
        $n = Config::get('db.name','skypanel');
        $u = Config::get('db.user','skypanel');
        $p = Config::get('db.pass','');
        $dsn = "mysql:host={$h};dbname={$n};charset=utf8mb4";
        self::$pdo = new PDO($dsn, $u, $p, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return self::$pdo;
    }
    // Privileged handle for DDL the app user can't do (CREATE/DROP DATABASE, CREATE USER).
    // Uses localhost-only provision credentials; keep out of normal queries (least privilege).
    public static function provision(): PDO {
        if (self::$prov) return self::$prov;
        $h = Config::get('db.host','localhost');
        $u = Config::get('db.provision_user','skypanel_prov');
        $p = Config::get('db.provision_pass','');
        self::$prov = new PDO("mysql:host={$h};charset=utf8mb4", $u, $p, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return self::$prov;
    }
    public static function query(string $sql, array $params = []): PDOStatement {
        $s = self::conn()->prepare($sql);
        // Typed binding: PHP ints go over as PARAM_INT so LIMIT ? OFFSET ?
        // placeholders work (quoted '25' is a MySQL syntax error), NULLs stay
        // NULL and bools stay bools — identical semantics for every caller,
        // with no string-typed integers ever reaching the server.
        foreach ($params as $i => $v) {
            $t = is_int($v) ? PDO::PARAM_INT : ($v === null ? PDO::PARAM_NULL
                : (is_bool($v) ? PDO::PARAM_BOOL : PDO::PARAM_STR));
            $s->bindValue(is_int($i) ? $i + 1 : $i, $v, $t);
        }
        $s->execute();
        return $s;
    }
}
