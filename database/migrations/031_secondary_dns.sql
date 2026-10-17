-- 031: per-domain secondary nameserver IPs (empty = primary-only).
ALTER TABLE domains ADD COLUMN IF NOT EXISTS secondary_ns VARCHAR(90) NOT NULL DEFAULT '';
