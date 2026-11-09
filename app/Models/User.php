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
        $cols = "id,username,email,role,status,disk_quota_mb,disk_used_mb,disk_measured_at,bandwidth_quota_gb,bw_suspended,created_at,package_id,totp_enabled,php_mail,php_mail_cap,php_mail_off_reason,".Auth::HAS_2FA." AS has_2fa";
        $like = '%'.str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $q).'%';
        if ($ownerId) return Database::query("SELECT $cols FROM users WHERE owner_id=? AND (username LIKE ? OR email LIKE ?) ORDER BY id DESC LIMIT ? OFFSET ?", [$ownerId, $like, $like, (int)$limit, (int)$offset])->fetchAll();
        return Database::query("SELECT $cols FROM users WHERE username LIKE ? OR email LIKE ? ORDER BY id DESC LIMIT ? OFFSET ?", [$like, $like, (int)$limit, (int)$offset])->fetchAll();
    }
    // LIMIT/OFFSET are bound as PARAM_INT (Database::query types PHP ints):
    // clamped + cast here, never raw input.
    public static function allPaged(?int $ownerId, int $limit, int $offset): array {
        $limit = max(1, $limit); $offset = max(0, $offset);
        $cols = "id,username,email,role,status,disk_quota_mb,disk_used_mb,disk_measured_at,bandwidth_quota_gb,bw_suspended,created_at,package_id,totp_enabled,php_mail,php_mail_cap,php_mail_off_reason,".Auth::HAS_2FA." AS has_2fa";
        if ($ownerId) return Database::query("SELECT $cols FROM users WHERE owner_id=? ORDER BY id DESC LIMIT ? OFFSET ?", [$ownerId, (int)$limit, (int)$offset])->fetchAll();
        return Database::query("SELECT $cols FROM users ORDER BY id DESC LIMIT ? OFFSET ?", [(int)$limit, (int)$offset])->fetchAll();
    }
    public static function create(array $d): int {
        // PHP mail for new accounts: server default (Email page -> Server), on unless set to 0
        $pm = 1;
        try { $pm = (string)(Database::query("SELECT value FROM settings WHERE name='php_mail_default'")->fetch()['value'] ?? '1') === '0' ? 0 : 1; } catch (Throwable $e) {}
        Database::query("INSERT INTO users(username,email,password_hash,role,owner_id,package_id,disk_quota_mb,bandwidth_quota_gb,status,php_mail) VALUES(?,?,?,?,?,?,?,?,?,?)",
            [$d['username'],$d['email'],Auth::hash($d['password']),$d['role'],$d['owner_id']??null,$d['package_id']??null,$d['disk_quota_mb']??5120,$d['bandwidth_quota_gb']??100,'active',$pm]);
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
    // Delete a hosting account everywhere: mail aliases, real MySQL objects,
    // parked aliases, the system side (delete-account.sh), then the panel
    // rows. Throws on a failed system teardown (rows kept, so it can be
    // retried). Used by Accounts → Delete, the API terminate call and an
    // Account Transfer rollback. Backup files on disk are kept.
    public static function teardown(array $t): void {
        if (($t['role'] ?? '') === 'admin' || !Helpers::validUsername((string)$t['username'])) throw new DomainException('not a deletable account');
        $uid = (int)$t['id'];
        $mails = Database::query("SELECT e.localpart, d.domain FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE e.user_id=?", [$uid])->fetchAll();
        $domIds = Database::query("SELECT id, domain FROM domains WHERE user_id=?", [$uid])->fetchAll();
        // privileged teardown first: mail aliases, real MySQL objects, then everything system
        foreach ($mails as $m) ServiceManager::run('email-remove.sh', [$m['localpart'].'@'.$m['domain']]);
        MysqlDb::dropAllFor($uid);
        // parked aliases first: delete-account only walks /home/<user>/<domain> zones
        foreach ($domIds as $row) {
            $dn = Database::query("SELECT domain FROM domains WHERE id=?", [$row['id']])->fetchColumn();
            foreach (Database::query("SELECT alias FROM domain_aliases WHERE domain_id=?", [$row['id']])->fetchAll() as $a) {
                ServiceManager::run('vhost-unalias.sh', [(string)$dn, $a['alias']]);
            }
        }
        $del = ServiceManager::run('delete-account.sh', [$t['username']]);
        if (($del['code'] ?? 1) !== 0) throw new RuntimeException(trim($del['out']));
        // panel rows (backup files on disk are kept for safety)
        Database::query("DELETE FROM cron_jobs WHERE user_id=?", [$uid]);
        Database::query("DELETE FROM email_accounts WHERE user_id=?", [$uid]);
        Database::query("DELETE FROM mail_imports WHERE user_id=?", [$uid]);
        Database::query("DELETE FROM ftp_accounts WHERE user_id=?", [$uid]);
        Database::query("DELETE FROM mysql_dbs WHERE user_id=?", [$uid]);
        Database::query("DELETE FROM api_tokens WHERE user_id=?", [$uid]);
        Database::query("DELETE FROM backups WHERE user_id=?", [$uid]);
        Database::query("DELETE FROM notifications WHERE user_id=?", [$uid]);
        foreach ($domIds as $row) Database::query("DELETE FROM dns_records WHERE domain_id=?", [$row['id']]);
        foreach ($domIds as $row) MailAutopilot::purgeDomain((int)$row['id']);
        foreach ($domIds as $row) DomainModel::purgeRows((int)$row['id']);
        ServiceManager::run('email-filters-sync.sh', []);
        ServiceManager::run('list-sync.sh', []);
        Database::query("DELETE FROM domains WHERE user_id=?", [$uid]);
        // LE certs outlive delete-account.sh — drop them (no orphan renewals)
        foreach ($domIds as $row) ServiceManager::run('ssl-remove.sh', [(string)$row['domain']]);
        Database::query("DELETE FROM packages WHERE reseller_id=?", [$uid]);
        Database::query("UPDATE users SET owner_id=NULL WHERE owner_id=?", [$uid]);
        Transfer::purgeRows($uid);   // every other per-account table (installs, builder, daemons, forwards…)
        Database::query("DELETE FROM users WHERE id=?", [$uid]);
    }

    public static function find(int $id): ?array {
        return Database::query("SELECT * FROM users WHERE id=?", [$id])->fetch() ?: null;
    }
    public static function findByName(string $username): ?array {
        return Database::query("SELECT * FROM users WHERE username=?", [$username])->fetch() ?: null;
    }
    public static function username(int $id): string {
        return (string)(Database::query("SELECT username FROM users WHERE id=?", [$id])->fetch()['username'] ?? '');
    }

    // ------------------------------------------------------- account admin
    public static function nsPair(int $id): array {
        return Database::query("SELECT ns1,ns2 FROM users WHERE id=?", [$id])->fetch() ?: ['ns1' => '', 'ns2' => ''];
    }
    public static function setNs(int $id, ?string $ns1, ?string $ns2): void {
        Database::query("UPDATE users SET ns1=?, ns2=? WHERE id=?", [$ns1, $ns2, $id]);
    }
    public static function clearNs(int $id): void {
        Database::query("UPDATE users SET ns1=NULL, ns2=NULL WHERE id=?", [$id]);
    }
    /** disk/bandwidth quota of one account (reseller self-limits) */
    public static function quotaOf(int $id): ?array {
        return Database::query("SELECT disk_quota_mb,bandwidth_quota_gb FROM users WHERE id=?", [$id])->fetch() ?: null;
    }
    public static function planRow(int $id): ?array {
        return Database::query("SELECT role,disk_quota_mb,bandwidth_quota_gb FROM users WHERE id=?", [$id])->fetch() ?: null;
    }
    /** quota totals of a reseller's other active clients (plan-change guard) */
    public static function sumsForOwner(int $ownerId, int $exceptId): array {
        return Database::query(
            "SELECT COALESCE(SUM(disk_quota_mb),0) d, COALESCE(SUM(bandwidth_quota_gb),0) b FROM users WHERE owner_id=? AND status='active' AND id!=?",
            [$ownerId, $exceptId]
        )->fetch() ?: ['d' => 0, 'b' => 0];
    }
    /** per-owner totals excluding clients already on $packageId */
    public static function sumsForOwnerExceptPackage(int $ownerId, int $packageId): array {
        return Database::query(
            "SELECT COALESCE(SUM(CASE WHEN package_id<=>? THEN 0 ELSE disk_quota_mb END),0) d,
                    COALESCE(SUM(CASE WHEN package_id<=>? THEN 0 ELSE bandwidth_quota_gb END),0) b,
                    COALESCE(SUM(package_id<=>?),0) n
             FROM users WHERE owner_id=? AND status='active'",
            [$packageId, $packageId, $packageId, $ownerId]
        )->fetch() ?: ['d' => 0, 'b' => 0, 'n' => 0];
    }
    public static function setPackage(int $id, ?int $packageId, int $disk, int $bw): void {
        Database::query(
            "UPDATE users SET package_id=?, disk_quota_mb=?, bandwidth_quota_gb=? WHERE id=?", [$packageId, $disk, $bw, $id]
        );
    }
    /** how many rows a plan limit counts (allowlisted tables) */
    public static function usageCount(string $table, int $uid): int {
        if (!in_array($table, ['domains', 'email_accounts', 'mysql_dbs', 'ftp_accounts', 'builder_sites', 'app_daemons'], true)) return 0;
        return (int)Database::query("SELECT COUNT(*) c FROM $table WHERE user_id=?", [$uid])->fetch()['c'];
    }

    public static function existsByName(string $username): bool {
        return (bool)Database::query("SELECT id FROM users WHERE username=?", [$username])->fetch();
    }
    public static function setEmail(int $id, string $email): void {
        Database::query("UPDATE users SET email=? WHERE id=?", [$email, $id]);
    }
    public static function setRole(int $id, string $role): void {
        Database::query("UPDATE users SET role=? WHERE id=?", [$role, $id]);
    }
    public static function countOtherAdmins(int $id): int {
        return (int)Database::query(
            "SELECT COUNT(*) c FROM users WHERE role='admin' AND status='active' AND id!=?", [$id]
        )->fetch()['c'];
    }
    public static function countClients(int $ownerId): int {
        return (int)Database::query("SELECT COUNT(*) c FROM users WHERE owner_id=?", [$ownerId])->fetch()['c'];
    }
    public static function setPasswordHash(int $id, string $hash): void {
        Database::query("UPDATE users SET password_hash=? WHERE id=?", [$hash, $id]);
    }
    /** suspend/unsuspend: clears the bandwidth flag too */
    public static function setStatusReset(int $id, string $status): void {
        Database::query("UPDATE users SET status=?, bw_suspended=0 WHERE id=?", [$status, $id]);
    }
    public static function passwordHash(int $id): ?string {
        $r = Database::query("SELECT password_hash FROM users WHERE id=?", [$id])->fetch();
        return $r === false ? null : (string)$r['password_hash'];
    }
    /** enable the authenticator app with a fresh secret (step already spent) */
    public static function setTotp(int $id, string $secret, int $step): void {
        Database::query("UPDATE users SET totp_secret=?, totp_enabled=1, totp_last_step=? WHERE id=?", [$secret, $step, $id]);
    }
    public static function clearTotpApp(int $id): void {
        Database::query("UPDATE users SET totp_secret=NULL, totp_enabled=0 WHERE id=?", [$id]);
    }
    /** admin/reseller accounts without a second factor */
    public static function without2fa(): array {
        return Database::query(
            "SELECT username, role FROM users WHERE role IN ('admin','reseller') AND NOT " . Auth::HAS_2FA . " ORDER BY role, username"
        )->fetchAll();
    }
    /** accounts (never admins) by disk pressure, for the abuse board */
    public static function abuseCount(string $q): int {
        if ($q === '') return (int)Database::query("SELECT COUNT(*) c FROM users WHERE role!='admin'")->fetch()['c'];
        return (int)Database::query(
            "SELECT COUNT(*) c FROM users WHERE role!='admin' AND username LIKE ?", ['%'.addcslashes($q, '%_\\').'%']
        )->fetch()['c'];
    }
    public static function abusePage(string $q, int $limit, int $offset): array {
        if ($q === '') {
            return Database::query(
                "SELECT * FROM users WHERE role!='admin' ORDER BY COALESCE(disk_used_mb,0)/GREATEST(disk_quota_mb,1) DESC, username LIMIT ? OFFSET ?",
                [$limit, $offset]
            )->fetchAll();
        }
        $like = '%'.addcslashes($q, '%_\\').'%';
        return Database::query(
            "SELECT * FROM users WHERE role!='admin' AND username LIKE ? ORDER BY COALESCE(disk_used_mb,0)/GREATEST(disk_quota_mb,1) DESC, username LIMIT ? OFFSET ?",
            [$like, $limit, $offset]
        )->fetchAll();
    }
    /** username => [role, status] (daemon/process attribution) */
    public static function roster(): array {
        $out = [];
        foreach (Database::query("SELECT username, role, status FROM users")->fetchAll() as $x) {
            $out[$x['username']] = [$x['role'], $x['status']];
        }
        return $out;
    }
    /** phpMyAdmin one-time gate hash (null when never set) */
    public static function pmaGate(int $uid): ?string {
        $v = Database::query("SELECT pma_gate_hash FROM users WHERE id=?", [$uid])->fetch()['pma_gate_hash'] ?? null;
        return $v === null ? null : (string)$v;
    }
    public static function setPmaGate(int $uid, ?string $hash): void {
        Database::query("UPDATE users SET pma_gate_hash=? WHERE id=?", [$hash, $uid]);
    }
    public static function emailOf(int $id): string {
        return (string)(Database::query("SELECT email FROM users WHERE id=?", [$id])->fetch()['email'] ?? '');
    }
    public static function passkeys(int $id): array {
        return Database::query(
            "SELECT id, name, rp_id, synced, created_at, last_used_at FROM user_passkeys WHERE user_id=? ORDER BY id", [$id]
        )->fetchAll();
    }
    public static function passkeyCreds(int $id, string $rp): array {
        return Database::query("SELECT cred_id FROM user_passkeys WHERE user_id=? AND rp_id=?", [$id, $rp])->fetchAll();
    }
    public static function addPasskey(int $uid, array $r, string $rp, string $name): void {
        Database::query(
            "INSERT INTO user_passkeys(user_id,cred_id,rp_id,public_key,alg,sign_count,synced,name) VALUES(?,?,?,?,?,?,?,?)",
            [$uid, $r['cred_id'], $rp, $r['public_key'], $r['alg'], $r['sign_count'], $r['synced'], $name]
        );
    }
    public static function passkey(int $uid, int $id): ?array {
        return Database::query("SELECT id, name FROM user_passkeys WHERE id=? AND user_id=?", [$id, $uid])->fetch() ?: null;
    }
    public static function deletePasskey(int $uid, int $id): void {
        Database::query("DELETE FROM user_passkeys WHERE id=? AND user_id=?", [$id, $uid]);
    }
    public static function clearTotp(int $id): void {
        Database::query("UPDATE users SET totp_secret=NULL, totp_enabled=0, totp_last_step=NULL WHERE id=?", [$id]);
    }
    public static function clearPasskeys(int $id): void {
        Database::query("DELETE FROM user_passkeys WHERE user_id=?", [$id]);
    }
    /** just the columns the PHP-mail card needs */
    public static function phpMailRow(int $id): ?array {
        return Database::query("SELECT id, username, php_mail FROM users WHERE id=?", [$id])->fetch() ?: null;
    }
    public static function phpMailOn(int $id, ?int $cap): void {
        Database::query("UPDATE users SET php_mail=1, php_mail_cap=?, php_mail_off_reason=NULL WHERE id=?", [$cap, $id]);
    }
    /** reason/fence BEFORE php_mail=0: MariaDB applies SET left to right */
    public static function phpMailOff(int $id, string $reason, ?int $cap): void {
        Database::query(
            "UPDATE users SET php_mail_off_reason=IF(php_mail=1, ?, php_mail_off_reason), php_mail_off_at=IF(php_mail=1, NOW(), php_mail_off_at), php_mail=0, php_mail_cap=? WHERE id=?",
            [$reason, $cap, $id]
        );
    }
}
