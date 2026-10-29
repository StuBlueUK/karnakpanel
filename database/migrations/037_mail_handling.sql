-- 037: per-domain mail handling (local delivery vs remote provider MX).
-- Default local = zero behaviour change. Remote domains route by public MX
-- via the remote_route router; local mailboxes/forwards/lists/autoreplies
-- stay in the DB but go dormant, and reactivate on toggle-back.
ALTER TABLE domains ADD COLUMN IF NOT EXISTS mail_handling VARCHAR(8) NOT NULL DEFAULT 'local';
