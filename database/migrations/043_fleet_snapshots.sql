-- 043_fleet_snapshots.sql — Fleet Updates restore points (one per update run,
-- newest 2 kept per install). Same shape as staging_snapshots, but tied to a
-- live site_installs row instead of a staging clone.
CREATE TABLE IF NOT EXISTS fleet_snapshots (
  id INT AUTO_INCREMENT PRIMARY KEY,
  install_id INT NOT NULL,
  files_path VARCHAR(255) NOT NULL,
  db_path VARCHAR(255) NOT NULL,
  note VARCHAR(190) NOT NULL DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (install_id)
);
