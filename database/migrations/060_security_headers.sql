-- 060: per-domain security headers (scripts/vhost-headers.sh, Domains page).
-- sec_headers = nosniff/X-Frame-Options/Referrer-Policy block in vhost.conf;
-- hsts 0/1/2 (2 = includeSubDomains), csp 0/1 — both opt-in, never global.
-- Existing domains start at 0: their vhosts predate the block (the script
-- sets the row when the owner applies it). New rows default to 1, matching
-- vhost-add.sh, which gives fresh vhosts the safe block.
ALTER TABLE domains ADD COLUMN IF NOT EXISTS sec_headers TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE domains ALTER COLUMN sec_headers SET DEFAULT 1;
ALTER TABLE domains ADD COLUMN IF NOT EXISTS hsts TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE domains ADD COLUMN IF NOT EXISTS csp TINYINT(1) NOT NULL DEFAULT 0;
