-- 041: track .htaccess import freshness per domain (see DomainController).
-- rewrite_src_hash = sha256 of the docroot .htaccess at import time (NULL =
-- hand-edited rules, where the editor is the source of truth). The domains
-- list compares it against the live file and flags "stale" on drift.
ALTER TABLE domains ADD COLUMN IF NOT EXISTS rewrite_src_hash VARCHAR(64) NULL DEFAULT NULL;
ALTER TABLE domains ADD COLUMN IF NOT EXISTS rewrite_imported_at TIMESTAMP NULL DEFAULT NULL;
