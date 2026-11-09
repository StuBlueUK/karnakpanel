-- 002: monthly bandwidth accounting (see scripts/bandwidth-collect.sh, run hourly)
CREATE TABLE IF NOT EXISTS bandwidth_daily (
  user_id INT NOT NULL,
  domain VARCHAR(190) NOT NULL,
  day DATE NOT NULL,
  bytes BIGINT NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, domain, day),
  INDEX (day)
);
