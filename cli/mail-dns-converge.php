#!/usr/bin/env php
<?php
// mail-dns-converge.php [--dry-run] — run as root by scripts/mail-dns-converge.sh
// (host-converge steps 16 + 34). Backfills for mail DNS, see app/Core/MailDns.php:
//   - calendar/contacts auto-discovery SRV+TXT (_caldavs/_carddavs, ensureDav)
//     once the DAV server is up with a valid certificate;
//   - a subdomain's mail-policy rows that MailPolicyController wrote under
//     the subdomain's own id before 1.5.38 (rendered by no zone) move into
//     the parent zone at the prefixed name; only those shapes, a conflicting
//     record already in the zone wins (MailDns::adopt);
//   - a domain with a DKIM signing key whose zone is hosted here but carries
//     no DKIM TXT at that name gets it published (signatures failed until
//     then). A different key already published is reported, never replaced.
//   - SPF TXT rows still holding a panel default shape ("v=spf1 a mx ~all",
//     optionally with ip6: terms) move to the current default, which lists
//     this box's IPv6 (MailDns::spfDefault) — IPv6 deliveries soft-failed SPF.
//     Any other SPF (hand-edited, includes, -all) is never touched.
// Quiet when there is nothing to do; prints one line per change.
declare(strict_types=1);
$root = dirname(__DIR__);
spl_autoload_register(function ($c) use ($root) {
    if (!preg_match('/^[A-Za-z]+$/', $c)) return;
    foreach (['Core', 'Controllers', 'Models'] as $dir) {
        $f = "$root/app/$dir/$c.php";
        if (file_exists($f)) { require_once $f; return; }
    }
});
Config::load($root.'/config/config.php');
$_SESSION = [];
$dry = in_array('--dry-run', $argv, true);
$rc = 0;
foreach (Database::query("SELECT * FROM domains ORDER BY id")->fetchAll() as $d) {
    try {
        $t = MailDns::target($d);
        foreach (MailDns::adopt($d, $dry) as $line) echo "mail-dns: $line\n";
        if (!$t['zone'] || !$t['hosted']) continue;
        // calendar/contacts auto-discovery SRV+TXT (MailDns::ensureDav; no-op until DAV is up)
        if (MailDns::ensureDav($d, $dry)) echo "mail-dns: {$d['domain']}: ".($dry ? 'would add' : 'added')." calendar discovery SRV/TXT in {$t['origin']}\n";
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- domain from the DB, sanitized to [a-z0-9.-] under a fixed dir; existence check only
        if (!is_file('/etc/exim4/dkim/'.preg_replace('/[^a-z0-9.-]/', '', strtolower((string)$d['domain'])).'.default.pem')) continue;
        $val = MailDns::dkimValue((string)$d['domain']);
        if ($val === '') continue;
        if ($dry) {
            $h = MailDns::host($t, 'default._domainkey');
            $have = Database::query("SELECT 1 FROM dns_records WHERE domain_id=? AND host=? AND (type='CNAME' OR (type='TXT' AND value LIKE '%v=DKIM1%')) LIMIT 1", [$t['zone']['id'], $h])->fetch();
            if (!$have) echo "mail-dns: {$d['domain']}: would publish DKIM TXT at $h in {$t['origin']}\n";
            continue;
        }
        $r = MailDns::publishDkim($d, $val);
        if ($r === 'published') echo "mail-dns: {$d['domain']}: DKIM TXT published in {$t['origin']}\n";
        elseif ($r === 'differs') echo "mail-dns: {$d['domain']}: a different DKIM key is published — signatures fail until it is replaced (Email page)\n";
        elseif (str_starts_with($r, 'failed')) { echo "mail-dns: {$d['domain']}: DKIM publish $r\n"; $rc = 1; }
    } catch (Throwable $e) { echo "mail-dns: {$d['domain']}: ".$e->getMessage()."\n"; $rc = 1; }
}
// SPF default backfill (see header); skipped when spf-default.sh failed
$spf = MailDns::spfDefault(true);
if ($spf !== '') {
    $zones = [];
    $isZone = [];
    foreach (Database::query("SELECT r.id, r.domain_id, r.value, d.domain FROM dns_records r JOIN domains d ON d.id=r.domain_id WHERE r.type='TXT' AND r.value LIKE 'v=spf1 a mx %'")->fetchAll() as $r) {
        $v = trim((string)$r['value'], " \t\"");
        if ($v === $spf || preg_match(MailDns::SPF_PANEL_RE, $v) !== 1) continue;
        // never strip ip6 automatically: a box briefly without global IPv6
        // (DAD, a network restart) would undo the fix on every zone
        if (!str_contains($spf, ' ip6:') && str_contains($v, ' ip6:')) continue;
        // only rows of a zone rendered HERE (orphan rows under a subdomain's
        // own id are rendered by nothing; apply() on them would mint a zone)
        $zid = (int)$r['domain_id'];
        if (!isset($isZone[$zid])) {
            $dr = Database::query("SELECT * FROM domains WHERE id=?", [$zid])->fetch();
            $t = $dr ? MailDns::target($dr) : null;
            $isZone[$zid] = $t && $t['zone'] && $t['hosted'] && (int)$t['zone']['id'] === $zid;
        }
        if (!$isZone[$zid]) continue;
        $zones[(int)$r['domain_id']]['domain'] = (string)$r['domain'];
        $zones[(int)$r['domain_id']]['ids'][] = (int)$r['id'];
    }
    foreach ($zones as $zid => $z) {
        $n = count($z['ids']);
        if ($dry) { echo "mail-dns: {$z['domain']}: would set $n default SPF row(s) to \"$spf\"\n"; continue; }
        try {
            $ph = implode(',', array_fill(0, $n, '?'));
            Database::query("UPDATE dns_records SET value=? WHERE id IN ($ph)", array_merge([$spf], $z['ids']));
            $r = (new DnsController())->apply($zid, $z['domain']);
            if ((int)($r['code'] ?? 1) === 0) echo "mail-dns: {$z['domain']}: default SPF now \"$spf\" ($n row(s))\n";
            else { echo "mail-dns: {$z['domain']}: SPF rows updated, zone apply failed: ".trim((string)($r['out'] ?? ''))."\n"; $rc = 1; }
        } catch (Throwable $e) { echo "mail-dns: {$z['domain']}: SPF backfill: ".$e->getMessage()."\n"; $rc = 1; }
    }
}
exit($rc);
