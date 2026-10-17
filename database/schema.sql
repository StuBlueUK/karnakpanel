-- KarnakPanel schema (MariaDB 10.6+)
CREATE DATABASE IF NOT EXISTS skypanel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE skypanel;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(32) UNIQUE NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','reseller','user') NOT NULL DEFAULT 'user',
  owner_id INT NULL,
  package_id INT NULL,
  disk_quota_mb INT NOT NULL DEFAULT 5120,
  disk_used_mb INT NULL DEFAULT NULL,
  disk_measured_at TIMESTAMP NULL DEFAULT NULL,
  bandwidth_quota_gb INT NOT NULL DEFAULT 100,
  bw_suspended TINYINT(1) NOT NULL DEFAULT 0,
  disk_alert TINYINT NOT NULL DEFAULT 0,
  bw_alert TINYINT NOT NULL DEFAULT 0,
  bw_alert_month CHAR(7) NULL DEFAULT NULL,
  status ENUM('active','suspended') NOT NULL DEFAULT 'active',  last_login DATETIME NULL, last_ip VARCHAR(45) NULL,
  totp_secret VARCHAR(64) NULL,
  totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
  totp_last_step BIGINT NULL,
  ns1 VARCHAR(190) NULL,
  ns2 VARCHAR(190) NULL,
  backup_pass VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX(owner_id)
);

CREATE TABLE IF NOT EXISTS packages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  reseller_id INT NULL,
  disk_quota_mb INT DEFAULT 5120,
  bandwidth_quota_gb INT DEFAULT 100,
  max_domains INT DEFAULT 10, max_emails INT DEFAULT 100,
  max_dbs INT DEFAULT 10, max_ftp INT DEFAULT 10,
  php_version VARCHAR(8) DEFAULT '8.3',
  max_children INT NOT NULL DEFAULT 10,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_pkg (name, reseller_id)
);

CREATE TABLE IF NOT EXISTS domains (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  domain VARCHAR(190) NOT NULL,
  docroot VARCHAR(255) NOT NULL,
  php_version VARCHAR(8) DEFAULT '8.3',
  ssl_enabled TINYINT DEFAULT 0,
  dnssec TINYINT(1) NOT NULL DEFAULT 0,
  maintenance TINYINT(1) NOT NULL DEFAULT 0,
  waf TINYINT(1) NOT NULL DEFAULT 1,
  mail_handling VARCHAR(8) NOT NULL DEFAULT 'local',
  rewrite_rules TEXT NOT NULL DEFAULT '',
  rewrite_src_hash VARCHAR(64) NULL DEFAULT NULL,
  rewrite_imported_at TIMESTAMP NULL DEFAULT NULL,
  dom_expires DATE NULL, ssl_expires DATE NULL,
  expiry_checked_at TIMESTAMP NULL,
  secondary_ns VARCHAR(90) NOT NULL DEFAULT '',
  mta_sts_mode VARCHAR(8) NOT NULL DEFAULT 'off',
  mta_sts_id VARCHAR(20) NOT NULL DEFAULT '',
  mta_sts_cached_until DATETIME NULL DEFAULT NULL,
  mail_reports TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(16) DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY(domain), INDEX(user_id)
);

CREATE TABLE IF NOT EXISTS dns_records (
  id INT AUTO_INCREMENT PRIMARY KEY,
  domain_id INT NOT NULL,
  type VARCHAR(8) NOT NULL, host VARCHAR(190) NOT NULL,
  value TEXT NOT NULL, priority INT NOT NULL DEFAULT 10, ttl INT DEFAULT 3600,
  FOREIGN KEY(domain_id) REFERENCES domains(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS email_accounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL, domain_id INT NOT NULL,
  localpart VARCHAR(64) NOT NULL, password_hash VARCHAR(255) NOT NULL,
  quota_mb INT DEFAULT 1024,
  spam_threshold FLOAT NOT NULL DEFAULT 5.0,
  smtp_limit INT NULL,
  send_hold_at DATETIME NULL,
  send_hold_reason VARCHAR(255) NOT NULL DEFAULT '',
  send_released_at DATETIME NULL,
  autoresp_enabled TINYINT(1) NOT NULL DEFAULT 0,
  autoresp_subject VARCHAR(190) NOT NULL DEFAULT '',
  autoresp_text TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY(domain_id, localpart)
);
CREATE TABLE IF NOT EXISTS email_forwards (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL, domain_id INT NOT NULL,
  localpart VARCHAR(64) NOT NULL, targets TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_fwd (domain_id, localpart)
);
CREATE TABLE IF NOT EXISTS preview_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL, domain_id INT NOT NULL,
  token VARCHAR(64) UNIQUE NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_prev (domain_id, token)
);
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

CREATE TABLE IF NOT EXISTS ftp_accounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL, ftp_user VARCHAR(64) UNIQUE NOT NULL,
  homedir VARCHAR(255) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS mysql_dbs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL, db_name VARCHAR(64) UNIQUE NOT NULL,
  db_user VARCHAR(64) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS cron_jobs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL, schedule VARCHAR(64) NOT NULL,
  command VARCHAR(500) NOT NULL, active TINYINT DEFAULT 1,
  expect_min INT NULL, grace_min INT NOT NULL DEFAULT 15
);

CREATE TABLE IF NOT EXISTS backups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL, filename VARCHAR(190) NOT NULL,
  size_bytes BIGINT DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS api_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL, label VARCHAR(80) DEFAULT '',
  token VARCHAR(64) UNIQUE NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  allowed_ip VARCHAR(45) DEFAULT NULL,
  expires_at DATETIME NULL,
  last_used_at DATETIME NULL,
  last_used_ip VARCHAR(45) NULL
);
 CREATE TABLE IF NOT EXISTS mail_scores (
  domain_id INT PRIMARY KEY,
  score INT NOT NULL DEFAULT 0,
  details TEXT NOT NULL DEFAULT '',
  checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- DMARC + TLS-RPT aggregate reports (see database/migrations/047_mail_reports.sql)
CREATE TABLE IF NOT EXISTS dmarc_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  domain_id INT NOT NULL,
  org_name VARCHAR(190) NOT NULL,
  report_id VARCHAR(190) NOT NULL,
  policy_domain VARCHAR(190) NOT NULL DEFAULT '',
  policy_p VARCHAR(16) NOT NULL DEFAULT '',
  date_begin DATETIME NULL,
  date_end DATETIME NULL,
  received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_dmarc (domain_id, org_name, report_id),
  INDEX idx_dmarc_dom (domain_id, date_end)
);
CREATE TABLE IF NOT EXISTS dmarc_records (
  id INT AUTO_INCREMENT PRIMARY KEY,
  report_id INT NOT NULL,
  source_ip VARCHAR(45) NOT NULL,
  msg_count INT NOT NULL DEFAULT 0,
  disposition VARCHAR(16) NOT NULL DEFAULT '',
  dkim_eval VARCHAR(8) NOT NULL DEFAULT '',
  spf_eval VARCHAR(8) NOT NULL DEFAULT '',
  header_from VARCHAR(190) NOT NULL DEFAULT '',
  dkim_domain VARCHAR(190) NOT NULL DEFAULT '',
  dkim_result VARCHAR(16) NOT NULL DEFAULT '',
  spf_domain VARCHAR(190) NOT NULL DEFAULT '',
  spf_result VARCHAR(16) NOT NULL DEFAULT '',
  INDEX idx_dmarc_rec (report_id),
  FOREIGN KEY(report_id) REFERENCES dmarc_reports(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS tlsrpt_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  domain_id INT NOT NULL,
  org_name VARCHAR(190) NOT NULL,
  report_id VARCHAR(190) NOT NULL,
  policy_type VARCHAR(16) NOT NULL DEFAULT '',
  date_begin DATETIME NULL,
  date_end DATETIME NULL,
  success_count INT NOT NULL DEFAULT 0,
  failure_count INT NOT NULL DEFAULT 0,
  failures TEXT NOT NULL DEFAULT '',
  received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_tlsrpt (domain_id, org_name, report_id),
  INDEX idx_tlsrpt_dom (domain_id, date_end)
);
-- deliverability autopilot (see database/migrations/048_mail_autopilot.sql)
CREATE TABLE IF NOT EXISTS dmarc_ignored (
  domain_id INT NOT NULL,
  source_ip VARCHAR(45) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (domain_id, source_ip)
);
CREATE TABLE IF NOT EXISTS mail_alerts (
  domain_id INT NOT NULL,
  kind VARCHAR(8) NOT NULL,
  item VARCHAR(64) NOT NULL DEFAULT '',
  sent_at DATETIME NOT NULL,
  PRIMARY KEY (domain_id, kind, item)
);

-- cached per-user disk-tree breakdown (see database/migrations/038_disk_tree.sql)
CREATE TABLE IF NOT EXISTS disk_tree_cache (
  user_id INT PRIMARY KEY,
  payload JSON NOT NULL,
  measured_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- global key/value settings (see database/migrations/021_settings.sql)
CREATE TABLE IF NOT EXISTS settings (
  name VARCHAR(190) PRIMARY KEY,
  value TEXT NOT NULL
);

-- admin row: created by installer/install.sh with a random per-install
-- password (INSERT IGNORE — re-runs never touch it). NO seed credential
-- lives here anymore: a published default password, even rotated at install
-- time, is scanner bait and a footgun on interrupted installs.
-- system seeds: re-import safe. (No unique key can cover NULL reseller_id,
-- so INSERT IGNORE alone would re-seed on every import — hence the guards.
-- See migration 029, which also dedupes pre-existing copies.)
INSERT INTO packages(name,disk_quota_mb,bandwidth_quota_gb,max_domains,max_emails,max_dbs,max_ftp,php_version)
SELECT 'Bronze',5120,100,5,50,5,5,'8.3' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM packages WHERE name='Bronze' AND reseller_id IS NULL);
INSERT INTO packages(name,disk_quota_mb,bandwidth_quota_gb,max_domains,max_emails,max_dbs,max_ftp,php_version)
SELECT 'Silver',20480,500,50,500,50,50,'8.3' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM packages WHERE name='Silver' AND reseller_id IS NULL);
INSERT INTO packages(name,disk_quota_mb,bandwidth_quota_gb,max_domains,max_emails,max_dbs,max_ftp,php_version)
SELECT 'Gold',102400,2000,200,2000,200,200,'8.3' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM packages WHERE name='Gold' AND reseller_id IS NULL);

-- bandwidth accounting (see database/migrations/002_bandwidth.sql)
CREATE TABLE IF NOT EXISTS bandwidth_daily (
  user_id INT NOT NULL,
  domain VARCHAR(190) NOT NULL,
  day DATE NOT NULL,
  bytes BIGINT NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, domain, day),
  INDEX (day)
);
-- per-account disk history for usage graphs (migration 039)
CREATE TABLE IF NOT EXISTS disk_daily (
  user_id INT NOT NULL,
  day DATE NOT NULL,
  mb INT NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, day),
  INDEX (day)
);
-- self-update bookkeeping: which migration files already ran (panel-update.sh)
CREATE TABLE IF NOT EXISTS applied_migrations (
  name VARCHAR(190) PRIMARY KEY,
  applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
-- parked (alias) domains: web-only pointers at a real domain (no mailboxes)
CREATE TABLE IF NOT EXISTS domain_aliases (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  domain_id INT NOT NULL,
  alias VARCHAR(190) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_alias (alias),
  INDEX (domain_id)
);
-- notification center feed (user_id NULL = admin/system scope)
CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  type VARCHAR(32) NOT NULL,
  title VARCHAR(190) NOT NULL,
  body TEXT NOT NULL DEFAULT '',
  read_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id), INDEX (read_at)
);
-- reseller white-label (app/Models/Branding.php). One row per reseller;
-- logo is a re-encoded PNG, base64, kept in the DB (not on disk: public/ is
-- web-served and the panel tree is replaced on every self-update).
CREATE TABLE IF NOT EXISTS branding (
  reseller_id INT PRIMARY KEY,
  name VARCHAR(40) NOT NULL DEFAULT '',
  accent CHAR(7) NOT NULL DEFAULT '',
  support_url VARCHAR(190) NOT NULL DEFAULT '',
  support_email VARCHAR(190) NOT NULL DEFAULT '',
  logo MEDIUMTEXT NULL,
  logo_hash CHAR(16) NOT NULL DEFAULT '',
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
-- one-click app installs registry
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

-- App Toolkit: staging copies of installed apps (see migrations/036_staging_clones.sql)
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

-- Fleet Updates scan results (see database/migrations/042_app_versions.sql)
CREATE TABLE IF NOT EXISTS app_versions (
  install_id INT PRIMARY KEY,
  app VARCHAR(32) NOT NULL DEFAULT 'wordpress',
  live_version VARCHAR(32) NOT NULL DEFAULT '',
  latest_version VARCHAR(32) NOT NULL DEFAULT '',
  update_available TINYINT(1) NOT NULL DEFAULT 0,
  plugins_total INT NOT NULL DEFAULT 0,
  plugins_available INT NOT NULL DEFAULT 0,
  scanned_at TIMESTAMP NULL DEFAULT NULL,
  scan_note VARCHAR(190) NOT NULL DEFAULT ''
);
-- Fleet Updates restore points (see database/migrations/043_fleet_snapshots.sql)
CREATE TABLE IF NOT EXISTS fleet_snapshots (
  id INT AUTO_INCREMENT PRIMARY KEY,
  install_id INT NOT NULL,
  files_path VARCHAR(255) NOT NULL,
  db_path VARCHAR(255) NOT NULL,
  note VARCHAR(190) NOT NULL DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (install_id)
);

-- login brute-force accounting (see database/migrations/003_login_attempts.sql)
CREATE TABLE IF NOT EXISTS login_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  username VARCHAR(64) NOT NULL DEFAULT '',
  success TINYINT NOT NULL DEFAULT 0,
  attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (ip, attempted_at),
  INDEX idx_user_time (username, attempted_at)
);

-- supervised user daemons (see database/migrations/016_daemons.sql)
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
-- monitoring samples (24h rolling)
CREATE TABLE IF NOT EXISTS server_stats (
  ts DATETIME PRIMARY KEY,
  load1 FLOAT NOT NULL DEFAULT 0,
  mem_pct FLOAT NOT NULL DEFAULT 0
);
-- remote import jobs (cPanel remote/root fetch progress for the Migrate
-- page poller — DB transport, not files)
CREATE TABLE IF NOT EXISTS import_jobs (
  job CHAR(16) PRIMARY KEY,
  stage VARCHAR(32) NOT NULL DEFAULT 'queued',
  pct TINYINT NOT NULL DEFAULT 0,
  detail VARCHAR(255) NOT NULL DEFAULT '',
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
