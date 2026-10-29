-- 035: seed plans renamed to metal tiers. "Starter/Business/Reseller" as
-- plan names collided conceptually with roles ("Reseller" the plan vs
-- reseller the role — real user confusion). Only PRISTINE seeds move (every
-- seeded limit matches, so customised plans keep their names); rows move only
-- when the target name is free (unique key is (name, reseller_id)).
UPDATE packages SET name='Bronze'
WHERE name='Starter' AND reseller_id IS NULL
  AND disk_quota_mb=5000 AND bandwidth_quota_gb=100 AND max_domains=5
  AND max_emails=50 AND max_dbs=5 AND max_ftp=5 AND php_version='8.2'
  AND (SELECT COUNT(*) FROM (SELECT 1 FROM packages WHERE name='Bronze' AND reseller_id IS NULL) t) = 0;
UPDATE packages SET name='Silver'
WHERE name='Business' AND reseller_id IS NULL
  AND disk_quota_mb=20000 AND bandwidth_quota_gb=500 AND max_domains=50
  AND max_emails=500 AND max_dbs=50 AND max_ftp=50 AND php_version='8.2'
  AND (SELECT COUNT(*) FROM (SELECT 1 FROM packages WHERE name='Silver' AND reseller_id IS NULL) t) = 0;
UPDATE packages SET name='Gold'
WHERE name='Reseller' AND reseller_id IS NULL
  AND disk_quota_mb=100000 AND bandwidth_quota_gb=2000 AND max_domains=200
  AND max_emails=2000 AND max_dbs=200 AND max_ftp=200 AND php_version='8.3'
  AND (SELECT COUNT(*) FROM (SELECT 1 FROM packages WHERE name='Gold' AND reseller_id IS NULL) t) = 0;
