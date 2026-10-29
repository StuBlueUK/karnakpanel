<?php
declare(strict_types=1);
class User {
    public static function all(?int $ownerId = null, ?string $role = null): array {
        if ($ownerId) return Database::query("SELECT id,username,email,role,status,disk_quota_mb,disk_used_mb,disk_measured_at,bandwidth_quota_gb,bw_suspended,created_at,package_id FROM users WHERE owner_id=? ORDER BY id DESC", [$ownerId])->fetchAll();
        if ($role) return Database::query("SELECT id,username,email,role,status,disk_quota_mb,disk_used_mb,disk_measured_at,bandwidth_quota_gb,bw_suspended,created_at,package_id FROM users WHERE role=? ORDER BY id DESC", [$role])->fetchAll();
        return Database::query("SELECT id,username,email,role,status,disk_quota_mb,disk_used_mb,disk_measured_at,bandwidth_quota_gb,bw_suspended,created_at,package_id FROM users ORDER BY id DESC")->fetchAll();
    }
    public static function countAll(?int $ownerId = null): int {
        if ($ownerId) return (int)Database::query("SELECT COUNT(*) c FROM users WHERE owner_id=?", [$ownerId])->fetch()['c'];
        return (int)Database::query("SELECT COUNT(*) c FROM users")->fetch()['c'];
    }
    // username/email contains-search (LIKE with \_% escaped; owner-scoped).
    public static function countSearch(?int $ownerId, string $q): int {
        $like = '%'.str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $q).'%';
        if ($ownerId) return (int)Database::query("SELECT COUNT(*) c FROM users WHERE owner_id=? AND (username LIKE ? OR email LIKE ?)", [$ownerId, $like, $like])->fetch()['c'];
        return (int)Database::query("SELECT COUNT(*) c FROM users WHERE username LIKE ? OR email LIKE ?", [$like, $like])->fetch()['c'];
    }
    public static function searchPaged(?int $ownerId, string $q, int $limit, int $offset): array {
        $limit = max(1, $limit); $offset = max(0, $offset);
        $cols = "id,username,email,role,status,disk_quota_mb,disk_used_mb,disk_measured_at,bandwidth_quota_gb,bw_suspended,created_at,package_id,totp_enabled";
        $like = '%'.str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $q).'%';
        if ($ownerId) return Database::query("SELECT $cols FROM users WHERE owner_id=? AND (username LIKE ? OR email LIKE ?) ORDER BY id DESC LIMIT ? OFFSET ?", [$ownerId, $like, $like, (int)$limit, (int)$offset])->fetchAll();
        return Database::query("SELECT $cols FROM users WHERE username LIKE ? OR email LIKE ? ORDER BY id DESC LIMIT ? OFFSET ?", [$like, $like, (int)$limit, (int)$offset])->fetchAll();
    }
    // LIMIT/OFFSET are bound as PARAM_INT (Database::query types PHP ints):
    // clamped + cast here, never raw input.
    public static function allPaged(?int $ownerId, int $limit, int $offset): array {
        $limit = max(1, $limit); $offset = max(0, $offset);
        $cols = "id,username,email,role,status,disk_quota_mb,disk_used_mb,disk_measured_at,bandwidth_quota_gb,bw_suspended,created_at,package_id,totp_enabled";
        if ($ownerId) return Database::query("SELECT $cols FROM users WHERE owner_id=? ORDER BY id DESC LIMIT ? OFFSET ?", [$ownerId, (int)$limit, (int)$offset])->fetchAll();
        return Database::query("SELECT $cols FROM users ORDER BY id DESC LIMIT ? OFFSET ?", [(int)$limit, (int)$offset])->fetchAll();
    }
    public static function create(array $d): int {
        Database::query("INSERT INTO users(username,email,password_hash,role,owner_id,package_id,disk_quota_mb,bandwidth_quota_gb,status) VALUES(?,?,?,?,?,?,?,?,?)",
            [$d['username'],$d['email'],password_hash($d['password'],PASSWORD_BCRYPT),$d['role'],$d['owner_id']??null,$d['package_id']??null,$d['disk_quota_mb']??5120,$d['bandwidth_quota_gb']??100,'active']);
        return (int)Database::conn()->lastInsertId();
    }
    // New hosting account: panel row AND system user, both or neither.
    // create-account.sh refuses (exit 3, nothing changed) when a Linux user of
    // that name already exists; any other failure (incl. the timeout) may have
    // left a half-made user of THIS run, which is torn down again. Before this,
    // the row stayed and the panel said "created" whatever the script did.
    // Returns [id, script output]. DomainException = name not available.
    public static function provision(array $d, string $php, int $kids): array {
        $taken = "Username {$d['username']} is already taken";
        if (Database::query("SELECT 1 FROM users WHERE username=?", [$d['username']])->fetch()) throw new DomainException($taken);
        try {
            $id = self::create($d);
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) throw new DomainException($taken);
            throw $e;
        }
        // 120 s: useradd + FPM pool + recursive ACLs outgrow the 15 s default on a busy box
        try {
            $r = ServiceManager::run('create-account.sh', [$d['username'], $php, new Secret((string)$d['password']), (string)$kids], 120);
        } catch (Throwable $e) {
            // unknown whether the script ran: drop the row only, never a user we may not have made
            Database::query("DELETE FROM users WHERE id=?", [$id]);
            throw $e;
        }
        $code = (int)($r['code'] ?? 1);
        if ($code === 0) return [$id, (string)$r['out']];
        Database::query("DELETE FROM users WHERE id=?", [$id]);
        if ($code !== 3) ServiceManager::run('delete-account.sh', [$d['username']], 120);
        $lines = array_values(array_filter(array_map('trim', explode("\n", (string)($r['out'] ?? ''))), 'strlen'));
        $why = $lines ? (string)end($lines) : "system account setup failed (code $code)";
        $why = preg_replace('/^refused:\s*/', '', $why);
        if ($code === 3) throw new DomainException($why);
        throw new RuntimeException($code === 124 ? 'system account setup timed out' : $why);
    }
}
