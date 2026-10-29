-- 029: packages had no unique key, so every schema re-import re-seeded
-- Starter/Business/Reseller (INSERT IGNORE can't ignore without one).
-- Collapse dupes first (NULL reseller_ids group together in MySQL), then key.
-- Re-runnable: key add is conditional on INFORMATION_SCHEMA (fresh installs
-- already carry the key via schema.sql).
DELETE p1 FROM packages p1 JOIN packages p2
  ON p1.name = p2.name
  AND COALESCE(p1.reseller_id, 0) = COALESCE(p2.reseller_id, 0)
  AND p1.id > p2.id;
SET @have_key = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'packages' AND INDEX_NAME = 'uniq_pkg');
SET @add_key = IF(@have_key = 0,
  'ALTER TABLE packages ADD UNIQUE KEY uniq_pkg (name, reseller_id)',
  'SELECT 1');
PREPARE stmt FROM @add_key; EXECUTE stmt; DEALLOCATE PREPARE stmt;
