-- 059: mailbox import from a remote IMAP account (scripts/mail-import.sh,
-- Email → Manage → Import). One row per attempt; no remote password is ever
-- stored. Purged with the mailbox, domain (DomainModel::purgeRows) and account.
CREATE TABLE IF NOT EXISTS mail_imports (
  job CHAR(16) PRIMARY KEY,
  user_id INT NOT NULL,
  domain_id INT NOT NULL,
  localpart VARCHAR(64) NOT NULL,
  host VARCHAR(253) NOT NULL,
  port SMALLINT NOT NULL DEFAULT 993,
  remote_user VARCHAR(254) NOT NULL,
  stage VARCHAR(16) NOT NULL DEFAULT 'checking',
  copied INT NOT NULL DEFAULT 0,
  total INT NOT NULL DEFAULT 0,
  detail VARCHAR(255) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  KEY user_created (user_id, created_at),
  KEY box (domain_id, localpart)
);
