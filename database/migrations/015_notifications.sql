-- 015: notification center feed
CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  type VARCHAR(32) NOT NULL,
  title VARCHAR(190) NOT NULL,
  body TEXT NOT NULL DEFAULT '',
  read_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id), INDEX (read_at)
);
