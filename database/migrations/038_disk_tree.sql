-- 038: cached per-user disk-tree breakdown (Disk page). Payload is the
-- top-20 folders JSON from disk-tree.sh; header totals reuse users.*
-- columns from 030. NULL payload = never measured (page measures on demand).
CREATE TABLE IF NOT EXISTS disk_tree_cache (
  user_id INT PRIMARY KEY,
  payload JSON NOT NULL,
  measured_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
