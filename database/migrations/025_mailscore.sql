-- 025: cached mail deliverability scores (see scripts/mail-score.sh)
CREATE TABLE IF NOT EXISTS mail_scores (
  domain_id INT PRIMARY KEY,
  score INT NOT NULL DEFAULT 0,
  details TEXT NOT NULL DEFAULT '',
  checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
