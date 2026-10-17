#!/usr/bin/env bash
# prestashop-install.sh <domain> <owner> <subdir> <db> <dbuser> <dbpass> <title> <user_id> <domain_id> <url> <admin_email> <admin_user> <admin_pass>
# One-click PrestaShop 8.2 (shop) via its CLI installer — database, shop and
# admin account are all created here, no web wizard needed.
# Same contract/guards as flarum-install.sh: DETACHED background, tracking rows
# on success, refuses to clobber, dbpass panel-generated hex, admin 10+ chars.
# Pinned release (version-pinned paths rot; digest verified — supply chain).
# The CLI renames admin/ to adminXXXX and we delete install/ afterwards, so
# the success message carries the real back-office URL (stored nowhere else).
# Runs the CLI as the owner (never root) with the domain's PHP (8.2 enforced
# by the panel — PS 8.2 supports 8.1/8.2, and our pools default to 8.2).
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/secret-args.sh"; secret_argv "$@"; set -- "${SECRET_ARGV[@]}"  # @stdin secrets
. "$(dirname "$(readlink -f "$0")")/db-caps.sh"  # DB_CAPS: per-user MariaDB caps (admin-set)
D="${1:?}"; U="${2:?}"; SUB="${3:-}"; DB="${4:?}"; DBU="${5:?}"; DBP="${6:?}"; TITLE="${7:-Shop}"
UIDN="${8:?}"; DID="${9:?}"; URL="${10:?}"; AM="${11:?}"; AU="${12:?}"; AP="${13:?}"
PS_VER="8.2.8"
PS_URL="https://github.com/PrestaShop/PrestaShop/releases/download/$PS_VER/prestashop_$PS_VER.zip"
PS_SHA="0d6931ed9ecb2636ae8024c5660bb72dc3c5f835738b79d3c1bda984e353598d"
LOG="/var/log/karnakpanel/software.log"
( exec >>"$LOG" 2>&1
  echo "=== $(date -Is) prestashop install for $D${SUB:+/$SUB} ==="
  [[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad domain"; exit 1; }
  [[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad owner"; exit 1; }
  [[ -z "$SUB" ]] || { echo "PrestaShop installs at domain root only (leave folder empty)"; exit 1; }
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
  LEFT="$(ls -A "$DOC" | grep -v -e '^index\.html$' -e '^\.well-known$' -e '^autodiscover$' || true)"
  [[ -z "$LEFT" ]] || { echo "docroot not empty ($DOC) — refusing to overwrite"; exit 1; }
  rm -f "$DOC/index.html"
  command -v unzip >/dev/null 2>&1 || apt-get install -y unzip 2>&1 | tail -1 || { echo "unzip missing"; exit 1; }
  command -v php8.2 >/dev/null 2>&1 || { echo "php8.2 CLI missing"; exit 1; }  # php-ver-ok: PS 8.2 needs PHP <= 8.2 (PS 9 = separate ticket)
  TMP="$(mktemp -d)"
  trap 'rm -rf "$TMP"' EXIT
  curl -fsSL --max-time 600 "$PS_URL" -o "$TMP/ps.zip" || { echo "download failed"; exit 1; }
  echo "$PS_SHA  $TMP/ps.zip" | sha256sum -c - || { echo "checksum mismatch — delete and retry"; exit 1; }
  # outer zip holds the unpacker + inner prestashop.zip (the shop itself):
  # extract inner straight into the docroot, never the outer helpers.
  unzip -q -o "$TMP/ps.zip" "prestashop.zip" -d "$TMP" || { echo "bad archive (no inner zip)"; exit 1; }
  unzip -q -o "$TMP/prestashop.zip" -d "$DOC" || { echo "shop extract failed"; exit 1; }
  [[ -f "$DOC/index.php" && -f "$DOC/install/index_cli.php" ]] || { echo "bad archive layout"; exit 1; }
  rm -f "$DOC/Install_PrestaShop.html"
  mysql -uroot <<<"CREATE DATABASE IF NOT EXISTS \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS '$DBU'@'localhost' IDENTIFIED BY '$DBP'; GRANT ALL PRIVILEGES ON \`$DB\`.* TO '$DBU'@'localhost'; GRANT USAGE ON *.* TO '$DBU'@'localhost' WITH $DB_CAPS; FLUSH PRIVILEGES;"
  # the PS installer writes thousands of rows (schema + fixtures + ~100
  # modules) — the hourly abuse caps would kill it mid-install, so lift them
  # for the run and re-apply straight after (both paths, via recap).
  mysql -uroot -e "ALTER USER '$DBU'@'localhost' WITH MAX_QUERIES_PER_HOUR 0 MAX_UPDATES_PER_HOUR 0 MAX_CONNECTIONS_PER_HOUR 0 MAX_USER_CONNECTIONS 0;" 2>/dev/null || true
  recap() { mysql -uroot -e "GRANT USAGE ON *.* TO '$DBU'@'localhost' WITH $DB_CAPS;" 2>/dev/null || true; }
  chown -R "$U:" "$DOC"
  find "$DOC" -type d -exec chmod 755 {} + 2>/dev/null
  find "$DOC" -type f -exec chmod 644 {} + 2>/dev/null
  if command -v setfacl >/dev/null 2>&1; then setfacl -R -m u:nobody:rX "$DOC" 2>/dev/null || true; fi
  # OLS hardening: deny sensitive paths FIRST (before the generic front
  # controller), idempotent via markers. install/ is deleted below anyway —
  # belt: a failed run must never leave it web-reachable either.
  # FATAL when the vhost is missing (an unprotected shop must never install
  # silently — fix the domain first, then re-run).
  VH="/usr/local/lsws/conf/vhosts/$D/vhost.conf"
  [[ -f "$VH" ]] || { echo "FATAL: no vhost $D — add the domain in the panel first (its web server entry is missing)"; exit 1; }
  if ! grep -q "# prestashop BEGIN" "$VH"; then
  python3 - "$VH" <<'PYEOF'
import sys
p = sys.argv[1]
s = open(p).read()
block = """# prestashop BEGIN (sensitive paths denied — managed)
RewriteRule ^app/ / [F,L]
RewriteRule ^bin/ / [F,L]
RewriteRule ^tools/ / [F,L]
RewriteRule ^docs/ / [F,L]
RewriteRule ^config/ / [F,L]
RewriteRule ^vendor/ / [F,L]
RewriteRule ^src/ / [F,L]
RewriteRule ^var/ / [F,L]
RewriteRule ^download/ / [F,L]
RewriteRule ^upload/ / [F,L]
RewriteRule ^localization/ / [F,L]
RewriteRule ^override/ / [F,L]
RewriteRule ^classes/ / [F,L]
RewriteRule ^controllers/ / [F,L]
RewriteRule ^webservice/ / [F,L]
RewriteRule ^mails/ / [F,L]
RewriteRule ^translations/ / [F,L]
RewriteRule ^pdf/ / [F,L]
RewriteRule ^install/ / [F,L]
RewriteRule ^\\.git / [F,L]
RewriteRule ^composer\\.(lock|json)$ / [F,L]
# prestashop END
"""
anchor = "rules                 <<<END_rules"
assert anchor in s, "rewrite anchor missing"
s = s.replace(anchor, anchor + "\n" + block, 1)
open(p, "w").write(s)
print("prestashop OLS rules installed")
PYEOF
  /usr/local/lsws/bin/lswsctrl restart 2>&1 | tail -1 || true
  fi
  # non-interactive install as the owner (never root — the CLI writes config).
  # newsletter/send_email off (no outbound-mail dependency); ssl follows the
  # visited scheme so shop URLs match from day one.
  SSL="0"; [[ "$URL" == https://* ]] && SSL="1"
  # (marker on its own line: a comment after a trailing \ ends the command)
  PSPHP="php8.2"  # php-ver-ok: PS 8.2 needs PHP <= 8.2 (PS 9 = separate ticket)
  if runuser -u "$U" -- env HOME="/home/$U" "$PSPHP" "$DOC/install/index_cli.php" \
    --domain="$D" --db_server="localhost" --db_name="$DB" --db_user="$DBU" --db_password="$DBP" \
    --db_create=0 --prefix="ps_" --engine="InnoDB" --language="en" --country="gb" \
    --name="$TITLE" --firstname="Store" --lastname="Owner" --password="$AP" --email="$AM" \
    --newsletter=0 --send_email=0 --ssl="$SSL" 2>&1 | tail -25; then
    RC=0
  else
    RC=1
  fi
  recap
  [[ "$RC" == 0 ]] || { echo "prestashop installer failed"; exit 1; }
  # the CLI may rename admin/ to adminXXXX — capture it for the back-office
  # URL (PS 8.2's CLI leaves plain admin/, which is then the BO path), then
  # remove install/ (public installer left behind = game over).
  ADMINDIR="$(ls -d "$DOC"/admin*/ 2>/dev/null | head -1 || true)"
  rm -rf "$DOC/install"
  ADMINPATH="$(basename "${ADMINDIR:-}")"
  # CLI-created files inherit the owner's umask — re-tighten for the worker
  chown -R "$U:" "$DOC"
  find "$DOC" -type f -name "*.php" -exec chmod 640 {} + 2>/dev/null || true
  if command -v setfacl >/dev/null 2>&1; then setfacl -R -m u:nobody:rX "$DOC" 2>/dev/null || true; fi
  mysql -uroot skypanel -e "INSERT INTO mysql_dbs(user_id,db_name,db_user) VALUES($UIDN,'$DB','$DBU');" 2>/dev/null || echo "WARN: db tracking row not recorded"
  mysql -uroot skypanel -e "INSERT INTO site_installs(user_id,domain_id,app,path,url,version) VALUES($UIDN,$DID,'prestashop','$SUB','$URL','$PS_VER');" 2>/dev/null || echo "WARN: install not registered"
  if [[ -n "$ADMINPATH" ]]; then
    echo "installed PrestaShop $PS_VER at $URL (db $DB) — back office: $URL/$ADMINPATH/ (admin: $AU)"
  else
    echo "installed PrestaShop $PS_VER at $URL (db $DB) — admin: $AU (back-office folder lookup failed, see software log)"
  fi
) &
echo "install started in background — watch Logs → software"
