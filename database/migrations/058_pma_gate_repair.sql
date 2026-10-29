-- 058: repair installs where migration 004 was recorded although the fresh schema omitted the column.
ALTER TABLE users ADD COLUMN IF NOT EXISTS pma_gate_hash VARCHAR(255) NULL;
