-- 010: per-package PHP-FPM pool sizing
ALTER TABLE packages ADD COLUMN IF NOT EXISTS max_children INT NOT NULL DEFAULT 10;
