-- 017: hash stored API tokens (existing plaintext rows hashed in place once).
-- SINGLE-RUN ONLY: hashed values are still 64-hex, so a replay would
-- double-hash and break every token. Never replay manually; the tracker
-- (applied_migrations, seeded by the installer) runs it exactly once.
UPDATE api_tokens SET token=SHA2(token, 256) WHERE LENGTH(token)=64 AND token REGEXP '^[a-f0-9]{64}$';
