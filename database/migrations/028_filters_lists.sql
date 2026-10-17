-- 028: mail filters (sieve via LMTP, see email-filters-sync.sh) + mailing lists
CREATE TABLE IF NOT EXISTS email_filters (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL, domain_id INT NOT NULL,
  localpart VARCHAR(64) NOT NULL,
  field VARCHAR(8) NOT NULL DEFAULT 'subject',
  match_text VARCHAR(160) NOT NULL,
  action VARCHAR(8) NOT NULL DEFAULT 'folder',
  target VARCHAR(190) NOT NULL DEFAULT '',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (domain_id, localpart)
);
CREATE TABLE IF NOT EXISTS email_lists (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL, domain_id INT NOT NULL,
  localpart VARCHAR(64) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_list (domain_id, localpart)
);
CREATE TABLE IF NOT EXISTS email_list_members (
  id INT AUTO_INCREMENT PRIMARY KEY,
  list_id INT NOT NULL, address VARCHAR(190) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_member (list_id, address)
);
