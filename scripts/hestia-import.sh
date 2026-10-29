#!/usr/bin/env bash
# hestia-import.sh <tarball> <hestiauser> <paneluser> [job] — import a
# HestiaCP user backup (v-backup-user: <user>.<date>.tar) into an EXISTING
# panel user. DETACHED (runs longer than the panel sudo timeout): backgrounds
# into /var/log/karnakpanel/software.log; progress in import_jobs when a job
# id is given (the Migrate page polls it).
#
# Migrated: every web domain (created here — subdomains as parent-zone A
# records via DomainProvisioner — public_html copied, .htaccess rewrites
# converted), MySQL DBs (same names/users; the MariaDB password hash carries
# over, so wp-config & co keep working), mailboxes (hash kept where Dovecot
# reads it, full Maildir incl. folders), aliases / forward-only boxes /
# catch-all as forwarders, auto-replies, custom DNS records (re-pointed off
# the old server IP, sub zones folded into the parent), cron (PAUSED).
# Report-only: pgsql DBs, forward-with-local-copy, private/ dirs, files
# hard-coding /home/<hestiauser>/web paths, SSL (reissue once DNS points here).
# Untrusted archive: Hestia .conf files are NEVER sourced — fields are
# grepped out one by one; free text reaches SQL only through HestiaImport.php.
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/db-caps.sh"  # DB_CAPS: per-user MariaDB caps (admin-set)
. "$(dirname "$(readlink -f "$0")")/php-versions.sh"
. "$(dirname "$(readlink -f "$0")")/as-user.sh"
TARBALL="${1:?}"; HUSER="${2:?}"; PUSER="${3:?}"; JOB="${4:-}"
S="$(dirname "$(readlink -f "$0")")"
LOG="/var/log/karnakpanel/software.log"
STAGE="/usr/local/skypanel/panel/storage/tmp-uploads"
RSTAGE="$(realpath -m "$STAGE")"; RTARBALL="$(realpath -m "$TARBALL")"
case "$RTARBALL" in "$RSTAGE"/*.tar) ;; *) echo "tarball outside staging"; exit 1;; esac
[[ -f "$RTARBALL" ]] || { echo "no such tarball"; exit 1; }
[[ "$HUSER" =~ ^[a-z0-9][a-z0-9._-]{0,30}[a-z0-9]$ ]] || { echo "bad hestia user"; exit 1; }
[[ "$PUSER" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad panel user"; exit 1; }
[[ -z "$JOB" || "$JOB" =~ ^[a-f0-9]{16}$ ]] || { echo "bad job"; exit 1; }
id "$PUSER" >/dev/null 2>&1 || { echo "no such panel user: $PUSER"; exit 1; }
PASS="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
[[ -n "$PASS" ]] || { echo "no panel db pass"; exit 1; }
UIDN="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT id FROM users WHERE username='$PUSER' LIMIT 1;" 2>/dev/null || true)"
[[ "$UIDN" =~ ^[0-9]+$ ]] || { echo "panel user not in panel DB: $PUSER"; exit 1; }
prog() {
  [[ -n "$JOB" ]] || return 0
  local d="${3//\'/}"; d="${d//\\/}"
  MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "INSERT INTO import_jobs(job,stage,pct,detail) VALUES('$JOB','$1',$2,'${d:0:250}') ON DUPLICATE KEY UPDATE stage=VALUES(stage),pct=VALUES(pct),detail=VALUES(detail);" 2>/dev/null || true
}
# panel code as root (prepared statements, validators); args via env only
PANEL_PHP() { ( cd /usr/local/skypanel/panel && php -r "
spl_autoload_register(function(\$c){foreach (['app/Core/','app/Controllers/','app/Models/'] as \$d){\$f=\"\$d\$c.php\"; if(file_exists(\$f)){require_once \$f; return;}}});
Config::load('config/config.php'); $1" ); }
# one quoted field out of a Hestia conf line: field <KEY> <file-or-line>
fieldf() { grep -m1 -oP "(^| )$1='\K[^']*" -- "$2" 2>/dev/null || true; }
fieldl() { grep -m1 -oP "(^| )$1='\K[^']*" <<<"$2" 2>/dev/null || true; }
unz() { case "$1" in *.zst) zstd -dcq -- "$1";; *.gz) gzip -dc -- "$1";; *) cat -- "$1";; esac; }
SAFE=(--no-same-owner --no-same-permissions --no-acls --no-xattrs --no-overwrite-dir)
( exec >>"$LOG" 2>&1
  echo "=== $(date -Is) hestia import ($HUSER -> $PUSER) ==="
  fail() { prog "failed" 0 "$1"; echo "hestia import FAILED: $1"; exit 1; }
  prog "importing" 1 "checking archive"
  LIST="$(tar tf "$RTARBALL" 2>/dev/null || true)"
  [[ -n "$LIST" ]] || fail "unreadable archive"
  grep -qE '^(\./)?(hestia|vesta)/user\.conf$' <<<"$LIST" || fail "not a HestiaCP backup (no hestia/user.conf)"
  ! grep -qE '(^|/)\.\.(/|$)|^/' <<<"$LIST" || fail "archive has absolute or .. paths"
  if grep -qE '\.zst$' <<<"$LIST" && ! command -v zstd >/dev/null 2>&1; then
    apt-get install -y zstd 2>&1 | tail -1 || true
    command -v zstd >/dev/null 2>&1 || fail "zstd needed (apt-get install zstd)"
  fi
  # room for the outer tar + every inner archive unpacked (~3x is the
  # usual zstd/gzip ratio on sites + mail; refuse rather than fill /)
  NEED=$(( $(stat -c %s "$RTARBALL") * 3 / 1024 ))
  FREE="$(df -Pk /root | awk 'NR==2{print $4}')"
  [[ "${FREE:-0}" -gt "$NEED" ]] || fail "not enough disk (need ~$((NEED/1024)) MB free)"
  TMP="$(mktemp -d /root/hestia-import-XXXXXX)"; trap 'rm -rf "$TMP"' EXIT
  X="$TMP/x"; mkdir -p "$X"
  prog "importing" 5 "unpacking archive"
  tar xf "$RTARBALL" -C "$X" "${SAFE[@]}" --exclude='*/dev/*' 2>/dev/null || fail "extract failed"
  HB="$X/hestia"; [[ -d "$HB" ]] || HB="$X/vesta"
  REPORT=""; rep() { REPORT="$REPORT; $1"; echo "$1"; }
  NFILES=0; NDB=0; NMAIL=0; NFWD=0
  # ---- old server addresses (records pointing there get re-pointed here) ----
  OLDIPS=""
  for f in "$X"/web/*/hestia/web.conf "$X"/dns/*/hestia/dns.conf; do
    [[ -f "$f" ]] || continue
    for k in IP NAT; do v="$(fieldf "$k" "$f")"; [[ "$v" =~ ^[0-9.]+$|^[0-9a-f:]+$ ]] && OLDIPS="$OLDIPS $v"; done
  done
  [[ -n "${HESTIA_SRC_IP:-}" && "$HESTIA_SRC_IP" =~ ^[0-9.]+$ ]] && OLDIPS="$OLDIPS $HESTIA_SRC_IP"
  # ---- domains: web + mail domains (apex first so subdomains find a parent) ----
  DOMS="$(for d in "$X"/web/* "$X"/mail/*; do [[ -d "$d" ]] && basename "$d"; done | sort -u \
    | grep -E '^[a-z0-9.-]+\.[a-z]{2,}$' | awk -F. '{print NF"\t"$0}' | sort -n | cut -f2 || true)"
  [[ -n "$DOMS" ]] || rep "no web or mail domains in the archive"
  declare -A DID=(); NEWDOMS=""
  TOT="$(grep -c . <<<"$DOMS" || true)"; I=0
  while IFS= read -r D; do
    [[ -n "$D" ]] || continue
    I=$((I+1)); prog "importing" $(( 10 + I * 30 / (TOT>0?TOT:1) )) "site $I/$TOT: $D"
    PHPH=""
    if [[ -f "$X/web/$D/hestia/web.conf" ]]; then
      BK="$(fieldf BACKEND "$X/web/$D/hestia/web.conf")"   # e.g. PHP-8_3
      [[ "$BK" =~ PHP-([0-9])_([0-9]) ]] && PHPH="${BASH_REMATCH[1]}.${BASH_REMATCH[2]}"
    fi
    OUT="$(KP_U="$PUSER" KP_D="$D" KP_P="$PHPH" PANEL_PHP 'HestiaImport::ensureDomain(getenv("KP_U"), getenv("KP_D"), getenv("KP_P"));' 2>&1 | tail -3)"
    grep '^note ' <<<"$OUT" | sed "s/^note /$D: /" || true
    RES="$(grep -E '^(ok|skip) ' <<<"$OUT" | tail -1)"
    case "$RES" in
      "ok "*) ID="$(awk '{print $2}' <<<"$RES")"; [[ "$ID" =~ ^[0-9]+$ ]] || { rep "$D: provisioning unclear, skipped"; continue; }
              DID[$D]="$ID"; [[ "$RES" == *created ]] && NEWDOMS="$NEWDOMS $D"; echo "domain $D: ${RES##* }";;
      *) rep "$D: ${RES#skip }"; [[ -n "$RES" ]] || rep "$D: provisioning failed ($(tr '\n' ' ' <<<"$OUT" | head -c 200))"; continue;;
    esac
    # ---- site files ----
    [[ -d "$X/web/$D" ]] || continue
    DATA=""; for c in "$X/web/$D/domain_data.tar.zst" "$X/web/$D/domain_data.tar.gz"; do [[ -f "$c" ]] && DATA="$c"; done
    [[ -n "$DATA" ]] || { rep "$D: no site data in backup"; continue; }
    CD="$(fieldf CUSTOM_DOCROOT "$X/web/$D/hestia/web.conf")"
    [[ -z "$CD" ]] || rep "$D: Hestia used a custom docroot ($CD) — check the site layout"
    W="$TMP/w"; rm -rf "$W"; mkdir -p "$W"
    unz "$DATA" | tar xf - -C "$W" "${SAFE[@]}" --exclude='./logs' --exclude='logs' 2>/dev/null || { rep "$D: site data extract failed"; continue; }
    DOC="/home/$PUSER/$D/public_html"
    [[ -d "$DOC" ]] || { rep "$D: docroot $DOC missing, files skipped"; continue; }
    if [[ ! -d "$W/public_html" ]]; then rep "$D: no public_html in site data"; continue; fi
    LEFT="$(as_user "$PUSER" ls -A -- "$DOC" 2>/dev/null | grep -v -e '^index\.html$' -e '^\.well-known$' -e '^autodiscover$' || true)"
    if [[ -n "$LEFT" ]]; then rep "$D: docroot not empty — site files SKIPPED (move them aside and re-import)"; continue; fi
    # foreign PHP overrides must not follow the site (our pools own config);
    # links leaving the docroot are dropped (cross-account symlink lockdown).
    # Both are done in root's private copy, which then goes in AS THE OWNER
    # (as root, a link the account planted in its docroot redirected the copy)
    rm -f "$W/public_html/.user.ini" "$W/public_html/php.ini"
    NL=0
    while IFS= read -r -d '' L; do
      T="$(realpath -m -- "$L" 2>/dev/null || true)"
      case "$T" in "$W/public_html"|"$W/public_html"/*) ;; *) rm -f -- "$L"; NL=$((NL+1));; esac
    done < <(find "$W/public_html" -type l -print0 2>/dev/null)
    [[ "$NL" == 0 ]] || rep "$D: $NL symlink(s) pointing outside the site removed"
    # Hestia /home/U/web/<dom>/… maps 1:1 onto /home/P/<dom>/… — rewrite it
    # in code/config files (FOSSBilling, WHMCS, Laravel .env… hard-code it;
    # left alone they 500). Logs are skipped; data in databases is not
    # touched (serialized lengths) — reported instead. Done in the private copy.
    NREF=0
    while IFS= read -r -d '' RF; do
      [[ -f "$RF" && ! -L "$RF" ]] || continue
      sed -i "s#/home/$HUSER/web/#/home/$PUSER/#g" -- "$RF" && NREF=$((NREF+1))
    done < <(grep -rlIZF "/home/$HUSER/web/" "$W/public_html" \
      --include='*.php' --include='*.inc' --include='*.env' --include='.env' --include='.htaccess' \
      --include='*.ini' --include='*.json' --include='*.yml' --include='*.yaml' --include='*.xml' --include='*.conf' \
      --exclude-dir=log --exclude-dir=logs 2>/dev/null || true)
    as_user "$PUSER" rm -f -- "$DOC/index.html" "$DOC/.user.ini" "$DOC/php.ini"
    user_unpack "$PUSER" "$W/public_html" "$DOC" || { rep "$D: site files copy failed (disk quota full?)"; continue; }
    as_user "$PUSER" find "$DOC" -type d -exec chmod 755 {} + 2>/dev/null || true
    as_user "$PUSER" find "$DOC" -type f -exec chmod 640 {} + 2>/dev/null || true
    command -v setfacl >/dev/null 2>&1 && as_user "$PUSER" setfacl -R -P -m "$(acl_web rX)" -- "$DOC" 2>/dev/null || true
    NF="$(as_user "$PUSER" find "$DOC" -type f 2>/dev/null | wc -l)"; NFILES=$((NFILES+NF)); echo "$D: $NF files"
    for extra in private document_errors public_shtml; do
      [[ -n "$(ls -A "$W/$extra" 2>/dev/null)" ]] && rep "$D: Hestia $extra/ had content — not copied (in the backup)"
    done
    [[ "$NREF" == 0 ]] || rep "$D: old path /home/$HUSER/web/ rewritten to /home/$PUSER/ in $NREF code/config file(s)"
    if grep -rqIF "/home/$HUSER/web/" "$W/public_html" --exclude-dir=log --exclude-dir=logs --exclude='*.log' 2>/dev/null; then
      rep "$D: other files still mention /home/$HUSER/web/ — check the app (paths stored in its database are not rewritten)"
    fi
    if [[ -f "$W/public_html/wp-config.php" && ! -f "$W/public_html/.htaccess" ]]; then rep "$D: WordPress without .htaccess (nginx site) — re-save Permalinks once"; fi
    KP_I="${DID[$D]}" KP_H="$W/public_html/.htaccess" PANEL_PHP 'HestiaImport::rewrites((int)getenv("KP_I"), (string)getenv("KP_H"));' 2>&1 | tail -2 || true
  done <<<"$DOMS"
  # ---- databases (db/<db>/: hestia/db.conf + <db>.mysql.sql.zst|gz) ----
  prog "importing" 45 "databases"
  PKGDB="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT p.max_dbs FROM packages p JOIN users u ON u.package_id=p.id WHERE u.id=$UIDN;" 2>/dev/null || true)"
  for DD in "$X"/db/*/; do
    [[ -d "$DD" ]] || continue
    CONF="$DD/hestia/db.conf"; [[ -f "$CONF" ]] || continue
    DB="$(fieldf DB "$CONF")"; DBU="$(fieldf DBUSER "$CONF")"; HASH="$(fieldf MD5 "$CONF")"; TYPE="$(fieldf TYPE "$CONF")"; CS="$(fieldf CHARSET "$CONF")"
    [[ "$TYPE" == "mysql" ]] || { rep "db $DB: $TYPE not supported here, skipped"; continue; }
    [[ "$DB" =~ ^[a-z0-9][a-z0-9_]{1,63}$ && "$DBU" =~ ^[a-z0-9][a-z0-9_]{1,31}$ ]] || { rep "db $DB: odd name, skipped"; continue; }
    case "$DB" in mysql|information_schema|performance_schema|sys|skypanel|roundcube) rep "db $DB: system name, skipped"; continue;; esac
    [[ "$CS" =~ ^[a-z0-9]{2,16}$ ]] || CS="utf8mb4"
    DUMP=""; for c in "$DD/$DB.mysql.sql.zst" "$DD/$DB.mysql.sql.gz" "$DD/$DB.mysql.sql"; do [[ -f "$c" ]] && DUMP="$c"; done
    [[ -n "$DUMP" ]] || { rep "db $DB: no dump in backup"; continue; }
    if [[ "$PKGDB" =~ ^[0-9]+$ ]]; then
      HAVE="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT COUNT(*) FROM mysql_dbs WHERE user_id=$UIDN;" 2>/dev/null || echo 0)"
      [[ "${HAVE:-0}" -lt "$PKGDB" ]] || { rep "db $DB: package database cap ($PKGDB), skipped"; continue; }
    fi
    if mysql -uroot -N -e "SHOW DATABASES LIKE '${DB//_/\\_}';" 2>/dev/null | grep -qxF "$DB"; then rep "db $DB: already exists on this server, skipped"; continue; fi
    # a db user that exists already must be this account's own (an earlier
    # db of this same import) — never re-password someone else's login
    UEX="$(mysql -uroot -N -e "SELECT COUNT(*) FROM mysql.user WHERE User='$DBU';" 2>/dev/null || echo 0)"
    UOWN="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT COUNT(*) FROM mysql_dbs WHERE db_user='$DBU' AND user_id=$UIDN;" 2>/dev/null || echo 0)"
    UOTH="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT COUNT(*) FROM mysql_dbs WHERE (db_user='$DBU' OR db_name='$DB') AND user_id<>$UIDN;" 2>/dev/null || echo 1)"
    if [[ "${UOTH:-1}" != 0 ]] || [[ "${UEX:-0}" != 0 && "${UOWN:-0}" == 0 ]]; then rep "db $DB: db user $DBU belongs to another account here, skipped"; continue; fi
    NOTE=""
    if [[ "${UEX:-0}" != 0 ]]; then AUTH=""
    elif [[ "$HASH" =~ ^\*[0-9A-F]{40}$ ]]; then AUTH="CREATE USER '$DBU'@'localhost' IDENTIFIED BY PASSWORD '$HASH';"
    else NP="$(openssl rand -hex 12)"; AUTH="CREATE USER '$DBU'@'localhost' IDENTIFIED BY '$NP';"; NOTE="$NP"; fi
    mysql -uroot <<<"CREATE DATABASE \`$DB\` CHARACTER SET $CS; $AUTH GRANT ALL PRIVILEGES ON \`$DB\`.* TO '$DBU'@'localhost'; GRANT USAGE ON *.* TO '$DBU'@'localhost' WITH $DB_CAPS; FLUSH PRIVILEGES;" 2>/dev/null \
      || { rep "db $DB: create failed"; continue; }
    # the dump is the customer's SQL: never run it as MariaDB root (USE
    # skypanel; UPDATE users…). A throwaway login with rights on this one
    # database only runs it, then goes.
    IU="kpimp_$(openssl rand -hex 6)"; IP="$(openssl rand -hex 16)"
    if mysql -uroot <<<"CREATE USER '$IU'@'localhost' IDENTIFIED BY '$IP'; GRANT ALL PRIVILEGES ON \`$DB\`.* TO '$IU'@'localhost';" 2>/dev/null \
       && unz "$DUMP" | MYSQL_PWD="$IP" mysql --force -u"$IU" "$DB" 2>"$TMP/dberr"; then echo "db imported: $DB"
      [[ -s "$TMP/dberr" ]] && rep "db $DB: some statements refused ($(grep -m2 ERROR "$TMP/dberr" | head -c 200 | tr '\n' ' '))"
    else rep "db $DB: data import errors ($(head -c 150 "$TMP/dberr" 2>/dev/null | tr '\n' ' '))"; fi
    mysql -uroot -e "DROP USER IF EXISTS '$IU'@'localhost';" 2>/dev/null || true
    MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "INSERT IGNORE INTO mysql_dbs(user_id,db_name,db_user) VALUES($UIDN,'$DB','$DBU');" 2>/dev/null || true
    NDB=$((NDB+1))
    if [[ -n "$NOTE" ]]; then
      # password not portable (MySQL 8 hash): patch wp-config that uses this db
      FIXED=0
      # customer tree: found, read and rewritten AS THE OWNER (temp + rename)
      while IFS= read -r WC; do
        as_user "$PUSER" cat -- "$WC" 2>/dev/null > "$TMP/wc" || continue
        grep -qE "define\(\s*'DB_NAME'\s*,\s*'$DB'" "$TMP/wc" 2>/dev/null || continue
        sed -E "s/(define\(\s*'DB_PASSWORD'\s*,\s*')[^']*('\s*\))/\1$NOTE\2/" "$TMP/wc" | user_put "$PUSER" "$WC" 640 && FIXED=1
        as_user "$PUSER" setfacl -m "$(acl_web r)" -- "$WC" 2>/dev/null || true
      done < <(as_user "$PUSER" find "/home/$PUSER" -maxdepth 4 -name wp-config.php -type f -path '*/public_html/*' 2>/dev/null)
      if [[ "$FIXED" == 1 ]]; then rep "db $DB: new password for $DBU written into wp-config.php"
      else rep "db $DB: user $DBU password $NOTE (update the app config)"; fi
    fi
  done
  # ---- mail (mail/<dom>/: hestia/<dom>.conf accounts, accounts.tar.*) ----
  prog "importing" 60 "mail"
  PKGEM="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT p.max_emails FROM packages p JOIN users u ON u.package_id=p.id WHERE u.id=$UIDN;" 2>/dev/null || true)"
  ARGON=0; doveadm pw -l 2>/dev/null | grep -qw ARGON2ID && ARGON=1
  : > "$TMP/autoreply.tsv"
  for MDIR in "$X"/mail/*/; do
    [[ -d "$MDIR" ]] || continue
    MD="$(basename "$MDIR")"
    MID="${DID[$MD]:-}"; [[ -n "$MID" ]] || { rep "mail $MD: domain not imported, mail skipped"; continue; }
    ACONF="$MDIR/hestia/$MD.conf"; [[ -f "$ACONF" ]] || { echo "mail $MD: no accounts"; continue; }
    MA="$TMP/m"; rm -rf "$MA"; mkdir -p "$MA"
    for c in "$MDIR/accounts.tar.zst" "$MDIR/accounts.tar.gz"; do
      [[ -f "$c" ]] && { unz "$c" | tar xf - -C "$MA" "${SAFE[@]}" 2>/dev/null || rep "mail $MD: mailbox data extract failed"; }
    done
    while IFS= read -r LINE; do
      L="$(fieldl ACCOUNT "$LINE")"
      [[ "$L" =~ ^[a-z0-9._-]{1,64}$ ]] || continue
      H="$(fieldl MD5 "$LINE")"; Q="$(fieldl QUOTA "$LINE")"
      FWD="$(fieldl FWD "$LINE")"; FONLY="$(fieldl FWD_ONLY "$LINE")"; ALI="$(fieldl ALIAS "$LINE")"; AR="$(fieldl AUTOREPLY "$LINE")"
      # Dovecot reads {SCHEME} prefixes; bare $2y$ is this panel's native form
      case "$H" in
        '{BLF-CRYPT}$2'[aby]'$'*) STORED="${H#\{BLF-CRYPT\}}"; NOTE="";;
        '{SHA512-CRYPT}$6$'*|'{SHA256-CRYPT}$5$'*) STORED="$H"; NOTE="";;
        '{MD5}$1$'*|'{MD5-CRYPT}$1$'*) STORED="{MD5-CRYPT}${H#\{*\}}"; NOTE="";;
        '{ARGON2ID}$argon2id$'*) if [[ "$ARGON" == 1 ]]; then STORED="$H"; NOTE=""; else STORED=""; fi;;
        *) STORED="";;
      esac
      [[ -z "$STORED" || "$STORED" =~ ^[][A-Za-z0-9{}\$./+=,-]+$ ]] || STORED=""
      if [[ -z "$STORED" ]]; then STORED="$(php -r 'echo password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);')"; NOTE=" (password not portable — set a new one in Email)"; fi
      if [[ "$FONLY" == "yes" && -n "$FWD" ]]; then :   # forward-only: no mailbox, forwarder below
      else
        EX="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT id FROM email_accounts WHERE domain_id=$MID AND localpart='$L' LIMIT 1;" 2>/dev/null || true)"
        if [[ -n "$EX" ]]; then rep "mailbox $L@$MD exists, skipped"
        elif [[ "$PKGEM" =~ ^[0-9]+$ && "$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT COUNT(*) FROM email_accounts WHERE user_id=$UIDN;" 2>/dev/null || echo 0)" -ge "$PKGEM" ]]; then
          rep "mailbox $L@$MD: package mailbox cap ($PKGEM), skipped"
        else
          SRC="$MA/$L"; SZ=0; [[ -d "$SRC" ]] && SZ="$(du -sm "$SRC" 2>/dev/null | cut -f1)"
          # an "unlimited" box gets headroom over what it already holds —
          # a fixed 1024 would bounce a 3 GB mailbox on first delivery
          if [[ "$Q" =~ ^[0-9]+$ && "$Q" -gt 0 ]]; then QM="$Q"; else QM=$(( SZ * 5 / 4 + 256 )); fi
          [[ "$QM" -ge 1024 ]] || QM=1024
          MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "INSERT INTO email_accounts(user_id,domain_id,localpart,password_hash,quota_mb) VALUES($UIDN,$MID,'$L','$STORED',$QM);" 2>/dev/null || { rep "mailbox $L@$MD: insert failed"; continue; }
          "$S/email-add.sh" "$L@$MD" "$PUSER" >/dev/null 2>&1 || rep "mailbox $L@$MD: maps not updated"
          VDIR="/var/mail/virtual/$MD/$L"
          if [[ -d "$SRC" ]]; then
            cp -a "$SRC/." "$VDIR/" 2>/dev/null || true
            find "$VDIR" \( -name 'dovecot.index*' -o -name 'maildirsize' -o -name 'dovecot.list.index*' \) -type f -delete 2>/dev/null || true
            find "$VDIR" -type l -delete 2>/dev/null || true
          fi
          chown -R vmail:vmail "$VDIR"
          find "$VDIR" -type d -exec chmod 700 {} + 2>/dev/null || true
          find "$VDIR" -type f -exec chmod 600 {} + 2>/dev/null || true
          NMAIL=$((NMAIL+1)); echo "mailbox: $L@$MD (${SZ} MB, quota ${QM} MB)$NOTE"
          [[ -z "$NOTE" ]] || REPORT="$REPORT; $L@$MD$NOTE"
          if [[ "$AR" == "yes" ]]; then
            MSG=""; for c in "$MDIR/hestia/$L@$MD.msg" "$MDIR/conf/autoreply.$L.msg"; do [[ -f "$c" ]] && MSG="$c"; done
            [[ -n "$MSG" ]] && printf '%s\t%s\t%s\n' "$MD" "$L" "$(head -c 4000 "$MSG" | base64 -w0)" >> "$TMP/autoreply.tsv"
          fi
        fi
      fi
      # forwarders: forward-only boxes + every alias (alias@ -> box@)
      addfw() { # <localpart> <comma targets>
        local CLEAN="" t
        IFS=',' read -ra TS <<< "$2"
        for t in "${TS[@]}"; do
          t="$(tr -d '[:space:]' <<<"$t" | tr 'A-Z' 'a-z')"
          [[ "$t" =~ ^[a-z0-9._+-]+@[a-z0-9.-]+\.[a-z]{2,}$ && "$t" != "$1@$MD" ]] || continue
          CLEAN="${CLEAN:+$CLEAN, }$t"
        done
        [[ -n "$CLEAN" ]] || return 0
        MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "INSERT INTO email_forwards(user_id,domain_id,localpart,targets) VALUES($UIDN,$MID,'$1','$CLEAN') ON DUPLICATE KEY UPDATE targets=VALUES(targets);" 2>/dev/null || return 0
        "$S/forward-add.sh" "$1@$MD" "$CLEAN" >/dev/null 2>&1 && NFWD=$((NFWD+1)) || true
      }
      if [[ "$FONLY" == "yes" && -n "$FWD" ]]; then addfw "$L" "$FWD"
      elif [[ -n "$FWD" ]]; then rep "$L@$MD forwarded to $FWD while keeping a copy — panel forwarders replace delivery; re-add if wanted"; fi
      if [[ -n "$ALI" ]]; then
        IFS=',' read -ra AS <<< "$ALI"
        for a in "${AS[@]}"; do [[ "$a" =~ ^[a-z0-9._-]{1,64}$ ]] && addfw "$a" "$L@$MD"; done
      fi
    done < <(grep -E "^ACCOUNT='" "$ACONF" 2>/dev/null || true)
    CA="$(fieldf CATCHALL "$MDIR/hestia/mail.conf")"
    if [[ -n "$CA" && "$CA" =~ ^[a-z0-9._+-]+@[a-z0-9.-]+\.[a-z]{2,}$ ]]; then
      MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "INSERT INTO email_forwards(user_id,domain_id,localpart,targets) VALUES($UIDN,$MID,'*','$CA') ON DUPLICATE KEY UPDATE targets=VALUES(targets);" 2>/dev/null \
        && "$S/forward-add.sh" "*@$MD" "$CA" >/dev/null 2>&1 && { NFWD=$((NFWD+1)); echo "catch-all $MD -> $CA"; } || true
    fi
  done
  if [[ -s "$TMP/autoreply.tsv" ]]; then
    KP_U="$PUSER" KP_F="$TMP/autoreply.tsv" PANEL_PHP 'HestiaImport::autoreply(getenv("KP_U"), getenv("KP_F"));' 2>&1 | tail -3 || true
  fi
  "$S/email-reconcile.sh" >/dev/null 2>&1 || true
  "$S/autoreply-sync.sh" >/dev/null 2>&1 || true
  # ---- DNS (dns/<zone>/hestia/<zone>.conf records) ----
  prog "importing" 80 "dns records"
  : > "$TMP/dns.tsv"
  for ZD in "$X"/dns/*/; do
    [[ -d "$ZD" ]] || continue
    Z="$(basename "$ZD")"; [[ "$Z" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || continue
    [[ -f "$ZD/hestia/$Z.conf" ]] || continue
    while IFS= read -r LINE; do
      [[ "$(fieldl SUSPENDED "$LINE")" == "yes" ]] && continue
      R="$(fieldl RECORD "$LINE")"; T="$(fieldl TYPE "$LINE")"; P="$(fieldl PRIORITY "$LINE")"; TT="$(fieldl TTL "$LINE")"; V="$(fieldl VALUE "$LINE")"
      [[ "$R" =~ ^[A-Za-z0-9_.@*-]{1,190}$ && "$T" =~ ^[A-Z]{1,8}$ ]] || continue
      [[ "$P" =~ ^[0-9]{1,5}$ ]] || P=10; [[ "$TT" =~ ^[0-9]{1,7}$ ]] || TT=3600
      printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$Z" "$R" "$T" "$P" "$TT" "$(printf '%s' "$V" | base64 -w0)" >> "$TMP/dns.tsv"
    done < <(grep -E "^ID='" "$ZD/hestia/$Z.conf" 2>/dev/null || true)
  done
  if [[ -s "$TMP/dns.tsv" ]]; then
    while IFS= read -r l; do rep "$l"; done < <(KP_U="$PUSER" KP_F="$TMP/dns.tsv" KP_O="$OLDIPS" KP_N="$NEWDOMS" \
      PANEL_PHP 'HestiaImport::dns(getenv("KP_U"), getenv("KP_F"), getenv("KP_O"), getenv("KP_N"));' 2>&1 | tail -40)
  fi
  # ---- cron (cron/cron.conf; imported paused) ----
  prog "importing" 90 "cron jobs"
  if [[ -f "$X/cron/cron.conf" ]]; then
    : > "$TMP/cron.tsv"
    while IFS= read -r LINE; do
      SCH="$(fieldl MIN "$LINE") $(fieldl HOUR "$LINE") $(fieldl DAY "$LINE") $(fieldl MONTH "$LINE") $(fieldl WDAY "$LINE")"
      printf '%s\t%s\t%s\n' "$SCH" "$(printf '%s' "$(fieldl CMD "$LINE")" | base64 -w0)" "$(fieldl SUSPENDED "$LINE")" >> "$TMP/cron.tsv"
    done < <(grep -E "^JOB='" "$X/cron/cron.conf" 2>/dev/null || true)
    while IFS= read -r l; do rep "$l"; done < <(KP_U="$PUSER" KP_F="$TMP/cron.tsv" KP_H="$HUSER" \
      PANEL_PHP 'HestiaImport::cron(getenv("KP_U"), getenv("KP_F"), getenv("KP_H"));' 2>&1 | sort | uniq -c | sed -E 's/^ *1 //; s/^ *([0-9]+) (.*)/\2 (x\1)/' | tail -10)
  fi
  [[ -n "$NEWDOMS" ]] && rep "SSL: issue Let's Encrypt for$NEWDOMS once DNS points here"
  # imported dirs are 755: close them (and the rest of the home) to other accounts
  "$S/home-perms.sh" "$PUSER" >/dev/null 2>&1 || true
  SUM="files=$NFILES dbs=$NDB mailboxes=$NMAIL forwarders=$NFWD"
  echo "=== hestia import done: $SUM$REPORT ==="
  prog "done" 100 "imported: $SUM (full report: Logs → software)"
  rm -f "$RTARBALL"
) &
echo "import started in background — progress on the Migrate page"
