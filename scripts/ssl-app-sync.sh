#!/usr/bin/env bash
# ssl-app-sync.sh <domain> — switch installed web apps from http:// to https://
# after a certificate is issued. Apps store their own base URL and fight the
# server's force-HTTPS redirect into a loop (first seen: PrestaShop, Oct 2026).
# Runs automatically at the end of ssl-issue.sh on success; also safe to run
# by hand any time. IDEMPOTENT (only http:// values are touched) and BEST
# EFFORT (exit 0 always — it must never fail a certificate issuance).
# Adding an app = new marker-guarded block below (never touch existing ones).
# SECURITY: the docroot is customer-controlled. Never execute (php require)
# or write its files as root — read/write AS THE SITE OWNER (asu), and only
# touch databases the owner holds in mysql_dbs (db_mine), never one a config
# file merely names.
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/as-user.sh"
cd /
D="${1:?}"
[[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || exit 0
VH="/usr/local/lsws/conf/vhosts/$D/vhost.conf"
[[ -f "$VH" ]] || exit 0
DOC="$(grep -E '^docRoot' "$VH" | awk '{print $2}' | head -1)"
DOC="${DOC%/}"
[[ "$DOC" =~ ^/home/([a-z][a-z0-9_]{2,31})/[a-zA-Z0-9_./-]+$ && "$DOC" != *..* ]] || exit 0
OWN="${BASH_REMATCH[1]}"
[[ "$OWN" != root ]] && (( $(id -u "$OWN" 2>/dev/null || echo 0) >= 1000 )) || exit 0
asu() { as_user "$OWN" "$@"; }
rd() { asu cat -- "$1" 2>/dev/null || true; }   # read a docroot file as the owner
db_mine() {  # <db> — the owner's own database (installers record it in mysql_dbs)
  [[ "$1" =~ ^[a-z][a-z0-9_]{2,63}$ ]] || return 1
  mysql -uroot -N skypanel -e "SELECT 1 FROM mysql_dbs m JOIN users u ON u.id=m.user_id WHERE u.username='$OWN' AND m.db_name='$1' LIMIT 1" 2>/dev/null | grep -q .
}
asu test -d "$DOC" || exit 0
# --- WordPress (wp-config.php; tables exist only after the browser wizard) ---
if [[ -f "$DOC/wp-config.php" && -f "$DOC/wp-includes/version.php" ]]; then
  WPC="$(rd "$DOC/wp-config.php")"
  PFX="$(grep -oE "\\\$table_prefix *= *'[^']+'" <<< "$WPC" | head -1 | cut -d"'" -f2 || true)"
  [[ "$PFX" =~ ^[a-zA-Z0-9_]{1,20}$ ]] || PFX="wp_"
  # which database? read it from the app's own config (never the panel DB)
  WDB="$(grep -oP "define\(\s*'DB_NAME'\s*,\s*'\K[^']+" <<< "$WPC" 2>/dev/null | head -1 || true)"
  [[ -n "$WDB" ]] || WDB="$(grep -oP 'define\(\s*"DB_NAME"\s*,\s*"\K[^"]+' <<< "$WPC" 2>/dev/null | head -1 || true)"
  if db_mine "$WDB" && mysql -uroot -N -e "SHOW TABLES FROM \`$WDB\` LIKE '${PFX}options'" 2>/dev/null | grep -q .; then
    if mysql -uroot -N -e "SELECT option_value FROM \`$WDB\`.\`${PFX}options\` WHERE option_name IN ('siteurl','home') AND option_value LIKE 'http://%';" 2>/dev/null | grep -q .; then
      mysql -uroot -e "UPDATE \`$WDB\`.\`${PFX}options\` SET option_value=REPLACE(option_value,'http://','https://') WHERE option_name IN ('siteurl','home') AND option_value LIKE 'http://%';" 2>/dev/null && echo "wordpress: siteurl/home -> https"
    fi
  else
    echo "wordpress: tables not installed yet (browser wizard pending) — skipped"
  fi
fi
# --- PrestaShop (app/config/parameters.php; proven Oct 2026) ---
if [[ -f "$DOC/app/config/parameters.php" && -f "$DOC/config/defines.inc.php" ]]; then
  # parsed, never executed: 'database_name' => 'x' (either quote style)
  PDB="$(rd "$DOC/app/config/parameters.php" | grep -oP "['\"]database_name['\"]\s*=>\s*['\"]\K[a-z0-9_]+" | head -1 || true)"
  if db_mine "$PDB"; then
    CHANGED=""
    if mysql -uroot -N -e "SHOW TABLES LIKE 'ps_configuration'" "$PDB" 2>/dev/null | grep -q .; then
      if mysql -uroot -N -e "SELECT value FROM \`$PDB\`.ps_configuration WHERE name IN ('PS_SSL_ENABLED','PS_SSL_ENABLED_EVERYWHERE') AND value!='1';" 2>/dev/null | grep -q .; then
        mysql -uroot -e "UPDATE \`$PDB\`.ps_configuration SET value='1' WHERE name IN ('PS_SSL_ENABLED','PS_SSL_ENABLED_EVERYWHERE') AND value!='1';" 2>/dev/null || true
        echo "prestashop: SSL flags on"; CHANGED=1
      fi
    else
      for T in $(mysql -uroot -N -e "SHOW TABLES LIKE '%configuration'" "$PDB" 2>/dev/null || true); do
        if mysql -uroot -N -e "SELECT value FROM \`$PDB\`.\`$T\` WHERE name IN ('PS_SSL_ENABLED','PS_SSL_ENABLED_EVERYWHERE') AND value!='1';" 2>/dev/null | grep -q .; then
          mysql -uroot -e "UPDATE \`$PDB\`.\`$T\` SET value='1' WHERE name IN ('PS_SSL_ENABLED','PS_SSL_ENABLED_EVERYWHERE') AND value!='1';" 2>/dev/null || true
          echo "prestashop: SSL flags on (non-default prefix)"; CHANGED=1
        fi
      done
    fi
    if [[ -n "$CHANGED" ]]; then asu find "$DOC/var/cache" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} + 2>/dev/null || true; echo "prestashop: cache cleared"; fi
  fi
fi
# --- Flarum (config.php baseUrl, either quote style) ---
if [[ -f "$DOC/config.php" && -d "$DOC/vendor/flarum" ]]; then
  if rd "$DOC/config.php" | grep -qE "['\"]url['\"] *=> *['\"]http://"; then
    asu python3 -I - "$DOC/config.php" <<'PYEOF' 2>/dev/null || true
import re, sys
p = sys.argv[1]
s = open(p).read()
s2 = re.sub(r"(['\"]url['\"]\s*=>\s*['\"])http://", r"\1https://", s)
if s2 != s:
    open(p, "w").write(s2)
    print("flarum: baseUrl -> https")
PYEOF
  fi
fi
# --- phpBB (config.php + phpbb_config; fixed phpbb_ prefix per our installer) ---
if [[ -f "$DOC/config.php" && -f "$DOC/includes/constants.php" ]]; then
  BDB="$(rd "$DOC/config.php" | grep -oP "\\\$dbname\s*=\s*['\"]\K[a-z0-9_]+" | head -1 || true)"
  if db_mine "$BDB"; then
    if mysql -uroot -N -e "SHOW TABLES FROM \`$BDB\` LIKE 'phpbb_config'" 2>/dev/null | grep -q .; then
      if mysql -uroot -N -e "SELECT config_value FROM \`$BDB\`.phpbb_config WHERE (config_name='server_protocol' AND config_value!='https://') OR (config_name='cookie_secure' AND config_value!='1');" 2>/dev/null | grep -q .; then
        mysql -uroot -e "UPDATE \`$BDB\`.phpbb_config SET config_value='https://' WHERE config_name='server_protocol' AND config_value!='https://'; UPDATE \`$BDB\`.phpbb_config SET config_value='1' WHERE config_name='cookie_secure' AND config_value!='1';" 2>/dev/null || true
        echo "phpbb: server_protocol https + secure cookies"
      fi
    fi
  fi
fi
# NOTE: Vvveb has no verified URL store yet (its settings live outside the
# markers above) — re-save the site URL in its admin after SSL. Register it
# here once the store is mapped, following the blocks above.
exit 0
