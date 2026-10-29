-- 054: Karnak Builder (docs/karnakbuilder-spec.md). One row per builder site
-- (domain root or one subfolder). The project itself lives in the owner's
-- home (~/.karnakbuilder/<site key>/), never in the DB. Package limit is 0
-- (off) by default: admins/resellers grant sites per package.
CREATE TABLE IF NOT EXISTS builder_sites (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  domain_id INT NOT NULL,
  subdir VARCHAR(64) NOT NULL DEFAULT '',
  title VARCHAR(120) NOT NULL DEFAULT '',
  template VARCHAR(32) NOT NULL DEFAULT '',
  status VARCHAR(12) NOT NULL DEFAULT 'draft',
  saved_at TIMESTAMP NULL DEFAULT NULL,
  published_at TIMESTAMP NULL DEFAULT NULL,
  lock_token CHAR(32) NOT NULL DEFAULT '',
  lock_at TIMESTAMP NULL DEFAULT NULL,
  preview_token CHAR(32) NOT NULL DEFAULT '',
  preview_expires TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_site (domain_id, subdir),
  INDEX (user_id)
);
ALTER TABLE packages ADD COLUMN IF NOT EXISTS max_builder_sites INT NOT NULL DEFAULT 0;
