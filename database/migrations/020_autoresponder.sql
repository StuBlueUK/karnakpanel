-- 020: per-mailbox autoresponders (vacation replies via Exim autoreply transport,
-- see scripts/autoreply-sync.sh + vacation_reply in installer/configs/exim/exim4.conf)
ALTER TABLE email_accounts ADD COLUMN IF NOT EXISTS autoresp_enabled TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE email_accounts ADD COLUMN IF NOT EXISTS autoresp_subject VARCHAR(190) NOT NULL DEFAULT '';
ALTER TABLE email_accounts ADD COLUMN IF NOT EXISTS autoresp_text TEXT NULL;
