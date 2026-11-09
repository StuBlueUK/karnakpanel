<?php
declare(strict_types=1);
/**
 * EmailModel — every query the Email page needs (mailboxes, forwarders,
 * filters, lists, autoresponders, scores, imports, mail settings).
 *
 * One home for the SQL so EmailController reads as parse → authorise → call →
 * render (docs/architecture.md §2). Queries are the ones the controller used
 * to run inline, verbatim; `array $u` is the Auth::user() row and only its
 * `role`/`id` are used to widen or narrow scope.
 */
class EmailModel {

    // ------------------------------------------------------------- mailboxes
    private static function where(int $uid, int $domainId, ?string $like): array {
        $where = "e.user_id=?"; $args = [$uid];
        if ($domainId > 0) { $where .= " AND e.domain_id=?"; $args[] = $domainId; }
        if ($like !== null) { $where .= " AND (e.localpart LIKE ? OR d.domain LIKE ?)"; $args[] = $like; $args[] = $like; }
        return [$where, $args];
    }
    public static function count(int $uid, int $domainId, ?string $like): int {
        [$where, $args] = self::where($uid, $domainId, $like);
        return (int)Database::query(
            "SELECT COUNT(*) c FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE $where", $args
        )->fetch()['c'];
    }
    public static function page(int $uid, int $domainId, ?string $like, int $limit, int $offset): array {
        [$where, $args] = self::where($uid, $domainId, $like);
        return Database::query(
            "SELECT e.*, d.domain FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE $where ORDER BY d.domain, e.localpart LIMIT ? OFFSET ?",
            array_merge($args, [$limit, $offset])
        )->fetchAll();
    }
    public static function boxes(int $uid): array {
        return Database::query(
            "SELECT e.id, e.localpart, d.domain FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE e.user_id=? ORDER BY d.domain, e.localpart",
            [$uid]
        )->fetchAll();
    }
    /** role-aware single mailbox (admin: any, user: own) */
    public static function box(array $u, int $id): ?array {
        $sql = ($u['role'] === 'admin')
            ? "SELECT e.*, d.domain FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE e.id=?"
            : "SELECT e.*, d.domain FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE e.id=? AND e.user_id=?";
        $args = ($u['role'] === 'admin') ? [$id] : [$id, $u['id']];
        return Database::query($sql, $args)->fetch() ?: null;
    }
    /** owner-only lookup (admins included: the row must be theirs) */
    public static function boxOwned(int $uid, int $id): ?array {
        return Database::query(
            "SELECT * FROM email_accounts WHERE id=? AND user_id=?", [$id, $uid]
        )->fetch() ?: null;
    }
    public static function mailboxExists(int $domainId, string $local): bool {
        return (bool)Database::query(
            "SELECT 1 FROM email_accounts WHERE domain_id=? AND localpart=?", [$domainId, $local]
        )->fetch();
    }
    public static function createMailbox(int $uid, int $domainId, string $local, string $hash, int $quota): void {
        Database::query(
            "INSERT INTO email_accounts(user_id,domain_id,localpart,password_hash,quota_mb) VALUES(?,?,?,?,?)",
            [$uid, $domainId, $local, $hash, $quota]
        );
    }
    public static function deleteMailbox(int $id): void {
        Database::query("DELETE FROM email_accounts WHERE id=?", [$id]);
    }
    public static function setPasswordHash(int $id, string $hash): void {
        Database::query("UPDATE email_accounts SET password_hash=? WHERE id=?", [$hash, $id]);
    }
    public static function releaseHold(int $id): void {
        Database::query("UPDATE email_accounts SET send_hold_at=NULL, send_hold_reason='', send_released_at=NOW() WHERE id=?", [$id]);
    }
    public static function setAutoresponder(int $id, string $subject, string $text, ?string $start, ?string $end): void {
        Database::query(
            "UPDATE email_accounts SET autoresp_enabled=1, autoresp_subject=?, autoresp_text=?, autoresp_start=?, autoresp_end=? WHERE id=?",
            [$subject, $text, $start, $end, $id]
        );
    }
    public static function clearAutoresponder(int $id): void {
        Database::query("UPDATE email_accounts SET autoresp_enabled=0 WHERE id=?", [$id]);
    }
    public static function setSpamThreshold(int $id, float $threshold): void {
        Database::query("UPDATE email_accounts SET spam_threshold=? WHERE id=?", [$threshold, $id]);
    }
    public static function setCleanDays(int $id, int $days): void {
        Database::query("UPDATE email_accounts SET clean_days=? WHERE id=?", [$days, $id]);
    }
    public static function setSmtpLimit(int $id, ?int $limit): void {
        Database::query("UPDATE email_accounts SET smtp_limit=? WHERE id=?", [$limit, $id]);
    }
    public static function setQuota(int $id, int $mb): void {
        Database::query("UPDATE email_accounts SET quota_mb=? WHERE id=?", [$mb, $id]);
    }
    public static function responders(int $uid): array {
        return Database::query(
            "SELECT e.id, e.localpart, e.autoresp_subject, e.autoresp_text, e.autoresp_start, e.autoresp_end, d.domain
             FROM email_accounts e JOIN domains d ON d.id=e.domain_id
             WHERE e.user_id=? AND e.autoresp_enabled=1 ORDER BY d.domain, e.localpart", [$uid]
        )->fetchAll();
    }
    public static function held(int $limit = 50): array {
        $limit = max(1, min(200, $limit));
        return Database::query(
            "SELECT e.id, e.localpart, e.send_hold_at, e.send_hold_reason, d.domain, u.username
             FROM email_accounts e JOIN domains d ON d.id=e.domain_id JOIN users u ON u.id=e.user_id
             WHERE e.send_hold_at IS NOT NULL ORDER BY e.send_hold_at DESC LIMIT " . $limit
        )->fetchAll();
    }
    public static function heldBox(int $id): ?array {
        return Database::query(
            "SELECT e.id, e.localpart, e.send_hold_at, d.domain
             FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE e.id=?", [$id]
        )->fetch() ?: null;
    }

    /** owner-scoped mailbox with its domain (the API's flavour of box()) */
    public static function mailboxForOwner(int $uid, int $id): ?array {
        return Database::query(
            "SELECT e.*, d.domain FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE e.id=? AND e.user_id=?",
            [$id, $uid]
        )->fetch() ?: null;
    }
    /** mail listing for one domain (API) */
    public static function mailboxesForDomain(int $domainId): array {
        return Database::query(
            "SELECT id,localpart,quota_mb FROM email_accounts WHERE domain_id=? ORDER BY localpart", [$domainId]
        )->fetchAll();
    }
    public static function forwardsForDomain(int $domainId): array {
        return Database::query(
            "SELECT id,localpart,targets FROM email_forwards WHERE domain_id=? ORDER BY localpart", [$domainId]
        )->fetchAll();
    }
    /** mail listing across every domain of one account (API) */
    public static function mailboxesForUser(int $uid): array {
        return Database::query(
            "SELECT e.id,e.localpart,e.quota_mb,d.domain FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE e.user_id=? ORDER BY d.domain,e.localpart",
            [$uid]
        )->fetchAll();
    }
    public static function forwardsForUser(int $uid): array {
        return Database::query(
            "SELECT f.id,f.localpart,f.targets,d.domain FROM email_forwards f JOIN domains d ON d.id=f.domain_id WHERE f.user_id=? ORDER BY d.domain,f.localpart",
            [$uid]
        )->fetchAll();
    }
    /** every mailbox of an account with its domain (backup/restore pickers) */
    public static function allForUser(int $uid): array {
        return Database::query(
            "SELECT e.*, d.domain FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE e.user_id=?", [$uid]
        )->fetchAll();
    }
    public static function countForDomain(int $domainId): int {
        return (int)Database::query("SELECT COUNT(*) c FROM email_accounts WHERE domain_id=?", [$domainId])->fetch()['c'];
    }
    /** removing a domain takes its mailboxes with it (rows only; the caller runs the scripts) */
    public static function deleteMailboxesForDomain(int $domainId): void {
        Database::query("DELETE FROM email_accounts WHERE domain_id=?", [$domainId]);
    }

    // ------------------------------------------------------------- forwarders
    public static function forwards(int $uid): array {
        return Database::query(
            "SELECT f.*, d.domain FROM email_forwards f JOIN domains d ON d.id=f.domain_id WHERE f.user_id=?", [$uid]
        )->fetchAll();
    }
    public static function forward(int $id, int $uid): ?array {
        return Database::query(
            "SELECT f.*, d.domain FROM email_forwards f JOIN domains d ON d.id=f.domain_id WHERE f.id=? AND f.user_id=?",
            [$id, $uid]
        )->fetch() ?: null;
    }
    public static function forwardTargetsAt(int $domainId, string $local): ?string {
        $r = Database::query("SELECT targets FROM email_forwards WHERE domain_id=? AND localpart=?", [$domainId, $local])->fetch();
        return $r === false ? null : (string)$r['targets'];
    }
    public static function forwardAt(int $domainId, string $local): ?array {
        return Database::query(
            "SELECT id, targets FROM email_forwards WHERE domain_id=? AND localpart=?", [$domainId, $local]
        )->fetch() ?: null;
    }
    public static function upsertForward(int $uid, int $domainId, string $local, string $targets): void {
        Database::query(
            "INSERT INTO email_forwards(user_id,domain_id,localpart,targets) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE targets=VALUES(targets)",
            [$uid, $domainId, $local, $targets]
        );
    }
    public static function setForwardTargets(int $id, string $targets): void {
        Database::query("UPDATE email_forwards SET targets=? WHERE id=?", [$targets, $id]);
    }
    public static function restoreForwardTargets(int $domainId, string $local, string $targets): void {
        Database::query("UPDATE email_forwards SET targets=? WHERE domain_id=? AND localpart=?", [$targets, $domainId, $local]);
    }
    public static function deleteForwardAt(int $domainId, string $local): void {
        Database::query("DELETE FROM email_forwards WHERE domain_id=? AND localpart=?", [$domainId, $local]);
    }
    public static function deleteForward(int $id): void {
        Database::query("DELETE FROM email_forwards WHERE id=?", [$id]);
    }

    // ---------------------------------------------------------------- filters
    public static function filters(int $uid): array {
        return Database::query(
            "SELECT f.*, d.domain FROM email_filters f JOIN domains d ON d.id=f.domain_id WHERE f.user_id=? ORDER BY d.domain, f.localpart, f.id",
            [$uid]
        )->fetchAll();
    }
    public static function filter(int $id): ?array {
        return Database::query("SELECT * FROM email_filters WHERE id=?", [$id])->fetch() ?: null;
    }
    public static function filterWithDomain(int $id): ?array {
        return Database::query(
            "SELECT f.*, d.domain FROM email_filters f JOIN domains d ON d.id=f.domain_id WHERE f.id=?", [$id]
        )->fetch() ?: null;
    }
    public static function insertFilter(int $uid, int $domainId, string $local, string $field, string $mtype, string $match, string $action, string $target): void {
        Database::query(
            "INSERT INTO email_filters(user_id,domain_id,localpart,field,match_type,match_text,action,target) VALUES(?,?,?,?,?,?,?,?)",
            [$uid, $domainId, $local, $field, $mtype, $match, $action, $target]
        );
    }
    public static function updateFilter(int $id, int $domainId, string $local, string $field, string $mtype, string $match, string $action, string $target): void {
        Database::query(
            "UPDATE email_filters SET domain_id=?, localpart=?, field=?, match_type=?, match_text=?, action=?, target=? WHERE id=?",
            [$domainId, $local, $field, $mtype, $match, $action, $target, $id]
        );
    }
    public static function setFilterActive(int $id, int $on): void {
        Database::query("UPDATE email_filters SET active=? WHERE id=?", [$on, $id]);
    }
    public static function deleteFilter(int $id): void {
        Database::query("DELETE FROM email_filters WHERE id=?", [$id]);
    }
    public static function deleteFiltersAt(int $domainId, string $local): void {
        Database::query("DELETE FROM email_filters WHERE domain_id=? AND localpart=?", [$domainId, $local]);
    }

    // ------------------------------------------------------------------ lists
    public static function lists(int $uid): array {
        return Database::query(
            "SELECT l.*, d.domain FROM email_lists l JOIN domains d ON d.id=l.domain_id WHERE l.user_id=? ORDER BY d.domain, l.localpart",
            [$uid]
        )->fetchAll();
    }
    public static function listMembers(int $listId): array {
        return Database::query("SELECT * FROM email_list_members WHERE list_id=? ORDER BY address", [$listId])->fetchAll();
    }
    public static function listRow(array $u, int $id): ?array {
        $sql = ($u['role'] === 'admin')
            ? "SELECT l.*, d.domain FROM email_lists l JOIN domains d ON d.id=l.domain_id WHERE l.id=?"
            : "SELECT l.*, d.domain FROM email_lists l JOIN domains d ON d.id=l.domain_id WHERE l.id=? AND l.user_id=?";
        $args = ($u['role'] === 'admin') ? [$id] : [$id, $u['id']];
        return Database::query($sql, $args)->fetch() ?: null;
    }
    public static function listsAt(int $domainId, string $local): array {
        return Database::query("SELECT id FROM email_lists WHERE domain_id=? AND localpart=?", [$domainId, $local])->fetchAll();
    }
    public static function createList(int $uid, int $domainId, string $local): void {
        Database::query("INSERT INTO email_lists(user_id,domain_id,localpart) VALUES(?,?,?)", [$uid, $domainId, $local]);
    }
    public static function deleteList(int $id): void {
        Database::query("DELETE FROM email_lists WHERE id=?", [$id]);
    }
    public static function deleteListMembers(int $listId): void {
        Database::query("DELETE FROM email_list_members WHERE list_id=?", [$listId]);
    }
    public static function memberWithOwner(int $mid): ?array {
        return Database::query(
            "SELECT m.*, l.user_id FROM email_list_members m JOIN email_lists l ON l.id=m.list_id WHERE m.id=?", [$mid]
        )->fetch() ?: null;
    }
    public static function memberAddresses(int $listId): array {
        return Database::query("SELECT address FROM email_list_members WHERE list_id=?", [$listId])->fetchAll();
    }
    public static function addMember(int $listId, string $address): void {
        Database::query("INSERT IGNORE INTO email_list_members(list_id,address) VALUES(?,?)", [$listId, $address]);
    }
    public static function deleteMember(int $mid): void {
        Database::query("DELETE FROM email_list_members WHERE id=?", [$mid]);
    }
    public static function deleteMembersByAddress(string $address): void {
        Database::query("DELETE FROM email_list_members WHERE address=?", [$address]);
    }
    public static function listAddressExists(string $address): bool {
        return (bool)Database::query(
            "SELECT 1 FROM email_lists l JOIN domains d ON d.id=l.domain_id WHERE CONCAT(l.localpart,'@',d.domain)=? LIMIT 1",
            [$address]
        )->fetch();
    }
    /** mailbox/forwarder/alias/list all claim the same localpart on a domain */
    public static function addressTaken(int $domainId, string $local, string $address): bool {
        return (bool)Database::query(
            "SELECT 1 FROM email_accounts WHERE domain_id=? AND localpart=?
             UNION SELECT 1 FROM email_forwards WHERE domain_id=? AND localpart=?
             UNION SELECT 1 FROM domain_aliases WHERE alias=?
             UNION SELECT 1 FROM email_lists WHERE domain_id=? AND localpart=? LIMIT 1",
            [$domainId, $local, $domainId, $local, $address, $domainId, $local]
        )->fetch();
    }

    // -------------------------------------------------------------- settings
    public static function setting(string $name): ?string {
        return Settings::get($name);
    }
    /** @param list<string> $names */
    public static function settings(array $names): array {
        if (!$names) return [];
        $in = implode(',', array_fill(0, count($names), '?'));
        $out = [];
        foreach (Database::query("SELECT name, value FROM settings WHERE name IN ($in)", $names)->fetchAll() as $r) {
            $out[(string)$r['name']] = (string)$r['value'];
        }
        return $out;
    }
    public static function putSetting(string $name, string $value): void {
        Settings::put($name, $value);
    }
    public static function dropSetting(string $name): void {
        Settings::drop($name);
    }
    /** old installs may lack the table — callers retry after this */
    public static function createSettingsTable(): void {
        Settings::ensureTable();
    }

    // ------------------------------------------------------------------ users
    public static function phpMail(int $uid): ?array {
        return Database::query("SELECT php_mail, php_mail_off_reason FROM users WHERE id=?", [$uid])->fetch() ?: null;
    }
    public static function user(int $uid): ?array {
        return User::find($uid);
    }
    public static function diskQuotaMb(int $uid): int {
        return (int)(Database::query("SELECT disk_quota_mb FROM users WHERE id=?", [$uid])->fetch()['disk_quota_mb'] ?? 5120);
    }

    // ---------------------------------------------------------------- domains
    /** a domain the caller owns (admins included: the id must still be theirs) */
    public static function domain(int $domainId, int $uid): ?array {
        return Database::query("SELECT * FROM domains WHERE id=? AND user_id=?", [$domainId, $uid])->fetch() ?: null;
    }
    public static function scores(int $uid): array {
        return Database::query(
            "SELECT s.*, d.domain FROM mail_scores s JOIN domains d ON d.id=s.domain_id WHERE d.user_id=?", [$uid]
        )->fetchAll();
    }
    public static function saveScore(int $domainId, int $score, string $details): void {
        Database::query(
            "INSERT INTO mail_scores(domain_id,score,details) VALUES(?,?,?) ON DUPLICATE KEY UPDATE score=VALUES(score), details=VALUES(details), checked_at=NOW()",
            [$domainId, $score, $details]
        );
    }
    /** one TXT value (SPF/DMARC) from the zone that holds the domain */
    public static function zoneTxt(int $zoneId, string $host, string $needle): ?string {
        $r = Database::query(
            "SELECT value FROM dns_records WHERE domain_id=? AND host=? AND type='TXT' AND value LIKE ? LIMIT 1",
            [$zoneId, $host, $needle]
        )->fetch();
        return $r === false ? null : (string)$r['value'];
    }
    public static function policyRows(int $zoneId, string $aHost, string $txt1, string $txt2): array {
        return Database::query(
            "SELECT type, host, value FROM dns_records WHERE domain_id=? AND ((type='A' AND host=?) OR (type='TXT' AND host IN (?,?))) ORDER BY host",
            [$zoneId, $aHost, $txt1, $txt2]
        )->fetchAll();
    }

    // ---------------------------------------------------------------- imports
    public static function purgeOldImports(): void {
        Database::query("DELETE FROM mail_imports WHERE created_at < (NOW() - INTERVAL 30 DAY)");
    }
    public static function importsFor(int $domainId, string $local, int $limit = 10): array {
        $limit = max(1, min(50, $limit));
        return Database::query(
            "SELECT * FROM mail_imports WHERE domain_id=? AND localpart=? ORDER BY created_at DESC LIMIT " . $limit,
            [$domainId, $local]
        )->fetchAll();
    }
    public static function importCount(int $uid): int {
        return (int)Database::query(
            "SELECT COUNT(*) c FROM mail_imports WHERE user_id=? AND created_at > (NOW() - INTERVAL 1 HOUR)", [$uid]
        )->fetch()['c'];
    }
    public static function addImport(string $job, int $uid, int $domainId, string $local, string $host, int $port, string $remoteUser): void {
        Database::query(
            "INSERT INTO mail_imports(job,user_id,domain_id,localpart,host,port,remote_user,updated_at) VALUES(?,?,?,?,?,?,?,NOW())",
            [$job, $uid, $domainId, $local, $host, $port, $remoteUser]
        );
    }
    public static function failImport(string $job, string $detail): void {
        Database::query(
            "UPDATE mail_imports SET stage='failed', detail=?, updated_at=NOW() WHERE job=? AND stage='checking'",
            [mb_substr($detail, 0, 250), $job]
        );
    }
    public static function closeImport(string $job): void {
        Database::query(
            "UPDATE mail_imports SET stage='cancelled', updated_at=NOW() WHERE job=? AND stage IN ('checking','running') AND updated_at < (NOW() - INTERVAL 30 SECOND)",
            [$job]
        );
    }
    public static function importJob(string $job, int $domainId, string $local): ?array {
        return Database::query(
            "SELECT * FROM mail_imports WHERE job=? AND domain_id=? AND localpart=?", [$job, $domainId, $local]
        )->fetch() ?: null;
    }
    public static function deleteImportsAt(int $domainId, string $local): void {
        Database::query("DELETE FROM mail_imports WHERE domain_id=? AND localpart=?", [$domainId, $local]);
    }
}
