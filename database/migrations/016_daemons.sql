-- 016: supervised user daemons
CREATE TABLE IF NOT EXISTS app_daemons (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  name VARCHAR(32) NOT NULL,
  command VARCHAR(500) NOT NULL,
  workdir VARCHAR(255) NOT NULL,
  autostart TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_daemon (user_id, name),
  INDEX (user_id)
);
-- 017: monitoring samples (24h rolling, admin dashboard sparklines)
CREATE TABLE IF NOT EXISTS server_stats (
  ts DATETIME PRIMARY KEY,
  load1 FLOAT NOT NULL DEFAULT 0,
  mem_pct FLOAT NOT NULL DEFAULT 0
);
