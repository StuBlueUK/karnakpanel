#!/usr/bin/env bash
# flarum-install.sh <domain> <owner> <subdir> <db> <dbuser> <dbpass> <title> <user_id> <domain_id> <url> <admin_email> <admin_user> <admin_pass>
# One-click Flarum 2.x (modern forum) via its CLI installer — database,
# settings and admin account are all created here, no web wizard needed.
# Same contract/guards as vvveb-install.sh: DETACHED background, tracking rows
# on success, refuses to clobber, dbpass panel-generated hex, admin 10+ chars.
# Runs `php flarum install --file` as the owner (never root) with a JSON
# config (deleted afterwards); DB/admin passwords never hit logs.
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/secret-args.sh"; secret_argv "$@"; set -- "${SECRET_ARGV[@]}"  # @stdin secrets
. "$(dirname "$(readlink -f "$0")")/as-user.sh"   # customer-tree writes run as the customer
. "$(dirname "$(readlink -f "$0")")/db-caps.sh"  # DB_CAPS: per-user MariaDB caps (admin-set)
D="${1:?}"; U="${2:?}"; SUB="${3:-}"; DB="${4:?}"; DBU="${5:?}"; DBP="${6:?}"; TITLE="${7:-Flarum}"
UIDN="${8:?}"; DID="${9:?}"; URL="${10:?}"; AM="${11:?}"; AU="${12:?}"; AP="${13:?}"
LOG="/var/log/karnakpanel/software.log"
. "$(dirname "$(readlink -f "$0")")/sw-state.sh"  # Software page progress: ok/fail per target
sw_begin flarum "$D" "$SUB" || exit 1
( ( exec >>"$SW_OUT" 2>&1
  echo "=== $(date -Is) flarum install for $D${SUB:+/$SUB} ==="
  [[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad domain"; exit 1; }
  [[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad owner"; exit 1; }
  [[ -z "$SUB" ]] || { echo "Flarum installs at domain root only (leave folder empty)"; exit 1; }
  [[ "$DB" =~ ^[a-z][a-z0-9_]{2,63}$ ]] || { echo "bad db"; exit 1; }
  [[ "$DBU" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad dbuser"; exit 1; }
  [[ "$DBP" =~ ^[a-f0-9]{12,64}$ ]] || { echo "db password must be panel-generated hex"; exit 1; }
  [[ "$UIDN" =~ ^[0-9]{1,10}$ && "$DID" =~ ^[0-9]{1,10}$ ]] || { echo "bad ids"; exit 1; }
  [[ "$URL" =~ ^https?://[a-z0-9.-]+\.[a-z]{2,}/?$ ]] || { echo "bad url"; exit 1; }
  [[ "$AM" == *"@"* && "$AM" == *"."* && "${#AM}" -le 190 ]] || { echo "bad admin email"; exit 1; }
  [[ "$AU" =~ ^[a-zA-Z0-9_.-]{3,32}$ ]] || { echo "bad admin user"; exit 1; }
  [[ "${#AP}" -ge 10 && "${#AP}" -le 128 ]] || { echo "admin password 10+ chars"; exit 1; }
  id "$U" >/dev/null 2>&1 || { echo "no such user"; exit 1; }
  DOC="/home/$U/$D/public_html"
  [[ -d "/home/$U/$D/public_html" ]] || { echo "no docroot for $D (add the domain first)"; exit 1; }
  LEFT="$(as_user "$U" ls -A -- "$DOC" | grep -v -e '^index\.html$' -e '^\.well-known$' -e '^autodiscover$' || true)"
  [[ -z "$LEFT" ]] || { echo "docroot not empty ($DOC) — refusing to overwrite"; exit 1; }
  as_user "$U" rm -f -- "$DOC/index.html"
  command -v unzip >/dev/null 2>&1 || apt-get install -y unzip 2>&1 | tail -1 || { echo "unzip missing"; exit 1; }
  TMP="$(mktemp -d)"
  trap 'rm -rf "$TMP"' EXIT
  # floating v2.x archives (version-pinned paths rot on every RC): discover the
  # newest build dir via the API, then fetch its php8.3 no-public-dir tarball
  RELDIR="$(curl -fsSL --max-time 30 "https://api.github.com/repos/flarum/installation-packages/contents/packages/v2.x" | python3 -c "import json,sys; ds=[x['name'] for x in json.load(sys.stdin) if x.get('type')=='dir']; print(sorted(ds)[-1])" 2>/dev/null || true)"
  [[ -n "$RELDIR" ]] || { echo "flarum version discovery failed"; exit 1; }
  TGZ="flarum-$RELDIR-no-public-dir-php8.3.tar.gz"  # php-ver-ok: Flarum 2.x CLI on 8.3
  curl -fsSL --max-time 300 "https://raw.githubusercontent.com/flarum/installation-packages/main/packages/v2.x/$RELDIR/$TGZ" -o "$TMP/flarum.tgz" || { echo "download failed"; exit 1; }
  tar tzf "$TMP/flarum.tgz" > "$TMP/list.txt" || { echo "bad archive"; exit 1; }
  grep -qE '(^|/)index\.php$' "$TMP/list.txt" || { echo "bad archive"; exit 1; }
  tar xzf "$TMP/flarum.tgz" -C "$TMP/pkg" 2>/dev/null || { mkdir -p "$TMP/pkg"; tar xzf "$TMP/flarum.tgz" -C "$TMP/pkg"; }
  # strip a single top-level wrapper dir so files land directly in DOC
  if [[ "$(ls -A "$TMP/pkg" | wc -l)" == 1 && -d "$TMP/pkg/$(ls -A "$TMP/pkg")" ]]; then
    user_unpack "$U" "$TMP/pkg/$(ls -A "$TMP/pkg")" "$DOC" || { echo "copy into $DOC failed (disk quota full?)"; exit 1; }
  else
    user_unpack "$U" "$TMP/pkg" "$DOC" || { echo "copy into $DOC failed (disk quota full?)"; exit 1; }
  fi
  [[ -f "$DOC/index.php" && -d "$DOC/vendor" ]] || { echo "bad archive layout"; exit 1; }
  db_new "$DB" "$DBU" "$DBP" || { echo "database $DB or db user $DBU already exists: not reusing it"; exit 1; }
  user_perms "$U" "$DOC"
  # OLS hardening: protection rules FIRST (before the generic front controller),
  # idempotent via markers. Without these, vendor/storage/config go public.
  # FATAL when the vhost is missing (an unprotected Flarum must never install
  # silently — fix the domain first, then re-run).
  VH="/usr/local/lsws/conf/vhosts/$D/vhost.conf"
  [[ -f "$VH" ]] || { echo "FATAL: no vhost $D — add the domain in the panel first (its web server entry is missing)"; exit 1; }
  if ! grep -q "# flarum BEGIN" "$VH"; then
  python3 - "$VH" <<'PYEOF'
import sys
p = sys.argv[1]
s = open(p).read()
block = """# flarum BEGIN (no-public-dir hardening — managed)
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteRule /\\.git / [F,L]
RewriteRule ^auth\\.json$ / [F,L]
RewriteRule ^composer\\.(lock|json)$ / [F,L]
RewriteRule ^config.php$ / [F,L]
RewriteRule ^flarum$ / [F,L]
RewriteRule ^storage/(.*)?$ / [F,L]
RewriteRule ^vendor/(.*)?$ / [F,L]
# flarum END
"""
anchor = "rules                 <<<END_rules"
assert anchor in s, "rewrite anchor missing"
s = s.replace(anchor, anchor + "\n" + block, 1)
open(p, "w").write(s)
print("flarum OLS rules installed")
PYEOF
  /usr/local/lsws/bin/lswsctrl restart 2>&1 | tail -1 || true
  fi
  # non-interactive install as the owner (never root — the CLI writes config +
  # storage). The JSON config MUST be a real file owned by the app user:
  # root-owned temps and inherited pipes are unreadable/unreliable, and an
  # unreadable config silently falls back to default credentials (root).
  # php8.3 matches the panel-enforced domain version for Flarum 2.x.
  command -v php8.3 >/dev/null 2>&1 || { echo "php8.3 CLI missing"; exit 1; }  # php-ver-ok: Flarum 2.x CLI on 8.3
  # JSON-escape every interpolated value (titles/emails can carry quotes)
  J() { printf '%s' "$1" | tr -d '\r\n' | sed 's/\\/\\\\/g; s/"/\\"/g'; }
  JSON="$(cat <<JSONEOF
{
  "debug": false,
  "baseUrl": "$(J "$URL")",
  "databaseConfiguration": {
    "driver": "mariadb",
    "host": "localhost",
    "port": 3306,
    "database": "$(J "$DB")",
    "username": "$(J "$DBU")",
    "password": "$(J "$DBP")",
    "prefix": ""
  },
  "adminUser": {
    "username": "$(J "$AU")",
    "password": "$(J "$AP")",
    "email": "$(J "$AM")"
  },
  "settings": {
    "forum_title": "$(J "$TITLE")"
  }
}
JSONEOF
)"
  JSONF="$DOC/.flarum-install.json"
  printf '%s' "$JSON" | user_put "$U" "$JSONF" 600 || { echo "install config write failed"; exit 1; }
  if as_user "$U" env HOME="/home/$U" php8.3 "$DOC/flarum" install --file "$JSONF" 2>&1 | tail -3; then  # php-ver-ok: Flarum 2.x CLI on 8.3
    RC=0
  else
    RC=1
  fi
  as_user "$U" rm -f -- "$JSONF"
  [[ "$RC" == 0 ]] || { echo "flarum installer failed"; exit 1; }
  # CLI-created files inherit the owner's umask — re-tighten for the worker
  as_user "$U" find "$DOC" -type f -name "*.php" -exec chmod 640 {} + 2>/dev/null || true
  if command -v setfacl >/dev/null 2>&1; then as_user "$U" setfacl -R -P -m "$(acl_web rX)" -- "$DOC" 2>/dev/null || true; fi
  mysql -uroot skypanel -e "INSERT INTO mysql_dbs(user_id,db_name,db_user) VALUES($UIDN,'$DB','$DBU');" 2>/dev/null || echo "WARN: db tracking row not recorded"
  mysql -uroot skypanel -e "INSERT INTO site_installs(user_id,domain_id,app,path,url,version) VALUES($UIDN,$DID,'flarum','$SUB','$URL','$RELDIR');" 2>/dev/null || echo "WARN: install not registered"
  echo "installed Flarum $RELDIR at $URL (db $DB) — admin: $AU"
) & sw_wait $! ) >/dev/null 2>&1 &
echo "install started in background — watch Logs → software"
