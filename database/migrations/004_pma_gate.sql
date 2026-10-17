-- 004: per-user phpMyAdmin gate password (SHA256-crypt hash for OLS htpasswd realm)
ALTER TABLE users ADD COLUMN IF NOT EXISTS pma_gate_hash VARCHAR(255) NULL;
