-- 063: passkeys (WebAuthn) as a second login factor next to the authenticator
-- app. Opt-in per account; off until the owner adds one on the 2FA page.
-- cred_id = base64url credential id; public_key = PEM (SPKI from the
-- browser); rp_id = the panel host it was made for (a passkey only works on
-- that exact host); synced = the authenticator reported a synced/backed-up
-- passkey (Google Password Manager, iCloud Keychain, 1Password…).
CREATE TABLE IF NOT EXISTS user_passkeys (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  cred_id VARCHAR(700) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  rp_id VARCHAR(253) NOT NULL,
  public_key TEXT NOT NULL,
  alg SMALLINT NOT NULL,
  sign_count INT UNSIGNED NOT NULL DEFAULT 0,
  synced TINYINT(1) NOT NULL DEFAULT 0,
  name VARCHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME NULL DEFAULT NULL,
  UNIQUE KEY cred (cred_id),
  KEY user (user_id),
  CONSTRAINT fk_passkey_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
