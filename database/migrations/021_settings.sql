-- 021: global key/value settings (see AdminController::alertEmail,
-- service-watchdog.sh reads alert_email with admin-account fallback)
CREATE TABLE IF NOT EXISTS settings (
  name VARCHAR(190) PRIMARY KEY,
  value TEXT NOT NULL
);
