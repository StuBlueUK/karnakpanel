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
        foreach (['domain_aliases','preview_tokens','email_forwards','email_filters','mail_scores','site_installs'] as $t) {
            Database::query("DELETE FROM $t WHERE domain_id=?", [$domainId]);
        }
        $ll = Database::query("SELECT id FROM email_lists WHERE domain_id=?", [$domainId])->fetchAll();
        foreach ($ll as $l) Database::query("DELETE FROM email_list_members WHERE list_id=?", [$l['id']]);
        Database::query("DELETE FROM email_lists WHERE domain_id=?", [$domainId]);
    }
}
