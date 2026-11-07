-- 066: per-account PHP mail control (mail() / sendmail from a hosting
-- account's sites and cron jobs). php_mail 0 = refused by Exim (admin switch,
-- or Mail Guard after a flood — reason/at say which); php_mail_cap = hourly
-- recipients, NULL = the server default (settings php_mail_cap, else
-- smtp_default, else 200), 0 = no cap. mail-owners-sync.sh renders both into
-- /etc/exim4/virtual/phpmail. form_alerts dedupes the "form is probably being
-- abused" notice (one per account + site + form path per 7 days).
ALTER TABLE users ADD COLUMN IF NOT EXISTS php_mail TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE users ADD COLUMN IF NOT EXISTS php_mail_cap INT NULL DEFAULT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS php_mail_off_reason VARCHAR(255) NULL DEFAULT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS php_mail_off_at TIMESTAMP NULL DEFAULT NULL;
CREATE TABLE IF NOT EXISTS form_alerts (
  user_id INT NOT NULL,
  domain VARCHAR(190) NOT NULL,
  path VARCHAR(190) NOT NULL,
  notified_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, domain, path)
);
