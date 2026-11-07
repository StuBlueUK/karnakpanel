-- 055: Email page rework. Filters gain a match type (contains / is / starts)
-- and cc + spam-score fields; autoresponders gain an optional date window
-- (autoreply-sync.sh, run hourly from cron, only publishes replies inside it).
-- Forwarders need no column: "keep a copy" is the source address listed in
-- its own targets (Exim skips the redirect for that child and delivers it).
ALTER TABLE email_filters ADD COLUMN IF NOT EXISTS match_type VARCHAR(8) NOT NULL DEFAULT 'contains';
ALTER TABLE email_accounts ADD COLUMN IF NOT EXISTS autoresp_start DATE NULL;
ALTER TABLE email_accounts ADD COLUMN IF NOT EXISTS autoresp_end DATE NULL;
