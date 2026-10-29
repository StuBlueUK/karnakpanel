-- 057: blacklist monitor (scripts/blacklist-check.sh, nightly + Mail queue
-- page "Check now"). One row per (server IP or hosted domain, list): clean,
-- listed or unchecked (the list refuses this server's resolvers, or gave no
-- answer). listed_since drives the alert-on-change logic, never the clock.
CREATE TABLE IF NOT EXISTS blacklist_status (
  target VARCHAR(255) NOT NULL,
  kind VARCHAR(8) NOT NULL,
  zone VARCHAR(64) NOT NULL,
  name VARCHAR(64) NOT NULL,
  state VARCHAR(12) NOT NULL,
  detail VARCHAR(255) NOT NULL DEFAULT '',
  delist VARCHAR(255) NOT NULL DEFAULT '',
  listed_since TIMESTAMP NULL DEFAULT NULL,
  checked_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (target, zone)
);
