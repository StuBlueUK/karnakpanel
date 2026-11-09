<?php
declare(strict_types=1);
class DomainModel {
    // parent = longest domain owned by $ownerId that $fqdn ends with ".<parent>".
    // Used by both the Domains tab (subdomain detection) and the Subdomains tab.
    public static function parentOf(string $fqdn, int $ownerId): ?array {
        foreach (Database::query("SELECT * FROM domains WHERE user_id=? ORDER BY LENGTH(domain) DESC", [$ownerId])->fetchAll() as $p) {
            if ($fqdn !== $p['domain'] && str_ends_with($fqdn, '.'.$p['domain'])) return $p;
        }
        return null;
    }
    public static function forUser(int $uid): array {
        return Database::query("SELECT * FROM domains WHERE user_id=? ORDER BY domain", [$uid])->fetchAll();
    }
    public static function all(): array {
        return Database::query("SELECT d.*, u.username FROM domains d JOIN users u ON u.id=d.user_id ORDER BY d.id DESC")->fetchAll();
    }
    public const PER_PAGE = 25;
    public static function countForUser(int $uid): int {
        return (int)Database::query("SELECT COUNT(*) c FROM domains WHERE user_id=?", [$uid])->fetch()['c'];
    }
    public static function countAll(): int {
        return (int)Database::query("SELECT COUNT(*) c FROM domains")->fetch()['c'];
    }
    private static function like(string $q): string {
        return '%'.str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $q).'%';
    }
    public static function countSearch(?int $uid, string $q): int {
        $like = self::like($q);
        if ($uid) return (int)Database::query("SELECT COUNT(*) c FROM domains WHERE user_id=? AND domain LIKE ?", [$uid, $like])->fetch()['c'];
        return (int)Database::query("SELECT COUNT(*) c FROM domains WHERE domain LIKE ?", [$like])->fetch()['c'];
    }
    public static function searchPaged(?int $uid, string $q, int $limit, int $offset): array {
        $limit = max(1, $limit); $offset = max(0, $offset);
        $like = self::like($q);
        if ($uid) return Database::query("SELECT * FROM domains WHERE user_id=? AND domain LIKE ? ORDER BY domain LIMIT ? OFFSET ?", [$uid, $like, (int)$limit, (int)$offset])->fetchAll();
        return Database::query("SELECT d.*, u.username FROM domains d JOIN users u ON u.id=d.user_id WHERE d.domain LIKE ? ORDER BY d.id DESC LIMIT ? OFFSET ?", [$like, (int)$limit, (int)$offset])->fetchAll();
    }
    // LIMIT/OFFSET are bound as PARAM_INT (Database::query types PHP ints):
    // clamped + cast here, never raw input.
    public static function forUserPaged(int $uid, int $limit, int $offset): array {
        $limit = max(1, $limit); $offset = max(0, $offset);
        return Database::query("SELECT * FROM domains WHERE user_id=? ORDER BY domain LIMIT ? OFFSET ?", [$uid, (int)$limit, (int)$offset])->fetchAll();
    }
    public static function allPaged(int $limit, int $offset): array {
        $limit = max(1, $limit); $offset = max(0, $offset);
        return Database::query("SELECT d.*, u.username FROM domains d JOIN users u ON u.id=d.user_id ORDER BY d.id DESC LIMIT ? OFFSET ?", [(int)$limit, (int)$offset])->fetchAll();
    }
    // Panel rows scoped to one domain (not the domains row itself, not DNS
    // or mail reports — callers order those). Shared by domain delete and
    // account delete so an account teardown leaves no orphans (site_installs
    // rows outlived their account until Oct 2026). Callers then run
    // email-filters-sync.sh + list-sync.sh once.
    public static function purgeRows(int $domainId): void {
        foreach (['domain_aliases','preview_tokens','email_forwards','email_filters','mail_scores','site_installs','mail_imports'] as $t) {
            Database::query("DELETE FROM $t WHERE domain_id=?", [$domainId]);
        }
        $ll = Database::query("SELECT id FROM email_lists WHERE domain_id=?", [$domainId])->fetchAll();
        foreach ($ll as $l) Database::query("DELETE FROM email_list_members WHERE list_id=?", [$l['id']]);
        Database::query("DELETE FROM email_lists WHERE domain_id=?", [$domainId]);
    }

    // ------------------------------------------------------------ single rows
    public static function find(int $id): ?array {
        return Database::query("SELECT * FROM domains WHERE id=?", [$id])->fetch() ?: null;
    }
    public static function findForUser(int $id, int $uid): ?array {
        return Database::query("SELECT * FROM domains WHERE id=? AND user_id=?", [$id, $uid])->fetch() ?: null;
    }
    public static function findByName(string $domain): ?array {
        return Database::query("SELECT * FROM domains WHERE domain=?", [$domain])->fetch() ?: null;
    }
    public static function findByNameForUser(string $domain, int $uid): ?array {
        return Database::query("SELECT * FROM domains WHERE domain=? AND user_id=?", [$domain, $uid])->fetch() ?: null;
    }
    public static function ownedByName(string $domain, int $uid): bool {
        return (bool)Database::query("SELECT 1 FROM domains WHERE domain=? AND user_id=?", [$domain, $uid])->fetch();
    }
    public static function ownerId(int $id): ?int {
        $v = Database::query("SELECT user_id FROM domains WHERE id=?", [$id])->fetch()['user_id'] ?? null;
        return $v === null ? null : (int)$v;
    }
    /** rewrite-rules rows for the redirects page ($uid null = whole box) */
    public static function rewriteRows(?int $uid): array {
        if ($uid === null) return Database::query("SELECT d.id, d.domain, d.rewrite_rules FROM domains d ORDER BY d.domain")->fetchAll();
        return Database::query("SELECT d.id, d.domain, d.rewrite_rules FROM domains d WHERE d.user_id=? ORDER BY d.domain", [$uid])->fetchAll();
    }
    /** redirects page: rules only, import tracking untouched */
    public static function setRewriteRulesOnly(int $id, string $rules): void {
        Database::query("UPDATE domains SET rewrite_rules=? WHERE id=?", [$rules, $id]);
    }
    /** domains on a PHP version no longer offered (admin banner) */
    public static function stalePhp(array $selectable): array {
        if (!$selectable) return [];
        $ph = implode(',', array_fill(0, count($selectable), '?'));
        return Database::query(
            "SELECT d.php_version AS v, COUNT(DISTINCT d.user_id) AS n,
                GROUP_CONCAT(DISTINCT u.username ORDER BY u.username SEPARATOR ', ') AS who
             FROM domains d JOIN users u ON u.id=d.user_id
             WHERE d.php_version NOT IN ($ph) GROUP BY d.php_version ORDER BY d.php_version",
            array_values($selectable)
        )->fetchAll();
    }
    /** accounts (with one domain each) still on a version, for the bulk move */
    public static function accountsOnPhp(string $version): array {
        return Database::query(
            "SELECT u.*, MIN(d.domain) AS one_domain FROM domains d JOIN users u ON u.id=d.user_id
             WHERE d.php_version=? GROUP BY u.id ORDER BY u.username", [$version]
        )->fetchAll();
    }
    public static function setPhpVersion(int $id, string $version): void {
        Database::query("UPDATE domains SET php_version=? WHERE id=?", [$version, $id]);
    }
    /** the domain named $domain if it is in the caller's scope (admins: all) */
    public static function findInScope(array $u, string $domain): ?array {
        $name = strtolower($domain);
        $rows = ($u['role'] === 'admin') ? self::all() : self::forUser((int)$u['id']);
        foreach ($rows as $d) if (strtolower((string)$d['domain']) === $name) return $d;
        return null;
    }
    public static function findWithOwner(int $id): ?array {
        return Database::query("SELECT d.*, u.username FROM domains d JOIN users u ON u.id=d.user_id WHERE d.id=?", [$id])->fetch() ?: null;
    }
    public static function namesAll(): array {
        return Database::query("SELECT domain FROM domains")->fetchAll(PDO::FETCH_COLUMN);
    }
    public static function namesFor(int $uid): array {
        return Database::query("SELECT domain FROM domains WHERE user_id=?", [$uid])->fetchAll(PDO::FETCH_COLUMN);
    }
    /** mail rows still attached to a domain (mailbox/forwarder/list) */
    public static function mailCount(int $domainId, string $table): int {
        if (!in_array($table, ['email_accounts', 'email_forwards', 'email_lists'], true)) return 0;
        return (int)Database::query("SELECT COUNT(*) c FROM $table WHERE domain_id=?", [$domainId])->fetch()['c'];
    }
    // ------------------------------------------------- security advisor
    public static function securitySummary(): array {
        return Database::query(
            "SELECT COUNT(*) n, SUM(sec_headers=0) hdr, SUM(waf=0) waf, SUM(ssl_enabled=0) nossl,
                SUM(ssl_enabled=1 AND ssl_expires IS NOT NULL AND ssl_expires <= CURDATE() + INTERVAL 14 DAY) exp FROM domains"
        )->fetch() ?: ['n' => 0, 'hdr' => 0, 'waf' => 0, 'nossl' => 0, 'exp' => 0];
    }
    public static function securityRows(): array {
        return Database::query("SELECT domain, user_id, sec_headers, waf, ssl_enabled FROM domains ORDER BY domain")->fetchAll();
    }
    public static function countOnPhp(string $version): int {
        return (int)Database::query("SELECT COUNT(*) c FROM domains WHERE php_version=?", [$version])->fetch()['c'];
    }
    public static function noexecOffRows(): array {
        return Database::query("SELECT domain, user_id FROM domains WHERE noexec=0")->fetchAll();
    }
    public static function noexecOffWithPaths(): array {
        return Database::query("SELECT domain, noexec_paths FROM domains WHERE noexec=0 ORDER BY domain")->fetchAll();
    }
    public static function headersOffDomains(): array {
        return Database::query("SELECT domain FROM domains WHERE sec_headers=0 ORDER BY domain")->fetchAll();
    }
    /** domains with SSL on, for the services card */
    public static function sslEnabledList(): array {
        return Database::query("SELECT id,domain FROM domains WHERE ssl_enabled=1 ORDER BY domain")->fetchAll();
    }
    public static function existsByName(string $domain): bool {
        return (bool)Database::query("SELECT 1 FROM domains WHERE domain=?", [$domain])->fetch();
    }
    public static function deleteForUser(int $id, int $uid): void {
        Database::query("DELETE FROM domains WHERE id=? AND user_id=?", [$id, $uid]);
    }
    public static function delete(int $id): void {
        Database::query("DELETE FROM domains WHERE id=?", [$id]);
    }
    /** first remaining domain of an account (pool convergence after a delete) */
    public static function firstFor(int $uid): ?array {
        return Database::query("SELECT * FROM domains WHERE user_id=? LIMIT 1", [$uid])->fetch() ?: null;
    }
    // ------------------------------------------------------------- tab lists
    /** admin: whole box; otherwise the account's own rows (with username) */
    public static function listFor(?int $uid): array {
        if ($uid === null) return Database::query("SELECT d.*, u.username FROM domains d JOIN users u ON u.id=d.user_id ORDER BY d.id DESC")->fetchAll();
        return Database::query("SELECT d.*, u.username FROM domains d JOIN users u ON u.id=d.user_id WHERE d.user_id=? ORDER BY d.id DESC", [$uid])->fetchAll();
    }
    /** published Karnak Builder sites (admin: whole box) */
    public static function publishedSites(?int $uid): array {
        $sql = "SELECT s.id, s.user_id, s.subdir, d.id domain_id, d.domain FROM builder_sites s JOIN domains d ON d.id=s.domain_id AND d.user_id=s.user_id WHERE s.status='published'";
        if ($uid === null) return Database::query($sql)->fetchAll();
        return Database::query("$sql AND s.user_id=?", [$uid])->fetchAll();
    }
    public static function aliasesForUser(int $uid): array {
        return Database::query(
            "SELECT a.*, d.domain FROM domain_aliases a JOIN domains d ON d.id=a.domain_id WHERE a.user_id=? ORDER BY a.alias", [$uid]
        )->fetchAll();
    }
    public static function aliasRow(int $id): ?array {
        return Database::query(
            "SELECT a.*, d.domain FROM domain_aliases a JOIN domains d ON d.id=a.domain_id WHERE a.id=?", [$id]
        )->fetch() ?: null;
    }
    public static function aliasTaken(string $alias): bool {
        return (bool)Database::query(
            "SELECT 1 FROM domains WHERE domain=? UNION SELECT 1 FROM domain_aliases WHERE alias=? LIMIT 1", [$alias, $alias]
        )->fetch();
    }
    public static function insertAlias(int $uid, int $domainId, string $alias): void {
        Database::query("INSERT INTO domain_aliases(user_id,domain_id,alias) VALUES(?,?,?)", [$uid, $domainId, $alias]);
    }
    public static function deleteAliasByAlias(string $alias): void {
        Database::query("DELETE FROM domain_aliases WHERE alias=?", [$alias]);
    }
    public static function deleteAlias(int $id): void {
        Database::query("DELETE FROM domain_aliases WHERE id=?", [$id]);
    }
    public static function previewsFor(?int $uid): array {
        if ($uid === null) return Database::query("SELECT p.*, d.domain FROM preview_tokens p JOIN domains d ON d.id=p.domain_id ORDER BY d.domain")->fetchAll();
        return Database::query("SELECT p.*, d.domain FROM preview_tokens p JOIN domains d ON d.id=p.domain_id WHERE p.user_id=? ORDER BY d.domain", [$uid])->fetchAll();
    }
    public static function previewRow(int $id): ?array {
        return Database::query(
            "SELECT p.*, d.domain FROM preview_tokens p JOIN domains d ON d.id=p.domain_id WHERE p.id=?", [$id]
        )->fetch() ?: null;
    }
    public static function insertPreview(int $uid, int $domainId, string $token): void {
        Database::query("INSERT INTO preview_tokens(user_id,domain_id,token) VALUES(?,?,?)", [$uid, $domainId, $token]);
    }
    public static function deletePreviewByToken(string $token): void {
        Database::query("DELETE FROM preview_tokens WHERE token=?", [$token]);
    }
    public static function deletePreview(int $id): void {
        Database::query("DELETE FROM preview_tokens WHERE id=?", [$id]);
    }
    // ------------------------------------------------------------ row flags
    public static function setMaintenance(int $id, int $on): void {
        Database::query("UPDATE domains SET maintenance=? WHERE id=?", [$on, $id]);
    }
    public static function setWaf(int $id, int $on): void {
        Database::query("UPDATE domains SET waf=? WHERE id=?", [$on, $id]);
    }
    public static function mailReportsOn(int $id): bool {
        $v = Database::query("SELECT mail_reports FROM domains WHERE id=?", [$id])->fetch()['mail_reports'] ?? 0;
        return (int)$v === 1;
    }
    public static function setMailReports(int $id, int $on): void {
        Database::query("UPDATE domains SET mail_reports=? WHERE id=?", [$on, $id]);
    }
    public static function setMtaSts(int $id, string $mode, string $policyId): void {
        Database::query("UPDATE domains SET mta_sts_mode=?, mta_sts_id=? WHERE id=?", [$mode, $policyId, $id]);
    }
    public static function clearMtaSts(int $id): void {
        Database::query("UPDATE domains SET mta_sts_mode='off', mta_sts_id='', mta_sts_cached_until=NULL WHERE id=?", [$id]);
    }
    public static function setMtaStsCachedUntil(int $id, int $days): void {
        Database::query("UPDATE domains SET mta_sts_cached_until=UTC_TIMESTAMP() + INTERVAL ? DAY WHERE id=?", [$days, $id]);
    }
    /** the enforce-cache deadline, if still in the future */
    public static function mtaStsCachedUntil(int $id): ?string {
        $r = Database::query("SELECT mta_sts_cached_until FROM domains WHERE id=? AND mta_sts_cached_until > UTC_TIMESTAMP()", [$id])->fetch();
        return $r === false ? null : (string)$r['mta_sts_cached_until'];
    }
    public static function setMailHandling(int $id, string $mode): void {
        Database::query("UPDATE domains SET mail_handling=? WHERE id=?", [$mode, $id]);
    }
    public static function setRewriteRules(int $id, string $rules): void {
        Database::query("UPDATE domains SET rewrite_rules=?, rewrite_src_hash=NULL WHERE id=?", [$rules, $id]);
    }
    public static function setRewriteFromHtaccess(int $id, string $rules, string $srcHash): void {
        Database::query("UPDATE domains SET rewrite_rules=?, rewrite_src_hash=?, rewrite_imported_at=NOW() WHERE id=?", [$rules, $srcHash, $id]);
    }
    // ------------------------------------------------------------ api scope
    public static function rowsFor(int $uid): array {
        return Database::query(
            "SELECT id,domain,docroot,php_version,ssl_enabled,rewrite_rules,rewrite_src_hash FROM domains WHERE user_id=? ORDER BY domain",
            [$uid]
        )->fetchAll();
    }
    public static function insert(int $uid, string $domain, string $docroot, string $php): int {
        Database::query("INSERT INTO domains(user_id,domain,docroot,php_version) VALUES(?,?,?,?)", [$uid, $domain, $docroot, $php]);
        return (int)Database::conn()->lastInsertId();
    }
    public static function enableSsl(string $domain): void {
        Database::query("UPDATE domains SET ssl_enabled=1 WHERE domain=?", [$domain]);
    }
    public static function aliasesFor(int $domainId): array {
        return Database::query("SELECT alias FROM domain_aliases WHERE domain_id=?", [$domainId])->fetchAll();
    }
    public static function effectivePhp(int $uid): ?string {
        $v = Database::query("SELECT php_version FROM domains WHERE user_id=? LIMIT 1", [$uid])->fetch()['php_version'] ?? null;
        return is_string($v) ? $v : null;
    }
}
