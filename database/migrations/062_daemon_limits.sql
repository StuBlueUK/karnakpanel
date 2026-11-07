-- 062: Daemons page limits (scripts/app-daemon.sh). max_daemons = how many
-- supervised apps an account on the plan may create: Bronze 1, Silver 3,
-- Gold 5; every other package (reseller-made) gets the column default 1.
-- Accounts without a package (admins) are not limited. Existing daemons over
-- a plan's limit keep running; only new ones are refused.
-- max_hours = scheduled restart of one daemon (RuntimeMaxSec, 0 = never).
ALTER TABLE packages ADD COLUMN IF NOT EXISTS max_daemons INT NOT NULL DEFAULT 1;
UPDATE packages SET max_daemons=3 WHERE name='Silver' AND reseller_id IS NULL;
UPDATE packages SET max_daemons=5 WHERE name='Gold' AND reseller_id IS NULL;
ALTER TABLE app_daemons ADD COLUMN IF NOT EXISTS max_hours SMALLINT NOT NULL DEFAULT 0;
