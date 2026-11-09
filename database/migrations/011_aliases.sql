-- 011: parked (alias) domains — web-only, no mailboxes
CREATE TABLE IF NOT EXISTS domain_aliases (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  domain_id INT NOT NULL,
  alias VARCHAR(190) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_alias (alias),
  INDEX (domain_id)
);
