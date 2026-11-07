-- 009: bandwidth auto-suspend marker (1 = suspended BY quota cron, safe to auto-lift monthly)
ALTER TABLE users ADD COLUMN IF NOT EXISTS bw_suspended TINYINT(1) NOT NULL DEFAULT 0;
