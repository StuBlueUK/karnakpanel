-- 022: preview URLs (see scripts/preview-add.sh; token host served as vhost alias)
CREATE TABLE IF NOT EXISTS preview_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL, domain_id INT NOT NULL,
  token VARCHAR(64) UNIQUE NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_prev (domain_id, token)
);
