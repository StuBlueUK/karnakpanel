-- 024: domain + SSL expiry cache (see scripts/expiry-check.sh, shown on Domains)
ALTER TABLE domains ADD COLUMN IF NOT EXISTS dom_expires DATE NULL;
ALTER TABLE domains ADD COLUMN IF NOT EXISTS ssl_expires DATE NULL;
ALTER TABLE domains ADD COLUMN IF NOT EXISTS expiry_checked_at TIMESTAMP NULL;
