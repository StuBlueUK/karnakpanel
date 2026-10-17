<?php
declare(strict_types=1);
// One creation path for domains + subdomains: the Domains page, the
// Subdomains tab and the importers (HestiaImport) all land here, so default
// DNS rows, PHP inheritance and site setup can't drift between them.
// Callers own validation, ownership checks and package limits.
class DomainProvisioner {
    // pools are per-USER: a new domain inherits the account's effective
    // version (first sibling row), otherwise the pool would silently move
    // under the owner's other domains (Oct 2026 forum outage, twice).
    // Deliberate moves stay on the PHP page (php-switch.sh).
    public static function effectivePhp(int $userId, string $want): string {
        $php = PhpVersions::isSelectable($want) ? $want : PhpVersions::DEFAULT;
        $eff = Database::query("SELECT php_version FROM domains WHERE user_id=? LIMIT 1", [$userId])->fetch()['php_version'] ?? null;
        if (is_string($eff) && PhpVersions::valid($eff)) $php = $eff;
        return $php;
    }
    public static function serverIp(): string {
        $ip = trim(ServiceManager::run('server-ip.sh', [])['out'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $ip : '192.0.2.1';
    }
    // top-level domain: row + default records + zone + vhost.
    // Returns [domainId, dns-add-zone result, vhost-add result].
    public static function createTop(array $owner, string $domain, string $php): array {
        $docroot = "/home/{$owner['username']}/".$domain."/public_html";
        Database::query("INSERT INTO domains(user_id,domain,docroot,php_version) VALUES(?,?,?,?)", [$owner['id'],$domain,$docroot,$php]);
        $domId = (int)Database::conn()->lastInsertId();
        // seed default records IN THE DB (not just the zone file template):
        // any panel-side DNS save re-renders from these rows — file-only
        // defaults silently vanish on first edit (took down an apex A live)
        $ip = self::serverIp();
        [$ns1, $ns2] = DnsController::effectiveNs((int)$owner['id'], $domain);
        foreach (DnsController::defaultRows($domain, $ip, [$ns1, $ns2]) as $rec) {
            Database::query("INSERT INTO dns_records(domain_id,type,host,value,priority,ttl) VALUES(?,?,?,?,?,?)", [$domId,$rec[0],$rec[1],$rec[2],10,3600]);
        }
        // self-healing: owner may lack a system account (e.g. admin) — create-account is idempotent
        ServiceManager::run('create-account.sh', ['--repair', $owner['username'], $php]);
        // re-resolve with the seeded rows: a reseller brand zone (glue seeded
        // above) advertises its own ns1/ns2, and the file must agree
        [$ns1, $ns2] = DnsController::effectiveNs((int)$owner['id'], $domain, $domId);
        $r = ServiceManager::run('dns-add-zone.sh', [$domain, $ns1, $ns2]);
        $r2 = ServiceManager::run('vhost-add.sh', [$domain, $owner['username'], $php]);
        // mail client autoconfig (Thunderbird/Outlook discover from the
        // docroot — no DNS/certs needed). Best-effort: never fail the add.
        ServiceManager::run('mail-autoconfig.sh', [$domain, $owner['username']]);
        // seed expiry dates now so the column isn't blank until tonight's run
        ServiceManager::run('expiry-check.sh', [$domain]);
        return [$domId, $r, $r2];
    }
    // subdomain: row + ONE A record in the parent zone (never a zone of its
    // own) + site. Throws on site-setup failure (row stays, like the tab did).
    // Returns [domainId, ip, subdomain-add output].
    public static function createSub(array $owner, array $parent, string $label, string $php): array {
        $full = $label.'.'.$parent['domain'];
        $docroot = "/home/{$owner['username']}/$full/public_html";
        Database::query("INSERT INTO domains(user_id,domain,docroot,php_version) VALUES(?,?,?,?)", [$owner['id'],$full,$docroot,$php]);
        $domId = (int)Database::conn()->lastInsertId();
        $ip = self::serverIp();
        Database::query("INSERT INTO dns_records(domain_id,type,host,value,priority,ttl) VALUES(?,?,?,?,?,?)", [$parent['id'],'A',$label,$ip,10,3600]);
        (new DnsController())->apply((int)$parent['id'], $parent['domain']);
        $r = ServiceManager::run('subdomain-add.sh', [$full, $owner['username'], $php]);
        if (($r['code'] ?? 1) !== 0) throw new RuntimeException(trim($r['out'] ?? 'site setup failed'));
        return [$domId, $ip, trim($r['out'] ?? '')];
    }
}
