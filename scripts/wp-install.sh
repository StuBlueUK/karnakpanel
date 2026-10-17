#!/usr/bin/env bash
# wp-install.sh <domain> <owner> <subdir> <db> <dbuser> <dbpass> <title> <user_id> <domain_id> <url>
# One-click WordPress into a docroot subdir (empty = domain root). Finish by
# visiting /wp-admin (web installer creates the admin account there).
# DETACHED: downloads take longer than the panel sudo timeout, so all work
# backgrounds into software.log. Tracking rows (mysql_dbs, site_installs) are
# written HERE on success (panel pre-validates only) — a failed install leaves
# no DB residue. GUARDED: identifiers strict, dbpass panel-generated hex,
# docroot must be empty (or stock index.html only).
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/secret-args.sh"; secret_argv "$@"; set -- "${SECRET_ARGV[@]}"  # @stdin secrets
. "$(dirname "$(readlink -f "$0")")/db-caps.sh"  # DB_CAPS: per-user MariaDB caps (admin-set)
D="${1:?}"; U="${2:?}"; SUB="${3:-}"; DB="${4:?}"; DBU="${5:?}"; DBP="${6:?}"; TITLE="${7:-WordPress}"
UIDN="${8:?}"; DID="${9:?}"; URL="${10:?}"
LOG="/var/log/karnakpanel/software.log"
( exec >>"$LOG" 2>&1
  echo "=== $(date -Is) wordpress install for $D${SUB:+/$SUB} ==="
[[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad domain"; exit 1; }
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad owner"; exit 1; }
[[ "$SUB" =~ ^[a-zA-Z0-9_-]{0,64}$ ]] || { echo "bad subdir"; exit 1; }
[[ "$DB" =~ ^[a-z][a-z0-9_]{2,63}$ ]] || { echo "bad db"; exit 1; }
[[ "$DBU" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad dbuser"; exit 1; }
  [[ "$DBP" =~ ^[a-f0-9]{12,64}$ ]] || { echo "db password must be panel-generated hex"; exit 1; }
  [[ "$UIDN" =~ ^[0-9]{1,10}$ && "$DID" =~ ^[0-9]{1,10}$ ]] || { echo "bad ids"; exit 1; }
  [[ "$URL" =~ ^https?://[a-z0-9.-]+\.[a-z]{2,}(/[a-z0-9_-]{1,64})?/?$ ]] || { echo "bad url"; exit 1; }
id "$U" >/dev/null 2>&1 || { echo "no such user"; exit 1; }
DOC="/home/$U/$D/public_html${SUB:+/$SUB}"
[[ -d "/home/$U/$D/public_html" ]] || { echo "no docroot for $D (add the domain first)"; exit 1; }
mkdir -p "$DOC"
# refuse to clobber a real site: only empty dirs or the stock placeholder
LEFT="$(ls -A "$DOC" | grep -v -e '^index\.html$' -e '^\.well-known$' -e '^autodiscover$' || true)"
[[ -z "$LEFT" ]] || { echo "docroot not empty ($DOC) — refusing to overwrite"; exit 1; }
rm -f "$DOC/index.html"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
curl -fsSL --max-time 180 https://wordpress.org/latest.tar.gz -o "$TMP/wp.tgz" || { echo "download failed"; exit 1; }
  # list to a file first: piping tar into head/grep -q SIGPIPEs under pipefail
  tar tzf "$TMP/wp.tgz" > "$TMP/list.txt" || { echo "bad tarball"; exit 1; }
  grep -q '^wordpress/' "$TMP/list.txt" || { echo "bad tarball"; exit 1; }
tar xzf "$TMP/wp.tgz" -C "$TMP"
cp -a "$TMP"/wordpress/. "$DOC/"
SALT="$(curl -fsSL --max-time 20 https://api.wordpress.org/secret-key/1.1/salt/ 2>/dev/null || for k in AUTH_KEY SECURE_AUTH_KEY LOGGED_IN_KEY NONCE_KEY AUTH_SALT SECURE_AUTH_SALT LOGGED_IN_SALT NONCE_SALT; do echo "define( '$k', '$(openssl rand -hex 32)' );"; done)"
export SALT
mysql -uroot <<<"CREATE DATABASE IF NOT EXISTS \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS '$DBU'@'localhost' IDENTIFIED BY '$DBP'; GRANT ALL PRIVILEGES ON \`$DB\`.* TO '$DBU'@'localhost'; GRANT USAGE ON *.* TO '$DBU'@'localhost' WITH $DB_CAPS; FLUSH PRIVILEGES;"
ESCDBP="${DBP//\'/\\\'}"
sed -e "s/database_name_here/$DB/" -e "s/username_here/$DBU/" -e "s/password_here/$ESCDBP/" "$DOC/wp-config-sample.php" > "$DOC/wp-config.php"
# swap the placeholder salt block for fresh salts (insert before stop-editing line)
/usr/bin/perl -i -pe "s{/\* That's all, stop editing!.*?\*/}{\$ENV{SALT}\n\$&}s" "$DOC/wp-config.php"
sed -i "/#@+/,/#@-/d" "$DOC/wp-config.php"
  chown -R "$U:" "$DOC"
  find "$DOC" -type d -exec chmod 755 {} + 2>/dev/null
  find "$DOC" -type f -exec chmod 644 {} + 2>/dev/null
  chmod 640 "$DOC/wp-config.php"
  if command -v setfacl >/dev/null 2>&1; then setfacl -R -m u:nobody:rX "$DOC" 2>/dev/null || true; fi
  mysql -uroot skypanel -e "INSERT INTO mysql_dbs(user_id,db_name,db_user) VALUES($UIDN,'$DB','$DBU');" 2>/dev/null || echo "WARN: db tracking row not recorded"
  mysql -uroot skypanel -e "INSERT INTO site_installs(user_id,domain_id,app,path,url,version) VALUES($UIDN,$DID,'wordpress','$SUB','$URL','ready');" 2>/dev/null || echo "WARN: install not registered"
  echo "installed WordPress at $URL (db $DB) — finish at $URL/wp-admin"
) &
echo "install started in background — watch Logs → software"
