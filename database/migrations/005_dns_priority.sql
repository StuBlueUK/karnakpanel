-- 005: MX priority for structured DNS records
ALTER TABLE dns_records ADD COLUMN IF NOT EXISTS priority INT NOT NULL DEFAULT 10 AFTER value;
