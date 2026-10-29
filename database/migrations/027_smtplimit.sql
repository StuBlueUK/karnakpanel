-- 027: per-mailbox SMTP rate override (NULL = global smtp_default, else 200/hr)
ALTER TABLE email_accounts ADD COLUMN IF NOT EXISTS smtp_limit INT NULL;
