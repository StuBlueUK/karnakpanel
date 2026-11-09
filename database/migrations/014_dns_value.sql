-- 014: DNS values must fit DKIM keys (~400 chars)
ALTER TABLE dns_records MODIFY COLUMN value TEXT NOT NULL;
