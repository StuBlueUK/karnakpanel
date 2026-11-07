-- 048: deliverability autopilot (guided DMARC none -> quarantine -> reject and
-- MTA-STS testing -> enforce, judged from aggregate reports; MailAutopilot).
-- dmarc_ignored: sources the owner marked "not mine" (spoofers) so they don't
-- block a step-up. mail_alerts: dedup for the ingester's owner alerts
-- (kind src = failing sender per IP, tls = TLS failures, ready = step ready).
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
