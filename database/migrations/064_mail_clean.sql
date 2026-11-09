-- 064: auto-empty Trash and Spam (scripts/mail-clean.sh, Email -> Manage).
-- clean_days = messages sitting in a mailbox's Trash/Junk/Spam folders longer
-- than this (counted from when they were moved there) are deleted nightly;
-- 0 = off. Existing mailboxes start at 0 (an update never deletes mail
-- nobody asked to delete); new rows default to 30 — same pattern as 061.
ALTER TABLE email_accounts ADD COLUMN IF NOT EXISTS clean_days SMALLINT NOT NULL DEFAULT 0;
ALTER TABLE email_accounts ALTER COLUMN clean_days SET DEFAULT 30;
