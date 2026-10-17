#!/usr/bin/env php
<?php
// mail-dns-converge.php [--dry-run] — run as root by scripts/mail-dns-converge.sh
// (host-converge step 16). Two backfills for mail DNS, see app/Core/MailDns.php:
//   - a subdomain's mail-policy rows that MailPolicyController wrote under
//     the subdomain's own id before 1.5.38 (rendered by no zone) move into
//     the parent zone at the prefixed name; only those shapes, a conflicting
//     record already in the zone wins (MailDns::adopt);
//   - a domain with a DKIM signing key whose zone is hosted here but carries
//     no DKIM TXT at that name gets it published (signatures failed until
//     then). A different key already published is reported, never replaced.
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
// DnsController::apply() stages /tmp/skypanel-<zone>.zone and, when that is
// held by a running panel save, flashes + REDIRECTS (exit): skip such a zone
$busy = fn(array $t) => $t['zone'] && file_exists('/tmp/skypanel-'.$t['zone']['domain'].'.zone');
foreach (Database::query("SELECT * FROM domains ORDER BY id")->fetchAll() as $d) {
    try {
        $t = MailDns::target($d);
        if ($busy($t)) { echo "mail-dns: {$d['domain']}: {$t['origin']} zone save in progress, skipped\n"; continue; }
        foreach (MailDns::adopt($d, $dry) as $line) echo "mail-dns: $line\n";
        if (!$t['zone'] || !$t['hosted']) continue;
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
exit($rc);
