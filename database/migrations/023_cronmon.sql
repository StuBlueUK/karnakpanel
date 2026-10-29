-- 023: cron dead-man monitoring (see scripts/cron-monitor.sh; NULL = unwatched)
ALTER TABLE cron_jobs ADD COLUMN IF NOT EXISTS expect_min INT NULL;
ALTER TABLE cron_jobs ADD COLUMN IF NOT EXISTS grace_min INT NOT NULL DEFAULT 15;
