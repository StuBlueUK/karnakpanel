-- 049: mail-guard (compromised-mailbox send hold). Held while send_hold_at
-- is set; Exim's RCPT ACL refuses authenticated sends from held mailboxes.
-- send_released_at fences the detector: only activity after a release counts.
ALTER TABLE email_accounts ADD COLUMN IF NOT EXISTS send_hold_at DATETIME NULL;
ALTER TABLE email_accounts ADD COLUMN IF NOT EXISTS send_hold_reason VARCHAR(255) NOT NULL DEFAULT '';
ALTER TABLE email_accounts ADD COLUMN IF NOT EXISTS send_released_at DATETIME NULL;
