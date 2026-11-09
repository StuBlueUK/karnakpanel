<?php
declare(strict_types=1);
/**
 * DnsModel — the dns_records + zone-flag queries behind DnsController
 * (records, clash rules, batch apply/rollback, imports/templates, DNSSEC and
 * secondary-nameserver flags, plus the reseller/panel nameserver lookups).
 *
 * SQL is verbatim from the controller; dynamic fragments only ever take
 * int-clamped id lists.
 */
class DnsModel {

    // ------------------------------------------------------------ nameservers
    public static function ownerOf(int $uid): ?int {
        $v = Database::query("SELECT owner_id FROM users WHERE id=?", [$uid])->fetch()['owner_id'] ?? null;
        return $v === null ? null : (int)$v;
    }
    public static function resellerNs(int $uid): ?array {
        return Database::query("SELECT ns1,ns2 FROM users WHERE id=? AND role='reseller'", [$uid])->fetch() ?: null;
    }
    public static function brandNsRows(): array {
        return Database::query("SELECT ns1,ns2 FROM users WHERE role='reseller' AND ns1 IS NOT NULL AND ns1<>''")->fetchAll();
    }
    public static function isLegacyNs(int $domainId): bool {
        return (int)Database::query(
            "SELECT COUNT(DISTINCT host) c FROM dns_records WHERE domain_id=? AND type='A' AND host IN ('ns1','ns2')", [$domainId]
        )->fetch()['c'] === 2;
    }
    /** rows of the per-domain legacy pair (the two WHEREs below must match) */
    public static function legacyNsRows(int $domainId, array $pair): array {
        return Database::query(
            "SELECT * FROM dns_records WHERE domain_id=? AND ((type='A' AND host IN ('ns1','ns2')) OR (type='NS' AND host='@' AND LOWER(value) IN (?,?,?,?)))",
            array_merge([$domainId], array_values($pair))
        )->fetchAll();
    }
    public static function deleteLegacyNsRows(int $domainId, array $pair): void {
        Database::query(
            "DELETE FROM dns_records WHERE domain_id=? AND ((type='A' AND host IN ('ns1','ns2')) OR (type='NS' AND host='@' AND LOWER(value) IN (?,?,?,?)))",
            array_merge([$domainId], array_values($pair))
        );
    }

    // ---------------------------------------------------------------- records
    /** @param list<int> $ids */
    public static function countsByDomain(array $ids): array {
        $ids = array_values(array_map('intval', $ids));
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach (Database::query("SELECT domain_id, COUNT(*) c FROM dns_records WHERE domain_id IN ($in) GROUP BY domain_id", $ids)->fetchAll() as $r) {
            $out[(int)$r['domain_id']] = (int)$r['c'];
        }
        return $out;
    }
    public static function count(int $domainId, ?string $like = null): int {
        if ($like === null) {
            return (int)Database::query("SELECT COUNT(*) c FROM dns_records WHERE domain_id=?", [$domainId])->fetch()['c'];
        }
        return (int)Database::query(
            "SELECT COUNT(*) c FROM dns_records WHERE domain_id=? AND (host LIKE ? OR value LIKE ?)", [$domainId, $like, $like]
        )->fetch()['c'];
    }
    public static function page(int $domainId, ?string $like, int $limit, int $offset): array {
        if ($like === null) {
            return Database::query(
                "SELECT * FROM dns_records WHERE domain_id=? ORDER BY host, type LIMIT ? OFFSET ?", [$domainId, $limit, $offset]
            )->fetchAll();
        }
        return Database::query(
            "SELECT * FROM dns_records WHERE domain_id=? AND (host LIKE ? OR value LIKE ?) ORDER BY host, type LIMIT ? OFFSET ?",
            [$domainId, $like, $like, $limit, $offset]
        )->fetchAll();
    }
    public static function recordsFor(int $domainId): array {
        return Database::query("SELECT * FROM dns_records WHERE domain_id=? ORDER BY host, type", [$domainId])->fetchAll();
    }
    public static function record(int $id): ?array {
        return Database::query("SELECT * FROM dns_records WHERE id=?", [$id])->fetch() ?: null;
    }
    public static function recordWithDomain(int $id): ?array {
        return Database::query(
            "SELECT r.*, d.domain, d.user_id FROM dns_records r JOIN domains d ON d.id=r.domain_id WHERE r.id=?", [$id]
        )->fetch() ?: null;
    }
    public static function clashCount(int $domainId, string $host, string $type, ?int $exceptId = null): int {
        if ($exceptId === null) {
            return (int)Database::query(
                "SELECT COUNT(*) c FROM dns_records WHERE domain_id=? AND host=? AND ((type='CNAME') OR (?='CNAME'))",
                [$domainId, $host, $type]
            )->fetch()['c'];
        }
        return (int)Database::query(
            "SELECT COUNT(*) c FROM dns_records WHERE domain_id=? AND host=? AND id<>? AND ((type='CNAME') OR (?='CNAME'))",
            [$domainId, $host, $exceptId, $type]
        )->fetch()['c'];
    }
    public static function insert(int $domainId, string $type, string $host, string $value, $prio, $ttl): int {
        Database::query(
            "INSERT INTO dns_records(domain_id,type,host,value,priority,ttl) VALUES(?,?,?,?,?,?)",
            [$domainId, $type, $host, $value, (int)$prio, (int)$ttl]
        );
        return (int)Database::conn()->lastInsertId();
    }
    public static function update(int $id, string $type, string $host, string $value, $prio, $ttl): void {
        Database::query(
            "UPDATE dns_records SET type=?,host=?,value=?,priority=?,ttl=? WHERE id=?",
            [$type, $host, $value, (int)$prio, (int)$ttl, $id]
        );
    }
    public static function deleteOwned(int $id, int $domainId): void {
        Database::query("DELETE FROM dns_records WHERE id=? AND domain_id=?", [$id, $domainId]);
    }
    public static function deleteHost(int $domainId, string $host): void {
        Database::query("DELETE FROM dns_records WHERE domain_id=? AND host=? AND type='A'", [$domainId, $host]);
    }
    public static function delete(int $id): void {
        Database::query("DELETE FROM dns_records WHERE id=?", [$id]);
    }
    public static function deleteFor(int $domainId): void {
        Database::query("DELETE FROM dns_records WHERE domain_id=?", [$domainId]);
    }
    /** restore a row exactly as it was (explicit id), for rollbacks */
    public static function restore(array $o): void {
        Database::query(
            "INSERT INTO dns_records(id,domain_id,type,host,value,priority,ttl) VALUES(?,?,?,?,?,?,?)",
            [$o['id'], $o['domain_id'], $o['type'], $o['host'], $o['value'], $o['priority'], $o['ttl']]
        );
    }
    /** first TXT whose value carries $marker, at an exact (already mapped) host */
    public static function txtRow(int $domainId, string $host, string $marker): ?array {
        return Database::query(
            "SELECT * FROM dns_records WHERE domain_id=? AND type='TXT' AND host=? AND value LIKE ? ORDER BY id LIMIT 1",
            [$domainId, $host, '%'.$marker.'%']
        )->fetch() ?: null;
    }
    public static function setValue(int $id, string $value): void {
        Database::query("UPDATE dns_records SET value=? WHERE id=?", [$value, $id]);
    }
    public static function insertTxt(int $domainId, string $host, string $value): void {
        Database::query(
            "INSERT INTO dns_records(domain_id,type,host,value,priority,ttl) VALUES(?,'TXT',?,?,10,3600)",
            [$domainId, $host, $value]
        );
    }
    public static function hostTaken(int $domainId, string $host): bool {
        return (bool)Database::query(
            "SELECT 1 FROM dns_records WHERE domain_id=? AND host=? LIMIT 1", [$domainId, $host]
        )->fetch();
    }
    public static function recordsAtHost(int $domainId, string $host): array {
        return Database::query(
            "SELECT type, value FROM dns_records WHERE domain_id=? AND host=?", [$domainId, $host]
        )->fetchAll();
    }
    public static function mxValues(int $domainId, string $host): array {
        return Database::query(
            "SELECT value FROM dns_records WHERE domain_id=? AND type='MX' AND host=? ORDER BY priority", [$domainId, $host]
        )->fetchAll();
    }
    public static function hasPreviewWildcard(int $domainId): bool {
        return (bool)Database::query(
            "SELECT 1 FROM dns_records WHERE domain_id=? AND type='A' AND host='*.preview' LIMIT 1", [$domainId]
        )->fetch();
    }
    public static function ownedRecord(int $id, int $domainId): ?array {
        return Database::query("SELECT * FROM dns_records WHERE id=? AND domain_id=?", [$id, $domainId])->fetch() ?: null;
    }

    // ------------------------------------------------------ dedupe / templates
    public static function keysFor(int $domainId): array {
        return Database::query("SELECT type,host,value FROM dns_records WHERE domain_id=?", [$domainId])->fetchAll();
    }
    /** @param list<int> $exceptIds rows being replaced, so they don't count as clashes */
    public static function keysForExcept(int $domainId, array $exceptIds): array {
        $ids = array_values(array_map('intval', $exceptIds));
        if (!$ids) return self::keysFor($domainId);
        $keep = ' AND id NOT IN ('.implode(',', $ids).')';
        return Database::query("SELECT type,host,value FROM dns_records WHERE domain_id=?$keep", [$domainId])->fetchAll();
    }
    public static function txtIdsStarting(int $domainId, string $host, string $prefix): array {
        return array_map(
            fn($r) => (int)$r['id'],
            Database::query(
                "SELECT id FROM dns_records WHERE domain_id=? AND type='TXT' AND host=? AND LOWER(value) LIKE ?",
                [$domainId, $host, "$prefix%"]
            )->fetchAll()
        );
    }
    public static function mailTemplateDropIds(int $domainId, string $at, string $autodiscoverHost): array {
        return array_map(
            fn($r) => (int)$r['id'],
            Database::query(
                "SELECT id FROM dns_records WHERE domain_id=? AND ((host=? AND (type='MX' OR (type='TXT' AND LOWER(value) LIKE 'v=spf1%'))) OR (type='CNAME' AND host=? AND LOWER(TRIM(TRAILING '.' FROM value))='autodiscover.outlook.com'))",
                [$domainId, $at, $autodiscoverHost]
            )->fetchAll()
        );
    }
    public static function idsByTypeHost(int $domainId, string $type, string $host): array {
        return array_map(
            fn($r) => (int)$r['id'],
            Database::query("SELECT id FROM dns_records WHERE domain_id=? AND type=? AND host=?", [$domainId, $type, $host])->fetchAll()
        );
    }

    // ---------------------------------------------------------------- domains
    public static function domainById(int $id): ?array {
        return DomainModel::find($id);
    }
    public static function domainByName(string $domain): ?array {
        return DomainModel::findByName($domain);
    }
    public static function domainOwner(int $id): ?int {
        return DomainModel::ownerId($id);
    }
    public static function setSecondaryNs(int $domainId, string $csv): void {
        Database::query("UPDATE domains SET secondary_ns=? WHERE id=?", [$csv, $domainId]);
    }
    public static function setDnssec(int $domainId, int $on): void {
        Database::query("UPDATE domains SET dnssec=? WHERE id=?", [$on, $domainId]);
    }
}
