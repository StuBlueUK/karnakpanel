-- 019: per-mailbox spam threshold (score at which X-Spam-Flag is set)
ALTER TABLE email_accounts ADD COLUMN IF NOT EXISTS spam_threshold FLOAT NOT NULL DEFAULT 5.0;
