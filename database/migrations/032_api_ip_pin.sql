-- 032: optional IP pin for API tokens (WHMCS hardening: a stolen token is
-- useless off the pinned host). NULL/empty = any IP (previous behaviour).
ALTER TABLE api_tokens ADD COLUMN IF NOT EXISTS allowed_ip VARCHAR(45) DEFAULT NULL;
