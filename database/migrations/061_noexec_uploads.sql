-- 061: no PHP in upload folders (scripts/vhost-noexec.sh, Domains page).
-- noexec = NOEXEC block in vhost.conf (403 for .php & co under
-- wp-content/uploads, Drupal files, .well-known); noexec_paths = extra
-- comma-separated docroot-relative folders. Existing domains start at 0
-- (their vhosts predate the block); new rows default to 1, matching
-- vhost-add.sh, which gives fresh vhosts the block — same as 060.
ALTER TABLE domains ADD COLUMN IF NOT EXISTS noexec TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE domains ALTER COLUMN noexec SET DEFAULT 1;
ALTER TABLE domains ADD COLUMN IF NOT EXISTS noexec_paths VARCHAR(1400) NOT NULL DEFAULT '';
