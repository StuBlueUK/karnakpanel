-- 012: maintenance mode flag per domain
ALTER TABLE domains ADD COLUMN IF NOT EXISTS maintenance TINYINT(1) NOT NULL DEFAULT 0;
