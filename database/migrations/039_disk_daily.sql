-- 039: per-account disk history for usage graphs (see scripts/bandwidth-collect.sh,
-- which upserts today's row hourly from users.disk_used_mb — same doctrine as
-- bandwidth_daily: re-runs converge, resolution is daily by construction).
CREATE TABLE IF NOT EXISTS disk_daily (
  user_id INT NOT NULL,
  day DATE NOT NULL,
  mb INT NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, day),
  INDEX (day)
);
