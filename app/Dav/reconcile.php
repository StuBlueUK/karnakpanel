<?php
// CLI (dav-setup.sh reconcile, daily cron): purge the calendars and contacts
// of every principal whose mailbox is gone (or was deleted and recreated —
// the binding is to email_accounts.id, not just the address). A recreated
// address is also purged at its first login (Store::bind); this sweep makes
// sure a deleted mailbox's data does not outlive it by more than a day.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/lib/Store.php';

use KarnakDav\Store;

$pdo = Store::pdo();
$rows = $pdo->query(
    "SELECT p.address FROM karnak_principals p
       LEFT JOIN skypanel.email_accounts e ON e.id = p.account_id
       LEFT JOIN skypanel.domains d ON d.id = e.domain_id
      WHERE e.id IS NULL OR CONCAT(e.localpart, '@', d.domain) COLLATE utf8mb4_bin <> p.address COLLATE utf8mb4_bin"
)->fetchAll(PDO::FETCH_COLUMN);
// data with no binding at all (should not happen; e.g. a crash mid-bind)
$stray = $pdo->query(
    "SELECT DISTINCT SUBSTRING(principaluri, 12) FROM (
        SELECT principaluri FROM calendarinstances UNION SELECT principaluri FROM addressbooks
     ) x WHERE principaluri LIKE 'principals/%'
       AND SUBSTRING(principaluri, 12) NOT IN (SELECT CAST(address AS BINARY) FROM karnak_principals)"
)->fetchAll(PDO::FETCH_COLUMN);
$n = 0;
foreach (array_unique(array_merge($rows, $stray)) as $addr) {
    Store::purge((string)$addr);
    $n++;
}
echo "dav reconcile: purged $n orphaned principal(s)\n";
