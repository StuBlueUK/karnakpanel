-- 053: reseller white-label (app/Models/Branding.php). One row per reseller;
-- logo is a re-encoded PNG, base64, kept in the DB (not on disk: public/ is
-- web-served and the panel tree is replaced on every self-update).
CREATE TABLE IF NOT EXISTS branding (
  reseller_id INT PRIMARY KEY,
  name VARCHAR(40) NOT NULL DEFAULT '',
  accent CHAR(7) NOT NULL DEFAULT '',
  support_url VARCHAR(190) NOT NULL DEFAULT '',
  support_email VARCHAR(190) NOT NULL DEFAULT '',
  logo MEDIUMTEXT NULL,
  logo_hash CHAR(16) NOT NULL DEFAULT '',
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
