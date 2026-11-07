-- 065: Account Transfer (docs/account-transfer-spec.md) — panel-to-panel
-- account moves. One job per pairing run, one item per account in its queue;
-- the Migrate page polls items for the live bars (bytes from rsync progress2).
CREATE TABLE IF NOT EXISTS transfer_jobs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pairing CHAR(16) NOT NULL,
  source_ip VARCHAR(45) NOT NULL DEFAULT '',
  state VARCHAR(16) NOT NULL DEFAULT 'queued',
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  KEY pairing (pairing)
);
CREATE TABLE IF NOT EXISTS transfer_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  job_id INT NOT NULL,
  username VARCHAR(32) NOT NULL,
  stage VARCHAR(24) NOT NULL DEFAULT 'queued',
  pct TINYINT UNSIGNED NOT NULL DEFAULT 0,
  bytes_done BIGINT UNSIGNED NOT NULL DEFAULT 0,
  bytes_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
  rate_bps BIGINT UNSIGNED NOT NULL DEFAULT 0,
  detail VARCHAR(255) NOT NULL DEFAULT '',
  checks TEXT NULL,
  held TEXT NULL,             -- JSON: cron job ids paused until cutover
  report TEXT NULL,
  new_user_id INT NULL,
  started_at TIMESTAMP NULL DEFAULT NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY job_user (job_id, username)
);
