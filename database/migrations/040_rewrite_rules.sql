-- 040: per-domain custom rewrite rules (see DomainController rewrite/htaccess
-- import + scripts/rewrite-sync.sh). Rendered into the vhost between
-- CUSTOM markers; empty = markers only (baked shield + fallback untouched).
ALTER TABLE domains ADD COLUMN IF NOT EXISTS rewrite_rules TEXT NOT NULL DEFAULT '';
