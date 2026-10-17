-- 034: remote import jobs (cPanel remote/root fetch progress for the
-- Migrate page poller). DB transport, not files: the panel worker reads
-- /var/lib unreliably under some stacks, and rows give free history.
CREATE TABLE IF NOT EXISTS import_jobs (
  job CHAR(16) PRIMARY KEY,
  stage VARCHAR(32) NOT NULL DEFAULT 'queued',
  pct TINYINT NOT NULL DEFAULT 0,
  detail VARCHAR(255) NOT NULL DEFAULT '',
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
