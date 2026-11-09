<?php
declare(strict_types=1);
/**
 * ApiModel — the SQL behind the REST/provisioning API (tokens, domain and DNS
 * rows, databases, backups, SSL/rewrite flags). Mailbox and forwarder queries
 * stay in EmailModel — one home per table group.
 *
 * Every method is a verbatim copy of the query ApiController used to run
 * inline; no behaviour is added here.
 */
class ApiModel {

    // ----------------------------------------------------------------- tokens
    public static function token(string $hash): ?array {
        return Database::query(
            "SELECT u.*, t.allowed_ip, t.id AS token_id FROM api_tokens t JOIN users u ON u.id=t.user_id
             WHERE t.token=SHA2(?,256) AND u.status='active' AND (t.expires_at IS NULL OR t.expires_at > NOW())",
            [$hash]
        )->fetch() ?: null;
    }
    /** at most one write a minute per token (callers wrap this in try/catch) */
    public static function touchToken(int $tokenId, string $ip): void {
        Database::query(
            "UPDATE api_tokens SET last_used_at=NOW(), last_used_ip=? WHERE id=? AND (last_used_at IS NULL OR last_used_at < NOW() - INTERVAL 1 MINUTE OR last_used_ip <> ?)",
            [$ip, $tokenId, $ip]
        );
    }
    public static function countTokens(int $uid): int {
        return (int)Database::query("SELECT COUNT(*) c FROM api_tokens WHERE user_id=?", [$uid])->fetch()['c'];
    }
    public static function tokenPage(int $uid, int $limit, int $offset): array {
        return Database::query(
            "SELECT id,label,allowed_ip,created_at,expires_at,last_used_at,last_used_ip FROM api_tokens WHERE user_id=? ORDER BY id DESC LIMIT ? OFFSET ?",
            [$uid, $limit, $offset]
        )->fetchAll();
    }
    public static function insertToken(int $uid, string $label, string $token, int $days): void {
        Database::query(
            "INSERT INTO api_tokens(user_id,label,token,expires_at) VALUES(?,?,SHA2(?,256),IF(?>0, NOW() + INTERVAL ? DAY, NULL))",
            [$uid, $label, $token, $days, $days]
        );
    }
    public static function deleteToken(int $id, int $uid): void {
        Database::query("DELETE FROM api_tokens WHERE id=? AND user_id=?", [$id, $uid]);
    }
    public static function setTokenIp(int $id, int $uid, ?string $ip): void {
        Database::query("UPDATE api_tokens SET allowed_ip=? WHERE id=? AND user_id=?", [$ip, $id, $uid]);
    }

    // ------------------------------------------------------------------ users
    public static function userByUsername(string $username): ?array {
        return Database::query("SELECT * FROM users WHERE username=?", [$username])->fetch() ?: null;
    }
    public static function username(int $uid): string {
        return User::username($uid);
    }
    public static function userEmail(int $uid): ?array {
        return Database::query("SELECT email FROM users WHERE id=?", [$uid])->fetch() ?: null;
    }
    public static function setStatus(int $uid, string $status): void {
        User::setStatusReset($uid, $status);
    }
    public static function setPasswordHash(int $uid, string $hash): void {
        User::setPasswordHash($uid, $hash);
    }

    // ---------------------------------------------------------------- domains
    public static function domainsFor(int $uid): array {
        return DomainModel::rowsFor($uid);
    }
    public static function domainById(int $id): ?array {
        return DomainModel::find($id);
    }
    public static function domainByIdForUser(int $id, int $uid): ?array {
        return DomainModel::findForUser($id, $uid);
    }
    public static function domainByNameForUser(string $domain, int $uid): ?array {
        return DomainModel::findByNameForUser($domain, $uid);
    }
    /** the PHP version already in use on any of the account's domains */
    public static function effectivePhp(int $uid): ?string {
        return DomainModel::effectivePhp($uid);
    }
    public static function insertDomain(int $uid, string $domain, string $docroot, string $php): int {
        return DomainModel::insert($uid, $domain, $docroot, $php);
    }
    public static function deleteDomain(int $id): void {
        DomainModel::delete($id);
    }
    public static function aliasesFor(int $domainId): array {
        return DomainModel::aliasesFor($domainId);
    }
    public static function enableSslByDomain(string $domain): void {
        DomainModel::enableSsl($domain);
    }
    public static function setRewriteRules(int $domainId, string $rules): void {
        Database::query("UPDATE domains SET rewrite_rules=?, rewrite_src_hash=NULL WHERE id=?", [$rules, $domainId]);
    }
    public static function setRewriteFromHtaccess(int $domainId, string $rules, string $srcHash): void {
        Database::query("UPDATE domains SET rewrite_rules=?, rewrite_src_hash=?, rewrite_imported_at=NOW() WHERE id=?",
            [$rules, $srcHash, $domainId]);
    }

    // -------------------------------------------------------------------- DNS
    public static function dnsRecordsFor(int $domainId): array {
        return Database::query(
            "SELECT id,type,host,value,priority,ttl FROM dns_records WHERE domain_id=? ORDER BY host,type", [$domainId]
        )->fetchAll();
    }
    public static function dnsRecordOwned(int $id, int $uid): ?array {
        return Database::query(
            "SELECT r.*, d.domain FROM dns_records r JOIN domains d ON d.id=r.domain_id WHERE r.id=? AND d.user_id=?",
            [$id, $uid]
        )->fetch() ?: null;
    }
    public static function insertDnsRecord(int $domainId, string $type, string $host, string $value, int $prio, int $ttl): int {
        Database::query(
            "INSERT INTO dns_records(domain_id,type,host,value,priority,ttl) VALUES(?,?,?,?,?,?)",
            [$domainId, $type, $host, $value, $prio, $ttl]
        );
        return (int)Database::conn()->lastInsertId();
    }
    public static function updateDnsRecord(int $id, string $type, string $host, string $value, int $prio, int $ttl): void {
        Database::query(
            "UPDATE dns_records SET type=?,host=?,value=?,priority=?,ttl=? WHERE id=?",
            [$type, $host, $value, $prio, $ttl, $id]
        );
    }
    public static function deleteDnsRecord(int $id): void {
        Database::query("DELETE FROM dns_records WHERE id=?", [$id]);
    }
    public static function deleteDnsFor(int $domainId): void {
        Database::query("DELETE FROM dns_records WHERE domain_id=?", [$domainId]);
    }
    public static function cnameClash(int $domainId, string $host, string $type): int {
        return (int)Database::query(
            "SELECT COUNT(*) c FROM dns_records WHERE domain_id=? AND host=? AND ((type='CNAME') OR (?='CNAME'))",
            [$domainId, $host, $type]
        )->fetch()['c'];
    }
    public static function cnameClashExcept(int $domainId, string $host, string $type, int $exceptId): int {
        return (int)Database::query(
            "SELECT COUNT(*) c FROM dns_records WHERE domain_id=? AND host=? AND id<>? AND ((type='CNAME') OR (?='CNAME'))",
            [$domainId, $host, $exceptId, $type]
        )->fetch()['c'];
    }

    // ---------------------------------------------------- databases / backups
    public static function dbsFor(int $uid): array {
        return Database::query(
            "SELECT id,db_name,db_user,created_at FROM mysql_dbs WHERE user_id=? ORDER BY db_name", [$uid]
        )->fetchAll();
    }
    public static function dbOwned(int $id, int $uid): ?array {
        return Database::query("SELECT * FROM mysql_dbs WHERE id=? AND user_id=?", [$id, $uid])->fetch() ?: null;
    }
    public static function backupsFor(int $uid): array {
        return Database::query(
            "SELECT id,filename,size_bytes,created_at FROM backups WHERE user_id=? ORDER BY id DESC LIMIT 50", [$uid]
        )->fetchAll();
    }
}
