-- 044_plugin_updates.sql — Fleet Updates v1.1: track updatable-plugin count so
-- the per-row Plugins button has something to fire at when core is current.
ALTER TABLE app_versions ADD COLUMN IF NOT EXISTS plugins_available INT NOT NULL DEFAULT 0;
