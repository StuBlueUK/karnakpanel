-- 013: one-click app installs registry
CREATE TABLE IF NOT EXISTS site_installs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  domain_id INT NOT NULL,
  app VARCHAR(32) NOT NULL,
  path VARCHAR(255) NOT NULL DEFAULT '',
  url VARCHAR(255) NOT NULL DEFAULT '',
  version VARCHAR(32) NOT NULL DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id)
);
