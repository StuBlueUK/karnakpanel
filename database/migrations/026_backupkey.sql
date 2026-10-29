-- 026: per-user backup encryption keys (see scripts/backup-crypt.sh; shown once)
ALTER TABLE users ADD COLUMN IF NOT EXISTS backup_pass VARCHAR(255) NULL;
