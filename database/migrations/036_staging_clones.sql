-- 036_staging_clones.sql — App Toolkit: staging copies of installed apps.
-- One staging per live install (v1). Snapshots are pre-push restore points
-- (files tarball + DB dump); push keeps the newest 2 per clone.
CREATE TABLE IF NOT EXISTS staging_clones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  app VARCHAR(32) NOT NULL,
  live_install_id INT NOT NULL,
  staging_domain_id INT NOT NULL,
  staging_db VARCHAR(64) NOT NULL,
  staging_db_user VARCHAR(64) NOT NULL,
  basic_auth VARCHAR(190) NOT NULL DEFAULT '',
  status VARCHAR(16) NOT NULL DEFAULT 'cloning',
  cloned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  last_push_at TIMESTAMP NULL,
  INDEX (user_id)
);
CREATE TABLE IF NOT EXISTS staging_snapshots (
  id INT AUTO_INCREMENT PRIMARY KEY,
  clone_id INT NOT NULL,
  files_path VARCHAR(255) NOT NULL,
  db_path VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (clone_id)
);
