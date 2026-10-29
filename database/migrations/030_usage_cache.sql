-- 030: cached disk usage (accounts page reads numbers, enforcer writes them).
-- NULL = never measured yet (callers fall back to live du one time).
ALTER TABLE users ADD COLUMN IF NOT EXISTS disk_used_mb INT NULL DEFAULT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS disk_measured_at TIMESTAMP NULL DEFAULT NULL;
