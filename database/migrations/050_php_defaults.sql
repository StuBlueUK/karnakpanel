-- 050: PHP refresh (Release A). New sites/plans default to 8.3; plans that
-- still name a retired/legacy version move to 8.3 so NEW accounts never land
-- on it. Existing domains are NOT touched (no silent site migration — the
-- admin moves them from the PHP page; php-retire.sh counts what is left).
ALTER TABLE packages MODIFY php_version VARCHAR(8) DEFAULT '8.3';
ALTER TABLE domains MODIFY php_version VARCHAR(8) DEFAULT '8.3';
UPDATE packages SET php_version='8.3' WHERE php_version IN ('8.1','8.2');
