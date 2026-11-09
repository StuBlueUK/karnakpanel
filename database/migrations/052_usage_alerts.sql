-- 052: 80%/95% usage warnings (scripts/usage-alerts.sh). Last level alerted
-- per user (0/80/95), re-armed under 75%; bandwidth state is per month.
ALTER TABLE users ADD COLUMN IF NOT EXISTS disk_alert TINYINT NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS bw_alert TINYINT NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS bw_alert_month CHAR(7) NULL DEFAULT NULL;
