#!/usr/bin/env bash
# vvveb-install.sh <domain> <owner> <subdir> <db> <dbuser> <dbpass> <title> <user_id> <domain_id> <url> <admin_email> <admin_user> <admin_pass>
# One-click Vvveb CMS (website builder) via its CLI installer — database,
# site and admin account are all created here, no web wizard needed.
# Same contract/guards as wp-install.sh: DETACHED background, tracking rows on
# success, refuses to clobber, dbpass panel-generated hex.
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/secret-args.sh"; secret_argv "$@"; set -- "${SECRET_ARGV[@]}"  # @stdin secrets
. "$(dirname "$(readlink -f "$0")")/as-user.sh"   # customer-tree writes run as the customer
. "$(dirname "$(readlink -f "$0")")/db-caps.sh"  # DB_CAPS: per-user MariaDB caps (admin-set)
. "$(dirname "$(readlink -f "$0")")/php-versions.sh"
D="${1:?}"; U="${2:?}"; SUB="${3:-}"; DB="${4:?}"; DBU="${5:?}"; DBP="${6:?}"; TITLE="${7:-Vvveb}"
UIDN="${8:?}"; DID="${9:?}"; URL="${10:?}"; AM="${11:?}"; AU="${12:?}"; AP="${13:?}"
LOG="/var/log/karnakpanel/software.log"
. "$(dirname "$(readlink -f "$0")")/sw-state.sh"  # Software page progress: ok/fail per target
sw_begin vvveb "$D" "$SUB" || exit 1
( ( exec >>"$SW_OUT" 2>&1
  echo "=== $(date -Is) vvveb install for $D${SUB:+/$SUB} ==="
  [[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad domain"; exit 1; }
  [[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad owner"; exit 1; }
  [[ "$SUB" =~ ^[a-zA-Z0-9_-]{0,64}$ ]] || { echo "bad subdir"; exit 1; }
  # Vvveb theme paths break under subdirectories (upstream limitation, see docs)
  # — domain root installs only.
  [[ -z "$SUB" ]] || { echo "Vvveb installs at domain root only (leave folder empty)"; exit 1; }
  [[ "$DB" =~ ^[a-z][a-z0-9_]{2,63}$ ]] || { echo "bad db"; exit 1; }
  [[ "$DBU" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad dbuser"; exit 1; }
  [[ "$DBP" =~ ^[a-f0-9]{12,64}$ ]] || { echo "db password must be panel-generated hex"; exit 1; }
  [[ "$UIDN" =~ ^[0-9]{1,10}$ && "$DID" =~ ^[0-9]{1,10}$ ]] || { echo "bad ids"; exit 1; }
  [[ "$URL" =~ ^https?://[a-z0-9.-]+\.[a-z]{2,}(/[a-z0-9_-]{1,64})?/?$ ]] || { echo "bad url"; exit 1; }
  [[ "$AM" == *"@"* && "$AM" == *"."* && "${#AM}" -le 190 ]] || { echo "bad admin email"; exit 1; }
  [[ "$AU" =~ ^[a-zA-Z0-9_.-]{3,32}$ ]] || { echo "bad admin user"; exit 1; }
  [[ "${#AP}" -ge 10 && "${#AP}" -le 128 ]] || { echo "admin password 10+ chars"; exit 1; }
  id "$U" >/dev/null 2>&1 || { echo "no such user"; exit 1; }
  DOC="/home/$U/$D/public_html${SUB:+/$SUB}"
  [[ -d "/home/$U/$D/public_html" ]] || { echo "no docroot for $D (add the domain first)"; exit 1; }
  as_user "$U" mkdir -p -- "$DOC" || { echo "can't create $DOC"; exit 1; }
  LEFT="$(as_user "$U" ls -A -- "$DOC" | grep -v -e '^index\.html$' -e '^\.well-known$' -e '^autodiscover$' || true)"
  [[ -z "$LEFT" ]] || { echo "docroot not empty ($DOC) — refusing to overwrite"; exit 1; }
  as_user "$U" rm -f -- "$DOC/index.html"
  command -v unzip >/dev/null 2>&1 || apt-get install -y unzip 2>&1 | tail -1 || { echo "unzip missing"; exit 1; }
  TMP="$(mktemp -d)"
  trap 'rm -rf "$TMP"' EXIT
  curl -fsSL --max-time 180 https://github.com/givanz/Vvveb/releases/latest/download/latest.zip -o "$TMP/vvveb.zip" || { echo "download failed"; exit 1; }
  unzip -l "$TMP/vvveb.zip" > "$TMP/list.txt" || { echo "bad archive"; exit 1; }
  grep -qE 'index\.php|cli\.php' "$TMP/list.txt" || { echo "bad archive"; exit 1; }
  unzip -o -q "$TMP/vvveb.zip" -d "$TMP/pkg" 2>&1 | tail -1 || { echo "extract failed"; exit 1; }
  # strip a single top-level wrapper dir so files land directly in DOC
  if [[ "$(ls -A "$TMP/pkg" | wc -l)" == 1 && -d "$TMP/pkg/$(ls -A "$TMP/pkg")" ]]; then
    user_unpack "$U" "$TMP/pkg/$(ls -A "$TMP/pkg")" "$DOC" || { echo "copy into $DOC failed (disk quota full?)"; exit 1; }
  else
    user_unpack "$U" "$TMP/pkg" "$DOC" || { echo "copy into $DOC failed (disk quota full?)"; exit 1; }
  fi
  db_new "$DB" "$DBU" "$DBP" || { echo "database $DB or db user $DBU already exists: not reusing it"; exit 1; }
  SUBARG="/${SUB}"; [[ -z "$SUB" ]] && SUBARG="/"
  # CLI installer runs as the owner (never root — it writes config + storage)
  as_user "$U" "php$PHP_DEFAULT" "$DOC/cli.php" install module=index host=127.0.0.1 engine=mysqli "user=$DBU" "password=$DBP" "database=$DB" "admin[email]=$AM" "admin[username]=$AU" "admin[password]=$AP" "subdir=$SUBARG" 2>&1 | tail -3 || { echo "vvveb installer failed"; exit 1; }
  # sample data ships well-known demo logins — remove every backend user but
  # ours, and only if ours actually exists (never leave a site admin-less)
  OURS="$(mysql -uroot -N -e "SELECT COUNT(*) FROM \`$DB\`.admin WHERE username='$AU';" 2>/dev/null || echo 0)"
  if [[ "$OURS" -ge 1 ]]; then
    mysql -uroot -e "DELETE FROM \`$DB\`.admin WHERE username <> '$AU';" 2>/dev/null || true
  else
    echo "WARN: our admin missing — demo accounts left alone, check the install"
  fi
  # production hygiene: the shipped env.php enables DEBUG (verbose error log)
  as_user "$U" sed -i "s/defined('DEBUG') || define('DEBUG', true);/defined('DEBUG') || define('DEBUG', false);/" "$DOC/env.php" 2>/dev/null || true
  # root installs: CLI writes V_SUBDIR_INSTALL='/' which double-slashes every
  # public path (//public/...) — fall back to auto-detect instead
  if [[ -z "$SUB" ]]; then
    as_user "$U" sed -i "s|^defined('V_SUBDIR_INSTALL').*|defined('V_SUBDIR_INSTALL') \|\| define('V_SUBDIR_INSTALL', '');|" "$DOC/env.php" 2>/dev/null || true
  fi
  user_perms "$U" "$DOC"
  mysql -uroot skypanel -e "INSERT INTO mysql_dbs(user_id,db_name,db_user) VALUES($UIDN,'$DB','$DBU');" 2>/dev/null || echo "WARN: db tracking row not recorded"
  mysql -uroot skypanel -e "INSERT INTO site_installs(user_id,domain_id,app,path,url,version) VALUES($UIDN,$DID,'vvveb','$SUB','$URL','ready');" 2>/dev/null || echo "WARN: install not registered"
  echo "installed Vvveb at $URL (db $DB) — admin: $AU"
) & sw_wait $! ) >/dev/null 2>&1 &
echo "install started in background — watch Logs → software"
