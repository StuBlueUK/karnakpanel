-- 042_app_versions.sql — Fleet Updates scan results (one row per site_installs id).
-- Written by fleet-scan.sh (nightly cron), read by FleetController (admin/reseller only).
-- v1 tracks WordPress core only: live version from wp-includes/version.php,
-- latest from a single cached wordpress.org version-check call. Plugin/theme
-- per-item status and vuln feeds are phase 2 (needs a WPScan token).
CREATE TABLE IF NOT EXISTS app_versions (
  install_id INT PRIMARY KEY,
  app VARCHAR(32) NOT NULL DEFAULT 'wordpress',
  live_version VARCHAR(32) NOT NULL DEFAULT '',
  latest_version VARCHAR(32) NOT NULL DEFAULT '',
  update_available TINYINT(1) NOT NULL DEFAULT 0,
  plugins_total INT NOT NULL DEFAULT 0,
  scanned_at TIMESTAMP NULL DEFAULT NULL,
  scan_note VARCHAR(190) NOT NULL DEFAULT ''
);
