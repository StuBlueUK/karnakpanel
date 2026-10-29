-- 056: where a zone's DNS is really served. dns-delegation-check.sh (nightly,
-- and live on Sign / Check delegation) asks public resolvers for the apex NS:
-- ns_external 1 = the registrar points at other nameservers, so records the
-- panel writes are not public (the DNS, Email and Domains pages say so);
-- 0 = served here; NULL = never answered. ns_public = what the public saw.
ALTER TABLE domains ADD COLUMN IF NOT EXISTS ns_external TINYINT(1) NULL DEFAULT NULL;
ALTER TABLE domains ADD COLUMN IF NOT EXISTS ns_public VARCHAR(255) NOT NULL DEFAULT '';
ALTER TABLE domains ADD COLUMN IF NOT EXISTS ns_checked_at TIMESTAMP NULL DEFAULT NULL;
