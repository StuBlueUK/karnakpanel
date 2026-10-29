#!/usr/bin/env bash
# app-uninstall.sh <install-id> — remove a one-click app's files (runs as root).
# Reads the install from site_installs (never trusts caller paths), prints
# DB=<name> parsed from the app's own config BEFORE deleting (site_installs
# keeps no db column), deletes the files as the owner, strips the managed
# Flarum/PrestaShop/Drupal vhost block. The CONTROLLER drops the database (only a
# mysql_dbs row owned by the same user) and deletes the site_installs row
# after exit 0. Root installs keep .well-known, autodiscover, builder sites
# and other apps' folders, then get the coming-soon page back.
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/as-user.sh"   # customer-tree writes run as the customer
SID="${1:?}"
LOG="/var/log/karnakpanel/software.log"
[[ "$SID" =~ ^[0-9]{1,10}$ ]] || { echo "bad install id"; exit 1; }
Q() { mysql -uroot -N skypanel -e "$1" 2>/dev/null; }
ROW="$(Q "SELECT CONCAT_WS('|', s.app, s.user_id, s.domain_id, d.domain, u.username, IFNULL(s.path,'')) FROM site_installs s JOIN domains d ON d.id=s.domain_id JOIN users u ON u.id=s.user_id WHERE s.id=$SID;")"
[[ -n "$ROW" ]] || { echo "REFUSED: no install $SID"; exit 1; }
IFS='|' read -r APP UIDN DID D U SUB <<< "$ROW"
[[ "$APP" =~ ^(wordpress|phpbb|vvveb|flarum|joomla|prestashop|drupal)$ ]] || { echo "REFUSED: unknown app $APP"; exit 1; }
[[ "$UIDN" =~ ^[0-9]+$ && "$DID" =~ ^[0-9]+$ ]] || { echo "REFUSED: bad install row"; exit 1; }
[[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ && "$D" != *..* && "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "REFUSED: bad install row"; exit 1; }
SUB="${SUB#/}"; SUB="${SUB%/}"
[[ -z "$SUB" || ( "$SUB" =~ ^[A-Za-z0-9._/-]{1,128}$ && "$SUB" != *..* ) ]] || { echo "REFUSED: bad install folder"; exit 1; }
[[ -z "$(Q "SELECT id FROM staging_clones WHERE live_install_id=$SID LIMIT 1;")" ]] || { echo "REFUSED: this site has a staging copy — delete it in the Toolkit first"; exit 1; }

ROOT="/home/$U/$D/public_html"
DOC="$ROOT${SUB:+/$SUB}"
# a planted symlink (folder -> /etc) must never steer a root rm
for p in "/home/$U" "/home/$U/$D" "$ROOT" "$DOC"; do
  [[ ! -L "$p" ]] || { echo "REFUSED: $p is a symlink"; exit 1; }
done
if [[ -d "$DOC" ]]; then
  REAL="$(readlink -f "$DOC")"
  [[ "$REAL" == "$ROOT" || "$REAL" == "$ROOT/"* ]] || { echo "REFUSED: folder escapes the web root"; exit 1; }
fi

# folders that are NOT this app: builder sites and other installs on the domain
KEEP=(".well-known" "autodiscover")
while IFS= read -r k; do [[ -n "$k" ]] && KEEP+=("$k"); done < <(
  Q "SELECT subdir FROM builder_sites WHERE domain_id=$DID AND subdir<>'';"
  Q "SELECT TRIM(BOTH '/' FROM path) FROM site_installs WHERE domain_id=$DID AND id<>$SID AND IFNULL(path,'')<>'';")
if [[ -n "$SUB" ]]; then
  # another install or builder site living INSIDE this folder: refuse
  for k in "${KEEP[@]}"; do
    [[ "$k" == "$SUB/"* ]] && { echo "REFUSED: /$k lives inside this app's folder — remove it first"; exit 1; }
  done
fi

# database name from the app's own config (read before the files go)
# read as the owner: a customer file (or a link planted in its place) is never
# opened by root
cfgval() { as_user "$U" test -f "$1" && as_user "$U" cat -- "$1" 2>/dev/null | grep -m1 -oP "$2" || true; }
case "$APP" in
  wordpress)  DB="$(cfgval "$DOC/wp-config.php" "define\(\s*['\"]DB_NAME['\"]\s*,\s*['\"]\K[^'\"]+")";;
  phpbb)      DB="$(cfgval "$DOC/config.php" "^\\\$dbname\s*=\s*'\K[^']+")";;
  vvveb)      DB="$(cfgval "$DOC/config/db.php" "'database'\s*=>\s*'\K[^']+")";;
  flarum)     DB="$(cfgval "$DOC/config.php" "'database'\s*=>\s*'\K[^']+")";;
  joomla)     DB="$(cfgval "$DOC/configuration.php" "^\s*public \\\$db\s*=\s*'\K[^']+")";;
  prestashop) DB="$(cfgval "$DOC/app/config/parameters.php" "'database_name'\s*=>\s*'\K[^']+")";;
  drupal)     DB="$(cfgval "$DOC/sites/default/settings.php" "^\s*'database'\s*=>\s*'\K[^']+")";;
esac
[[ "$DB" =~ ^[a-z][a-z0-9_]{2,63}$ ]] || DB=""

(
  echo "=== $(date -Is) uninstall $APP #$SID from $D${SUB:+/$SUB} (db: ${DB:-unknown}) ==="
  # Drupal keeps sites/default read-only: the owner could not delete it
  if [[ "$APP" == drupal ]]; then as_user "$U" chmod -R u+w -- "$DOC/sites" 2>/dev/null || true; fi
  if [[ -n "$SUB" ]]; then
    if [[ -d "$DOC" ]]; then as_user "$U" rm -rf --one-file-system -- "$DOC"; fi
    [[ ! -e "$DOC" ]] || { echo "FAILED: $DOC still there"; exit 1; }
  elif [[ -d "$ROOT" ]]; then
    find "$ROOT" -mindepth 1 -maxdepth 1 -print0 | while IFS= read -r -d '' f; do
      n="${f#"$ROOT"/}"; top=1
      for k in "${KEEP[@]}"; do [[ "$n" == "${k%%/*}" ]] && { top=0; break; }; done
      if (( top )); then as_user "$U" rm -rf --one-file-system -- "$f"; fi
    done
    # coming-soon page (same page vhost-add.sh seeds; create-only)
    if ! as_user "$U" test -e "$ROOT/index.html" -o -e "$ROOT/index.php" -o -e "$ROOT/index.htm"; then
      user_put "$U" "$ROOT/index.html" 640 <<EOF || echo "WARN: placeholder page not written"
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>$D — coming soon</title></head>
<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#0b1220,#0ea5e9);font-family:Segoe UI,Arial,sans-serif;color:#e2e8f0">
<div style="text-align:center;background:rgba(11,18,32,.72);border:1px solid #1e3a5f;border-radius:18px;padding:44px 56px;box-shadow:0 20px 60px rgba(0,0,0,.45)">
<svg width="72" height="72" viewBox="0 0 64 64"><rect width="64" height="64" rx="14" fill="#0b3b66"/><circle cx="32" cy="17" r="7" fill="#fbbf24"/><path d="M12 48 L32 24 L52 48 Z" fill="#d4a437"/><path d="M32 24 L52 48 L32 48 Z" fill="#92400e" opacity=".45"/><rect x="10" y="48" width="44" height="4" rx="2" fill="#f6d365" opacity=".85"/></svg>
<h1 style="margin:18px 0 6px">$D</h1>
<p style="color:#94a3b8;margin:0">A new website is on its way.</p>
<p style="margin:22px 0 0;font-size:13px;color:#38bdf8">Hosted with 🏛️ KarnakPanel</p>
</div></body></html>
EOF
      as_user "$U" setfacl -m "$(acl_web r)" -- "$ROOT/index.html" 2>/dev/null || true
    fi
  fi
  VH="/usr/local/lsws/conf/vhosts/$D/vhost.conf"
  if [[ -f "$VH" ]] && grep -q "# $APP BEGIN" "$VH"; then
    sed -i "/# $APP BEGIN/,/# $APP END/d" "$VH"
    /usr/local/lsws/bin/lswsctrl restart 2>&1 | tail -1 || true
  fi
  echo "uninstall COMPLETE"
) >>"$LOG" 2>&1 || { echo "Uninstall failed — see $LOG"; exit 1; }
echo "DB=$DB"
echo "Removed $APP from $D${SUB:+/$SUB}"
