<?php
declare(strict_types=1);
/**
 * LoginAttempt — the login_attempts ledger plus the two second-factor reads
 * (TOTP row, passkey credential ids). Kept apart from Auth so the throttle
 * rules stay readable next to their queries.
 */
class LoginAttempt {

    public static function failuresByIp(string $ip): int {
        return (int)Database::query(
            "SELECT COUNT(*) c FROM login_attempts WHERE ip=? AND success=0 AND attempted_at > (NOW() - INTERVAL 15 MINUTE)",
            [$ip]
        )->fetch()['c'];
    }
    public static function failuresByUser(string $username): int {
        return (int)Database::query(
            "SELECT COUNT(*) c FROM login_attempts WHERE username=? AND success=0 AND attempted_at > (NOW() - INTERVAL 15 MINUTE)",
            [$username]
        )->fetch()['c'];
    }
    /** whole minutes left on the wait (the oldest failure ages out of the window) */
    public static function waitMinutes(bool $byIp, string $key, int $limit): int {
        $col = $byIp ? 'ip' : 'username';
        $limit = max(1, $limit);
        // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string -- $col is one of two literals; value bound
        return (int)Database::query(
            "SELECT GREATEST(1, CEIL(TIMESTAMPDIFF(SECOND, NOW(), MIN(attempted_at) + INTERVAL 15 MINUTE) / 60)) m FROM (SELECT attempted_at FROM login_attempts WHERE $col=? AND success=0 AND attempted_at > (NOW() - INTERVAL 15 MINUTE) ORDER BY attempted_at DESC LIMIT $limit) t",
            [$key]
        )->fetch()['m'];
    }
    public static function record(string $ip, string $username): void {
        Database::query("INSERT INTO login_attempts(ip,username,success) VALUES(?,?,0)", [$ip, $username]);
    }
    /** clear one bucket name across IPs (the password:UID re-check counter) */
    public static function clearBucket(string $username): void {
        Database::query("DELETE FROM login_attempts WHERE username=?", [$username]);
    }
    public static function clearFor(string $ip, string $username): void {
        Database::query("DELETE FROM login_attempts WHERE ip=? AND username=?", [$ip, $username]);
    }
    /** clear one IP's failures for several bucket names at once */
    public static function clearForMany(string $ip, array $usernames): void {
        if (!$usernames) return;
        $in = implode(',', array_fill(0, count($usernames), '?'));
        Database::query("DELETE FROM login_attempts WHERE ip=? AND username IN ($in)", array_merge([$ip], array_values($usernames)));
    }

    // ------------------------------------------------------- second factor
    public static function totpEnabled(int $uid): bool {
        $r = Database::query("SELECT totp_enabled FROM users WHERE id=?", [$uid])->fetch();
        return !empty($r['totp_enabled']);
    }
    public static function totpRow(int $uid): ?array {
        return Database::query("SELECT username, totp_secret FROM users WHERE id=? AND totp_enabled=1", [$uid])->fetch() ?: null;
    }
    /** replay guard: each 30s step is claimed once (atomic conditional update) */
    public static function claimTotpStep(int $uid, int $step): bool {
        return Database::query(
            "UPDATE users SET totp_last_step=? WHERE id=? AND (totp_last_step IS NULL OR totp_last_step < ?)",
            [$step, $uid, $step]
        )->rowCount() === 1;
    }
    public static function passkeyIds(int $uid, string $rp): array {
        return Database::query("SELECT cred_id FROM user_passkeys WHERE user_id=? AND rp_id=?", [$uid, $rp])->fetchAll();
    }
}
