-- 047: MTA-STS + TLS-RPT publishing and DMARC/TLS aggregate report intake.
-- domains.mta_sts_mode: off (nothing published) | testing | enforce | none
-- (winding down: policy still served so cached enforce policies expire).
-- mta_sts_id is the _mta-sts TXT id; it must change with every policy change.
-- mail_reports = dmarc-reports@ / tls-reports@ intake on (Exim reports router).
-- Reports are unauthenticated mail: rows are attributed by the RECEIVING
-- domain and only kept when the report's own policy domain matches it.
ALTER TABLE domains ADD COLUMN IF NOT EXISTS mta_sts_mode VARCHAR(8) NOT NULL DEFAULT 'off';
ALTER TABLE domains ADD COLUMN IF NOT EXISTS mta_sts_id VARCHAR(20) NOT NULL DEFAULT '';
-- UTC instant until which an enforce policy may still sit in sender caches
-- (set on every step-down from enforce; removal is refused before it)
ALTER TABLE domains ADD COLUMN IF NOT EXISTS mta_sts_cached_until DATETIME NULL DEFAULT NULL;
ALTER TABLE domains ADD COLUMN IF NOT EXISTS mail_reports TINYINT(1) NOT NULL DEFAULT 0;
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
