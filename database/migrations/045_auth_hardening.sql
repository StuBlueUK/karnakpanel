-- 045_auth_hardening.sql — per-username login throttle needs a username index
-- (a distributed guess run must not reset/escape the per-IP bucket), and TOTP
-- replay protection records the last accepted 30s step per user.
ALTER TABLE login_attempts ADD INDEX IF NOT EXISTS idx_user_time (username, attempted_at);
ALTER TABLE users ADD COLUMN IF NOT EXISTS totp_last_step BIGINT NULL AFTER totp_enabled;
-- API tokens: optional expiry (NULL = never, the pre-045 behaviour — billing
-- tokens must not die silently) + last-use audit trail.
ALTER TABLE api_tokens ADD COLUMN IF NOT EXISTS expires_at DATETIME NULL;
ALTER TABLE api_tokens ADD COLUMN IF NOT EXISTS last_used_at DATETIME NULL;
ALTER TABLE api_tokens ADD COLUMN IF NOT EXISTS last_used_ip VARCHAR(45) NULL;
