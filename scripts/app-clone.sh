#!/usr/bin/env bash
# app-clone.sh <app> <live-domain> <staging-domain> <owner> <php-version>
#   <live-db> <staging-db> <staging-dbuser> <staging-dbpass> <live-url>
#   <staging-url> <user-id> <live-install-id> <staging-domain-id> <owner-email>
#   <clone-id> — build a quarantined staging copy of a panel-installed app.
# Runs as root. DETACHED: big trees exceed the panel sudo timeout — the body
# backgrounds into apptoolkit.log; the parent returns at once. On success the
# script flips staging_clones.status to ready (controller pre-inserts the row
# as cloning + the domains/dns rows the zone render needs). On failure it
# removes what it created (staging DB, files, vhost) and leaves the row as
# cloning for the page to report; the bare staging domain row stays for the
# Domains page. v1: wordpress only (others REFUSED — v1.1 packs).
set -uo pipefail
. "$(dirname "$(readlink -f "$0")")/secret-args.sh"; secret_argv "$@"; set -- "${SECRET_ARGV[@]}"  # @stdin secrets
. "$(dirname "$(readlink -f "$0")")/as-user.sh"   # both docroots are the customer's: touch them as the owner
APP="${1:?}"; LIVE="${2:?}"; STG="${3:?}"; U="${4:?}"; PHPV="${5:?}"
LDB="${6:?}"; SDB="${7:?}"; SDBU="${8:?}"; SDBP="${9:?}"; LURL="${10:?}"
SURL="${11:?}"; UIDN="${12:?}"; LIID="${13:?}"; SDID="${14:?}"
OEMAIL="${15:?}"; CLID="${16:?}"
LOG="/var/log/karnakpanel/apptoolkit.log"
[[ "$APP" == "wordpress" ]] || { echo "REFUSED: staging for $APP is coming soon (WordPress only right now)"; exit 1; }
[[ "$LIVE" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad live domain"; exit 1; }
[[ "$STG" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad staging domain"; exit 1; }
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad owner"; exit 1; }
[[ "$PHPV" =~ ^[0-9]\.[0-9]$ ]] || { echo "bad php"; exit 1; }
[[ "$LDB" =~ ^[a-z][a-z0-9_]{2,63}$ ]] || { echo "bad live db"; exit 1; }
[[ "$SDB" =~ ^[a-z][a-z0-9_]{2,63}$ ]] || { echo "bad staging db"; exit 1; }
[[ "$SDBU" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad staging dbuser"; exit 1; }
[[ "$SDBP" =~ ^[a-f0-9]{24}$ ]] || { echo "bad staging dbpass"; exit 1; }
[[ "$LURL" =~ ^https?://[A-Za-z0-9.-]+\.[A-Za-z]{2,}(/.*)?$ ]] || { echo "bad live url"; exit 1; }
[[ "$SURL" =~ ^https?://[A-Za-z0-9.-]+\.[A-Za-z]{2,}(/.*)?$ ]] || { echo "bad staging url"; exit 1; }
[[ "$UIDN" =~ ^[0-9]{1,10}$ && "$LIID" =~ ^[0-9]{1,10}$ && "$SDID" =~ ^[0-9]{1,10}$ && "$CLID" =~ ^[0-9]{1,10}$ ]] || { echo "bad ids"; exit 1; }
OEMAIL="${OEMAIL//\'/}"
[[ "$OEMAIL" =~ ^[^@]+@[^@]+\.[a-zA-Z]{2,}$ ]] || { echo "bad owner email"; exit 1; }
LDOC="/home/$U/$LIVE/public_html"; SDOC="/home/$U/$STG/public_html"
# the live DB name is read from a customer-editable wp-config, then dumped as
# root: it must be one of THIS owner's databases, never another account's
[[ -n "$(mysql -uroot -N skypanel -e "SELECT 1 FROM mysql_dbs WHERE user_id=$UIDN AND db_name='$LDB' LIMIT 1;" 2>/dev/null)" ]] || { echo "REFUSED: live DB $LDB is not one of $U's databases"; exit 1; }
# the staging db + user are DROPPED and re-created below: they must be free on
# the server or already this owner's (a refresh) — never another account's
ownq() { mysql -uroot -N skypanel -e "SELECT 1 FROM mysql_dbs WHERE user_id=$UIDN AND $1 LIMIT 1;" 2>/dev/null; }
[[ -z "$(mysql -uroot -N skypanel -e "SELECT 1 FROM mysql_dbs WHERE user_id<>$UIDN AND (db_name='$SDB' OR db_user='$SDBU') LIMIT 1;" 2>/dev/null)" ]] || { echo "REFUSED: staging db $SDB / $SDBU belongs to another account"; exit 1; }
if [[ -n "$(mysql -uroot -N -e "SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$SDB'" 2>/dev/null)" && -z "$(ownq "db_name='$SDB'")" ]] \
   || [[ -n "$(mysql -uroot -N -e "SELECT 1 FROM mysql.user WHERE User='$SDBU' LIMIT 1" 2>/dev/null)" && -z "$(ownq "db_user='$SDBU'")" ]]; then
  echo "REFUSED: staging db $SDB / user $SDBU already exists and is not one of $U's"; exit 1
fi
# live may be a subdir install (path column); staging is always domain root
SUB="$(mysql -uroot -N skypanel -e "SELECT path FROM site_installs WHERE id=$LIID AND user_id=$UIDN;" 2>/dev/null || true)"
[[ "$SUB" == "NULL" ]] && SUB=""
SUB="${SUB#/}"; SUB="${SUB%/}"
[[ "$SUB" =~ ^[a-zA-Z0-9_-]{0,64}$ ]] || { echo "bad live subdir"; exit 1; }
[[ -n "$SUB" ]] && LDOC="$LDOC/$SUB"
as_user "$U" test -f "$LDOC/wp-config.php" || { echo "REFUSED: $LDOC is not a WordPress site (no wp-config.php)"; exit 1; }
# re-runs (refresh) land on our own copy: same staging DB means ours, anything
# else means someone's real site lives there — refuse instead of paving it.
if as_user "$U" test -f "$SDOC/wp-config.php"; then
  HAVE="$(as_user "$U" cat -- "$SDOC/wp-config.php" | grep -oE "define\( *'DB_NAME', *'[^']+'" | grep -oE "'[^']+'$" | tr -d "'" || true)"
  [[ "$HAVE" == "$SDB" ]] || { echo "REFUSED: $SDOC already holds a foreign WordPress copy — destroy the old staging first"; exit 1; }
fi
PREFIX="$(as_user "$U" cat -- "$LDOC/wp-config.php" | grep -oE "\\\$table_prefix *= *'[^']+'" | grep -oE "'[^']+'$" | tr -d "'" || true)"
[[ "$PREFIX" =~ ^[A-Za-z0-9_]{1,20}$ ]] || PREFIX="wp_"
# a one-click WordPress whose browser setup was never finished has files but
# no tables: refuse here, before anything is built (the copy would die later)
[[ -n "$(mysql -uroot -N -e "SELECT 1 FROM \`$LDB\`.\`${PREFIX}options\` WHERE option_name='siteurl' LIMIT 1;" 2>/dev/null)" ]] \
  || { echo "REFUSED: finish the WordPress setup on $LIVE${SUB:+/$SUB} first (its database has no site yet)"; exit 1; }
URLR="$(readlink -f /usr/local/skypanel/panel)/cli/url-replace.php"
[[ -x "$URLR" ]] || { echo "REFUSED: url-replace.php missing ($URLR) — panel update needed"; exit 1; }
echo "clone $LIVE -> $STG started — watch Logs (apptoolkit)"
( exec >>"$LOG" 2>&1
  echo "=== $(date -Is) app-clone $APP $LIVE -> $STG ==="
  T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT   # root-only build dir for staged files
  # SQL that carries a password goes on stdin (never argv); the MariaDB client
  # echoes a failing statement to stderr in full, so only its ERROR line, with
  # both passwords masked, reaches the log
  sqlq() { local e; e="$(mysql -uroot "$@" 2>&1 >/dev/null)" && return 0
    grep '^ERROR' <<<"$e" | sed "s/$SDBP/<hidden>/g${SAP:+; s/$SAP/<hidden>/g}" || true; return 1; }
  fail() { echo "FAILED: $1";
    mysql -uroot skypanel -e "DELETE FROM staging_clones WHERE id=$CLID;" 2>/dev/null || true
    mysql -uroot -e "DROP DATABASE IF EXISTS \`$SDB\`; DROP USER IF EXISTS '$SDBU'@'localhost';" 2>/dev/null || true
    "$(dirname "$0")/vhost-remove.sh" "$STG" "$U" >/dev/null 2>&1 || true
    exit 1; }
  # disk: refuse rather than half-copy (need ~2x the tree free)
  NEED="$(du -sb "$LDOC" 2>/dev/null | awk '{print $1}')"
  FREE="$(df -B1 /home 2>/dev/null | awk 'NR==2{print $4}')"
  [[ -n "$NEED" && -n "$FREE" && "$FREE" -gt $((NEED * 2)) ]] || fail "disk too tight for a full copy (need ~$((NEED * 2 / 1048576))MB free)"
  # HTTP lock first: the realm in vhost.conf points at $VH/htpasswd, so the
  # file must exist BEFORE the vhost-add restart or OLS refuses the config.
  SAU="staging"; SAP="$(openssl rand -base64 12 | tr -dc 'a-zA-Z0-9' | head -c 16)"
  SAH="$(printf '%s\n' "$SAP" | openssl passwd -apr1 -stdin)"
  [[ "$SAH" == '$apr1$'* ]] || fail "lock password hash failed"
  VH="/usr/local/lsws/conf/vhosts/$STG"
  mkdir -p "$VH"
  printf '%s:%s\n' "$SAU" "$SAH" > "$VH/htpasswd"; chmod 644 "$VH/htpasswd"
  STAGING_REALM=1 "$(dirname "$0")/vhost-add.sh" "$STG" "$U" "$PHPV" >>"$LOG" 2>&1 || fail "vhost-add failed"
  as_user "$U" cp -a -- "$LDOC/." "$SDOC/" || fail "file copy failed (disk quota full?)"
  as_user "$U" test -f "$SDOC/index.php" && as_user "$U" rm -f -- "$SDOC/index.html"
  sqlq <<<"DROP DATABASE IF EXISTS \`$SDB\`; CREATE DATABASE \`$SDB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS '$SDBU'@'localhost' IDENTIFIED BY '$SDBP'; ALTER USER '$SDBU'@'localhost' IDENTIFIED BY '$SDBP'; GRANT ALL PRIVILEGES ON \`$SDB\`.* TO '$SDBU'@'localhost'; FLUSH PRIVILEGES;" || fail "staging database failed"
  mysqldump -uroot "$LDB" 2>>"$LOG" | php "$URLR" "$LURL" "$SURL" 2>>"$LOG" | mysql -uroot "$SDB" 2>>"$LOG" || fail "database copy failed"
  # staging config: own creds + locked URL + dormant cron
  # edited in the root build dir (sed script from a file: the password never hits argv), written back as the owner
  as_user "$U" cat -- "$SDOC/wp-config.php" > "$T/wpc" || fail "cannot read staging wp-config"
  printf '%s\n' "s/define( *'DB_NAME'.*/define( 'DB_NAME', '$SDB' );/" "s/define( *'DB_USER'.*/define( 'DB_USER', '$SDBU' );/" "s/define( *'DB_PASSWORD'.*/define( 'DB_PASSWORD', '$SDBP' );/" > "$T/wpc.sed"
  sed -i -f "$T/wpc.sed" "$T/wpc"
  grep -q "WP_HOME" "$T/wpc" || cat >> "$T/wpc" <<EOF
define( 'WP_HOME', '$SURL' );
define( 'WP_SITEURL', '$SURL' );
define( 'DISABLE_WP_CRON', true );
EOF
  user_put "$U" "$SDOC/wp-config.php" 640 < "$T/wpc" || fail "staging wp-config write failed"
  mysql -uroot "$SDB" -e "UPDATE ${PREFIX}options SET option_value='$SURL' WHERE option_name IN ('siteurl','home'); UPDATE ${PREFIX}options SET option_value='$OEMAIL' WHERE option_name='admin_email';" 2>>"$LOG" || fail "staging options failed"
  # mail gate: staging must never mail customers — reroute to the owner
  cat > "$T/gate.php" <<EOF
<?php
// App Toolkit staging gate — all outbound mail goes to the site owner only.
add_action( 'phpmailer_init', function ( \$m ) {
  \$m->clearAllRecipients(); \$m->clearCCs(); \$m->clearBCCs();
  \$m->addAddress( '$OEMAIL' );
  \$m->Subject = '[STAGING] ' . \$m->Subject;
} );
EOF
  as_user "$U" mkdir -p -- "$SDOC/wp-content/mu-plugins" || fail "mu-plugins dir failed"
  user_put "$U" "$SDOC/wp-content/mu-plugins/staging-mail-gate.php" 644 < "$T/gate.php" || fail "mail gate write failed"
  printf 'User-agent: *\nDisallow: /\n' | user_put "$U" "$SDOC/robots.txt" 644 || fail "robots.txt write failed"
  as_user "$U" setfacl -m "$(acl_web r)" -- "$SDOC/wp-config.php" 2>/dev/null || true
  # HTTP lock: OLS realm over / (verified live at build; curl-checked below)
  /usr/local/lsws/bin/lswsctrl restart >>"$LOG" 2>&1 || true
  LOCK="no"
  for _i in 1 2 3 4 5; do
    CODE="$(curl -sk --max-time 10 -o /dev/null -w '%{http_code}' "https://$STG/" 2>/dev/null || echo 000)"
    if [[ "$CODE" == "401" ]]; then LOCK="yes"; break; fi
    [[ "$CODE" == "000" ]] && sleep 3 || break
  done; unset _i CODE
  [[ "$LOCK" == "yes" ]] || echo "WARNING: realm lock did not engage (site works, lock missing — check $VH/vhost.conf)"
  sqlq skypanel <<<"INSERT IGNORE INTO mysql_dbs(user_id,db_name,db_user) VALUES($UIDN,'$SDB','$SDBU'); UPDATE staging_clones SET status='ready', basic_auth='$SAU:$SAP' WHERE id=$CLID;" || fail "tracking rows failed"
  echo "STAGING_URL=$SURL"
  echo "STAGING_AUTH=$SAU:(password on the App Toolkit page)"
  echo "STAGING_LOCK=$LOCK"
  echo "=== clone COMPLETE ==="
) &
