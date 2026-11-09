-- 033: per-domain bot-shield toggle (default ON; vhost-add.sh ships the
-- SHIELD block for new domains, waf-shield.sh --sync backfills existing).
ALTER TABLE domains ADD COLUMN IF NOT EXISTS waf TINYINT(1) NOT NULL DEFAULT 1;
