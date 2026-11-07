-- 007: email forwarders (source -> comma-separated targets)
CREATE TABLE IF NOT EXISTS email_forwards (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  domain_id INT NOT NULL,
  localpart VARCHAR(64) NOT NULL,
  targets TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_fwd (domain_id, localpart)
);
