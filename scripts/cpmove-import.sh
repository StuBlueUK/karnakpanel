#!/usr/bin/env bash
# cpmove-import.sh <tarball> <cpuser> <paneluser> <domain> — import a cPanel
# full backup (cpmove-USER.tar.gz) into an EXISTING panel user + domain.
# DETACHED (runs longer than the panel sudo timeout): backgrounds into
# /var/log/karnakpanel/software.log like the app installers.
#
# v3 scope: site files -> docroot, MySQL dumps -> same-name DBs, mailboxes
# (passwords preserved where portable) + stored mail, forwarders, sub/addon
# domains (rows + vhost + zone + files + mail), autoresponders (subject/body;
# weekly-per-sender here), user filters (contains/folder/redirect/discard).
# REPORT-ONLY: cron entries, DNS (re-point/delegate manually), non-WP
# DB passwords (random + listed — update the app config), mailing lists
# (bulk-importing a live list is a spam-reputation footgun — migrate by hand).
#
# WordPress bonus: DB credentials are read back out of the backed-up
# wp-config.php, so WP sites typically work with zero config edits.
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/db-caps.sh"  # DB_CAPS: per-user MariaDB caps (admin-set)
. "$(dirname "$(readlink -f "$0")")/php-versions.sh"
. "$(dirname "$(readlink -f "$0")")/as-user.sh"
# place a root-extracted site tree into an existing docroot AS THE OWNER (root
# cp/chown/chmod/setfacl there follows links the account plants)
site_in() {  # <src> <docroot>
  as_user "$PUSER" rm -f -- "$2/index.html" "$2/.user.ini" "$2/php.ini"
  user_unpack "$PUSER" "$1" "$2" || return 1
  as_user "$PUSER" rm -f -- "$2/.user.ini" "$2/php.ini"
  as_user "$PUSER" find "$2" -type d -exec chmod 755 {} + 2>/dev/null || true
  as_user "$PUSER" find "$2" -type f -exec chmod 640 {} + 2>/dev/null || true
  command -v setfacl >/dev/null 2>&1 && as_user "$PUSER" setfacl -R -P -m "$(acl_web rX)" -- "$2" 2>/dev/null || true
}
# SQL string body: backslashes too, not just quotes — MariaDB's default
# sql_mode reads \' as an escaped quote, so doubling quotes alone lets \' out
sqlq() { local s="${1//\\/\\\\}"; printf '%s' "${s//\'/\'\'}"; }
TARBALL="${1:?}"; CPUSER="${2:?}"; PUSER="${3:?}"; DOMAIN="${4:?}"
LOG="/var/log/karnakpanel/software.log"
STAGE="/usr/local/skypanel/panel/storage/tmp-uploads"
RSTAGE="$(realpath -m "$STAGE")"; RTARBALL="$(realpath -m "$TARBALL")"
case "$RTARBALL" in "$RSTAGE"/*.tar.gz) ;; *) echo "tarball outside staging"; exit 1;; esac
[[ "$CPUSER" =~ ^[a-z][a-z0-9_]{1,15}$ ]] || { echo "bad cpanel user"; exit 1; }
[[ "$PUSER" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad panel user"; exit 1; }
[[ "$DOMAIN" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad domain"; exit 1; }
id "$PUSER" >/dev/null 2>&1 || { echo "no such panel user: $PUSER"; exit 1; }
( exec >>"$LOG" 2>&1
  echo "=== $(date -Is) cpmove import $DOMAIN (cp $CPUSER -> $PUSER) ==="
  PASS="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
  [[ -n "$PASS" ]] || { echo "no panel db pass"; exit 1; }
  UIDN="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT id FROM users WHERE username='$PUSER' LIMIT 1;" 2>/dev/null || true)"
  DID="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT id FROM domains WHERE domain='$DOMAIN' AND user_id='$UIDN' LIMIT 1;" 2>/dev/null || true)"
  [[ -n "${UIDN:-}" && -n "${DID:-}" ]] || { echo "target user/domain not in panel (add the domain first)"; exit 1; }
  # per-domain mail + forwarder migration (main domain + every sub/addon call
  # these; counters NMAIL/NFWD are global — never call in a pipeline subshell)
  migrate_mail() { # <domain> <did> — mailboxes (passwd schemes) + stored mail
    # hash schemes: $6$ -> SHA512-CRYPT, $2y$ native, $1$ -> MD5-CRYPT
    # (Dovecot reads explicit {SCHEME} prefixes); anything else resets.
    local MD="$1" MWDID="$2" PWFILE LOCAL HASH REST STORED NOTE EXISTS SD
    PWFILE="$TMP/etc/$MD/passwd"
    [[ -f "$PWFILE" ]] || PWFILE="$W/homedir/etc/$MD/passwd"
    [[ -f "$PWFILE" ]] || { echo "no mailbox list (etc/$MD/passwd) — mail skipped"; return 0; }
    while IFS=: read -r LOCAL HASH REST; do
      [[ "$LOCAL" =~ ^[a-z0-9._-]{1,64}$ ]] || continue
      if [[ "$HASH" == *'$6$'* ]]; then STORED="{SHA512-CRYPT}$(echo "$HASH" | grep -o '\$6\$.*')"; NOTE="";
      elif [[ "$HASH" == '$2y$'* ]]; then STORED="$HASH"; NOTE="";
      elif [[ "$HASH" == '$1$'* ]]; then STORED="{MD5-CRYPT}$HASH"; NOTE="";
      else STORED="$(openssl rand -hex 10)"; NOTE=" (password reset needed)"; fi
      # the hash goes into SQL verbatim: crypt charset only (as hestia-import)
      [[ "$STORED" =~ ^[A-Za-z0-9{}\$./+=,-]+$ ]] || { STORED="$(openssl rand -hex 10)"; NOTE=" (password reset needed)"; }
      EXISTS="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT id FROM email_accounts WHERE domain_id=$MWDID AND localpart='$LOCAL' LIMIT 1;" 2>/dev/null || true)"
      if [[ -n "$EXISTS" ]]; then echo "mailbox exists, skipped: $LOCAL@$MD"; continue; fi
      MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "INSERT INTO email_accounts(user_id,domain_id,localpart,password_hash,quota_mb) VALUES($UIDN,$MWDID,'$LOCAL','$STORED',1024);" 2>/dev/null || continue
      /usr/local/skypanel/scripts/email-add.sh "$LOCAL@$MD" "$PUSER" >/dev/null 2>&1 || true
      for sub in cur new; do
        SD=""; for cand in "$TMP/home/mail/$MD/$LOCAL/$sub" "$TMP/mail/$MD/$LOCAL/$sub" "$W/homedir/mail/$MD/$LOCAL/$sub"; do [[ -d "$cand" ]] && SD="$cand"; done
        if [[ -n "$SD" ]]; then
          VDIR="/var/mail/virtual/$MD/$LOCAL"
          mkdir -p "$VDIR"/{cur,new,tmp}
          chown -R vmail:vmail "$VDIR" 2>/dev/null || true
          shopt -s nullglob; for m in "$SD"/*; do [[ -f "$m" && ! -L "$m" ]] && cp -P -- "$m" "$VDIR/new/" 2>/dev/null || true; done; shopt -u nullglob
        fi
      done
      chown -R vmail:vmail "/var/mail/virtual/$MD/$LOCAL" 2>/dev/null || true
      find "/var/mail/virtual/$MD/$LOCAL" -type f -exec chmod 600 {} + 2>/dev/null || true
      NMAIL=$((NMAIL+1)); echo "mailbox: $LOCAL@$MD$NOTE"
    done < "$PWFILE"
  }
  migrate_fw() { # <domain> <did> — forwarders (plain address targets only)
    local MD="$1" MWDID="$2" VF SRC TGTS CLEAN t
    VF="$TMP/etc/valiases/$MD"
    [[ -f "$VF" ]] || VF="$W/homedir/etc/valiases/$MD"
    [[ -f "$VF" ]] || return 0
    while IFS=: read -r SRC TGTS; do
      SRC="$(echo "$SRC" | tr -d '[:space:]')"; TGTS="$(echo "$TGTS" | tr -d ' ')"
      [[ "$SRC" =~ ^[a-z0-9._-]+$ && "$SRC" != "*" ]] || continue
      CLEAN=""
      IFS=',' read -ra TS <<< "$TGTS"
      for t in "${TS[@]}"; do
        [[ "$t" =~ ^[a-z0-9._-]+@[a-z0-9.-]+\.[a-z]{2,}$ && "$t" != *"|"* && "$t" != *"/"* ]] || continue
        [[ "$t" != "$SRC@$MD" ]] || continue
        CLEAN="${CLEAN:+$CLEAN, }$t"
      done
      [[ -n "$CLEAN" ]] || continue
      MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "INSERT INTO email_forwards(user_id,domain_id,localpart,targets) VALUES($UIDN,$MWDID,'$SRC','$CLEAN') ON DUPLICATE KEY UPDATE targets=VALUES(targets);" 2>/dev/null || continue
      /usr/local/skypanel/scripts/forward-add.sh "$SRC@$MD" "$CLEAN" >/dev/null 2>&1 || true
      NFWD=$((NFWD+1))
    done < <(grep -E '^[a-z0-9._-]+:' "$VF" 2>/dev/null || true)
    echo "forwarders: $NFWD"
  }
  LIST="$(tar tzf "$RTARBALL" 2>/dev/null || true)"
  [[ -n "$LIST" ]] || { echo "unreadable tarball"; exit 1; }
  echo "$LIST" | grep -qE '^(.*/)?(homedir\.tar|mysql/|etc/|public_html/)' || { echo "not a cpanel backup (no homedir/mysql/etc)"; exit 1; }
  TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
  # untrusted archive: never preserve owners/permissions, no devices/suid
  tar xzf "$RTARBALL" -C "$TMP" --no-same-owner --no-same-permissions --no-acls --no-xattrs --exclude='dev/*' 2>/dev/null || { echo "extract failed"; exit 1; }
  DOC="/home/$PUSER/$DOMAIN/public_html"
  REPORT=""; NFILES=0; NDB=0; NMAIL=0; NFWD=0; NRESP=0; NFILT=0
  # modern pkgacct nests everything under a wrapper dir (inline homedir/
  # tree, per-DB mysql/*.sql, homedir/etc mail data); old archives lay the
  # same names at top level with homedir.tar. Detect the wrapper generically:
  # the wrapper name follows the ARCHIVE name (cpmove-USER for pkgacct,
  # backup-<date>_<user> for UAPI home-dir builds), never the account name.
  W=""
  _cand="$(find "$TMP" -maxdepth 2 -type d -name homedir 2>/dev/null | head -1 || true)"
  [[ -n "$_cand" ]] && W="$(dirname "$_cand")"
  case "$W" in "$TMP"/?*) ;; *) W="";; esac
  unset _cand
  # ---- site files (homedir.tar preferred, else inline homedir/ tree) ----
  if [[ -f "$TMP/homedir.tar" ]]; then
    mkdir -p "$TMP/home"
    tar xf "$TMP/homedir.tar" -C "$TMP/home" --no-same-owner --no-same-permissions --no-acls --no-xattrs 2>/dev/null || { echo "homedir extract failed"; exit 1; }
    SRC="$(find "$TMP/home" -maxdepth 3 -type d -name public_html 2>/dev/null | head -1 || true)"
    if [[ -n "$SRC" ]]; then
      LEFT="$(ls -A "$DOC" 2>/dev/null | grep -v '^index\.html$' || true)"
      if [[ -n "$LEFT" ]]; then echo "docroot not empty — site files SKIPPED (move them aside and re-import)"; else
        site_in "$SRC" "$DOC" || echo "site files copy failed"
        NFILES="$(as_user "$PUSER" find "$DOC" -type f | wc -l)"
        REPORT="$REPORT; files: $NFILES into docroot"
      fi
    else echo "no public_html in homedir.tar — files skipped"; fi
  elif [[ -n "$W" ]]; then
    SRC="$(find "$W/homedir" -maxdepth 3 -type d -name public_html 2>/dev/null | head -1 || true)"
    if [[ -n "$SRC" ]]; then
      LEFT="$(ls -A "$DOC" 2>/dev/null | grep -v -e '^index\.html$' -e '^\.well-known$' -e '^autodiscover$' || true)"
      if [[ -n "$LEFT" ]]; then echo "docroot not empty — site files SKIPPED (move them aside and re-import)"; else
        site_in "$SRC" "$DOC" || echo "site files copy failed"
        NFILES="$(as_user "$PUSER" find "$DOC" -type f | wc -l)"
        REPORT="$REPORT; files: $NFILES into docroot (foreign php.ini/.user.ini dropped)"
      fi
    else echo "no public_html in inline homedir — files skipped"; fi
  else echo "no homedir.tar — files skipped"; fi
  # ---- databases (mysql/*.sql, original names; WP creds recovered if present) ----
  # old layout: $TMP/mysql/*.sql — modern: $W/mysql/*.sql (top-level mysql.sql
  # is grants-only preamble, never a dump — skipped by the loop below).
  # the docroot was just filled from the customer's tarball (links and all):
  # find + read the config AS THE OWNER, into a root-private copy
  WPCFG=""; WPF="$(as_user "$PUSER" find "$DOC" -maxdepth 2 -name wp-config.php -type f 2>/dev/null | head -1 || true)"
  if [[ -n "$WPF" ]]; then WPCFG="$TMP/.wpcfg"; as_user "$PUSER" cat -- "$WPF" > "$WPCFG" 2>/dev/null || WPCFG=""; fi
  # every name below comes from the customer's backup: an existing database or
  # MariaDB user is only reused when the panel records it as THIS account's
  # (a backup naming another tenant's db, or DB_USER 'root'/'skypanel', would
  # otherwise get its password reset and its data overwritten)
  my_q() { mysql -uroot -N -e "$1" 2>/dev/null || true; }
  ours_q() { MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT 1 FROM mysql_dbs WHERE user_id=$UIDN AND $1 LIMIT 1;" 2>/dev/null || true; }
  # (recorded for another account = theirs, even when the login is missing)
  oth_q() { MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT 1 FROM mysql_dbs WHERE user_id<>$UIDN AND $1 LIMIT 1;" 2>/dev/null || echo 1; }
  dbu_free() { [[ "$1" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || return 1
    [[ -z "$(oth_q "db_user='$1'")" ]] || return 1
    [[ -z "$(my_q "SELECT 1 FROM mysql.user WHERE User='$1' LIMIT 1")" || -n "$(ours_q "db_user='$1'")" ]]; }
  SEEN="|"
  for dump in "$TMP"/mysql/*.sql "$W"/mysql/*.sql; do
    [[ -f "$dump" ]] || continue
    DB="$(basename "$dump" .sql)"
    [[ "$DB" =~ ^[a-z][a-z0-9_]{2,63}$ ]] || { echo "skip odd db name: $DB"; continue; }
    case "$SEEN" in *"|$DB|"*) echo "db already processed: $DB"; continue;; esac
    SEEN="$SEEN$DB|"
    case "$DB" in mysql|information_schema|performance_schema|sys|skypanel|roundcube) echo "skip system db: $DB"; continue;; esac
    if [[ -n "$(oth_q "db_name='$DB'")" ]] || [[ -n "$(my_q "SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$DB'")" && -z "$(ours_q "db_name='$DB'")" ]]; then
      echo "db name taken on this server, skipped: $DB"; REPORT="$REPORT; db $DB: name taken here, NOT imported"; continue
    fi
    DBU=""; DBP=""
    if [[ -n "$WPCFG" ]] && grep -q "define( *'DB_NAME' *, *'$DB'" "$WPCFG" 2>/dev/null; then
      DBU="$(grep -oP "define\(\s*'DB_USER'\s*,\s*'\K[^']+" "$WPCFG" | head -1 || true)"
      DBP="$(grep -oP "define\(\s*'DB_PASSWORD'\s*,\s*'\K[^']+" "$WPCFG" | head -1 || true)"
      [[ ${#DBP} -ge 8 && "$DBP" != *"'"* && "$DBP" != *\\* ]] && dbu_free "$DBU" || { DBU=""; DBP=""; }
    fi
    if [[ -z "$DBU" ]]; then
      DBU="${DB:0:32}"; dbu_free "$DBU" || DBU="${PUSER:0:24}_cp$NDB"
      dbu_free "$DBU" || DBU="${PUSER:0:20}_$(openssl rand -hex 5)"
      DBP="$(openssl rand -hex 10)"
      REPORT="$REPORT; db $DB user $DBU password $DBP (update app config)"
    else
      REPORT="$REPORT; db $DB user $DBU (password from wp-config, unchanged)"
    fi
    mysql -uroot <<<"CREATE DATABASE IF NOT EXISTS \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS '$DBU'@'localhost' IDENTIFIED BY '$DBP'; ALTER USER '$DBU'@'localhost' IDENTIFIED BY '$DBP'; GRANT ALL PRIVILEGES ON \`$DB\`.* TO '$DBU'@'localhost'; GRANT USAGE ON *.* TO '$DBU'@'localhost' WITH $DB_CAPS; FLUSH PRIVILEGES;" 2>/dev/null || { echo "db objects failed: $DB"; continue; }
    # the dump is the customer's SQL: run it as the db's own user (rights on
    # this one database only), never as MariaDB root
    MYSQL_PWD="$DBP" mysql --force -u"$DBU" "$DB" < "$dump" 2>/dev/null && echo "db imported: $DB" || echo "db data failed: $DB (objects needing other rights, e.g. DEFINER views, are skipped)"
    MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "INSERT IGNORE INTO mysql_dbs(user_id,db_name,db_user) VALUES($UIDN,'$DB','$DBU');" 2>/dev/null || true
    NDB=$((NDB+1))
  done
  # ---- mailboxes (etc/<domain>/passwd; $6$ preserved, $2y$ as-is, else reset) ----
  # old layout: $TMP/etc — modern: $W/homedir/etc (same relative tree inside).
  migrate_mail "$DOMAIN" "$DID"
  /usr/local/skypanel/scripts/email-reconcile.sh >/dev/null 2>&1 || true
  /usr/local/skypanel/scripts/autoreply-sync.sh >/dev/null 2>&1 || true
  # ---- forwarders (etc/valiases/<domain>, plain address targets only) ----
  migrate_fw "$DOMAIN" "$DID"
  # ---- sub/addon domains: migrated, not just reported ----
  # cPanel gives every mail-capable name an etc/<name>/ dir. Under the
  # imported account: <sub>.<DOMAIN> = subdomain, any other domain-shaped
  # name = addon. Each becomes a real panel domain (row + vhost + zone) with
  # files + mail + forwarders migrated like the main one. Anything unshaped
  # (parked pointers share the main docroot — nothing to move) is skipped.
  NSUB=0
  # migrated source docroots (homedir-relative): the orphan scan below reports
  # content dirs we did NOT migrate, so nothing is silently left behind
  MIGRATED="|"
  ETCROOT=""; [[ -d "$TMP/etc" ]] && ETCROOT="$TMP/etc"
  if [[ -z "$ETCROOT" && -n "${W:-}" && -d "$W/homedir/etc" ]]; then ETCROOT="$W/homedir/etc"; fi
  HOMEROOT=""
  if [[ -d "$TMP/home" ]]; then HOMEROOT="$TMP/home"; elif [[ -n "${W:-}" ]]; then HOMEROOT="$W/homedir"; fi
  MAINPHP="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT php_version FROM domains WHERE id=$DID LIMIT 1;" 2>/dev/null || true)"
  php_known "$MAINPHP" || MAINPHP="$PHP_DEFAULT"
  PKGMAX="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT p.max_domains FROM packages p JOIN users u ON u.package_id=p.id WHERE u.id=$UIDN LIMIT 1;" 2>/dev/null || true)"
  if [[ -n "$ETCROOT" && -n "$HOMEROOT" ]]; then
    # docroot resolver shared by every discovery pass: explicit candidate
    # paths only (never find — mail/ trees contain domain-named dirs too)
    resolve_src() { # <name> — echoes docroot dir or nothing
      local RN="$1" RLEFT RFIRST
      if [[ "$RN" == *".$DOMAIN" ]]; then RLEFT="${RN%%.$DOMAIN*}"; RFIRST="${RLEFT%%.*}"
        for cand in "$HOMEROOT/public_html/$RFIRST" "$HOMEROOT/$RN" "$HOMEROOT/public_html/$RLEFT"; do
          if [[ -d "$cand" ]]; then echo "$cand"; return 0; fi; done
      else
        for cand in "$HOMEROOT/$RN" "$HOMEROOT/public_html/$RN"; do
          if [[ -d "$cand" ]]; then echo "$cand"; return 0; fi; done
      fi
      return 1
    }
    # provision one sub/addon end to end (globals REPORT/NSUB updated)
    provision_site() { # <name> <kind> <srcdir>
      local EN="$1" KIND="$2" SRC="$3" HAVE NDID NDOC VOK NLEFT
      if [[ -n "$PKGMAX" && "$PKGMAX" =~ ^[0-9]+$ ]]; then
        HAVE="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT COUNT(*) FROM domains WHERE user_id=$UIDN;" 2>/dev/null || echo 0)"
        if [[ "${HAVE:-0}" -ge "$PKGMAX" ]]; then REPORT="$REPORT; $KIND $EN: package domain cap ($PKGMAX), skipped"; return 0; fi
      fi
      if MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT id FROM domains WHERE domain='$EN' LIMIT 1;" 2>/dev/null | grep -q .; then
        REPORT="$REPORT; $KIND $EN: name already hosted, skipped"; return 0
      fi
      NDOC="/home/$PUSER/$EN/public_html"
      MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "INSERT INTO domains(user_id,domain,docroot,php_version) VALUES($UIDN,'$EN','$NDOC','$MAINPHP');" 2>/dev/null || { REPORT="$REPORT; $KIND $EN: row failed, skipped"; return 0; }
      NDID="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT id FROM domains WHERE domain='$EN' LIMIT 1;" 2>/dev/null || true)"
      [[ -n "$NDID" ]] || { REPORT="$REPORT; $KIND $EN: row lookup failed, skipped"; return 0; }
      VOK=1
      /usr/local/skypanel/scripts/vhost-add.sh "$EN" "$PUSER" "$MAINPHP" >/dev/null 2>&1 || VOK=0
      /usr/local/skypanel/scripts/dns-add-zone.sh "$EN" >/dev/null 2>&1 || VOK=0
      /usr/local/skypanel/scripts/mail-autoconfig.sh "$EN" "$PUSER" >/dev/null 2>&1 || true
      [[ "$VOK" == "1" ]] || REPORT="$REPORT; $KIND $EN: vhost/zone setup needs review"
      NLEFT="$(ls -A "$NDOC" 2>/dev/null | grep -v -e '^index\.html$' -e '^\.well-known$' -e '^autodiscover$' || true)"
      if [[ -z "$NLEFT" ]]; then
        site_in "$SRC" "$NDOC" || echo "site files copy failed"
        echo "$KIND files: $(as_user "$PUSER" find "$NDOC" -type f | wc -l) into $EN"
      else echo "$KIND docroot not empty — $EN files SKIPPED"; fi
      migrate_mail "$EN" "$NDID"
      migrate_fw "$EN" "$NDID"
      MIGRATED="$MIGRATED${SRC#$HOMEROOT/}|"
      NSUB=$((NSUB+1)); echo "$KIND migrated: $EN"
    }
    # addon-claimed docroots (relative): userdata shadows pointing at the same
    # files are pointers, not sites — collect before the userdata pass
    CLAIMED="|"
    while IFS= read -r AN; do
      [[ "$AN" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || continue
      [[ "$AN" == "$DOMAIN" || "$AN" == *".$DOMAIN" ]] && continue
      ASRC="$(resolve_src "$AN" || true)"
      [[ -n "$ASRC" ]] && CLAIMED="$CLAIMED${ASRC#$HOMEROOT/}|"
    done < <(find "$ETCROOT" -maxdepth 1 -mindepth 1 -type d -printf '%f\n' 2>/dev/null | sort || true)
    # pass 1 — cPanel userdata (authoritative servername->docroot map):
    # subs only; the main domain, aliases and addon shadows are skipped
    UDATADIR="$(find "$TMP" "$W" -maxdepth 3 -type d -name userdata 2>/dev/null | head -1 || true)"
    if [[ -n "$UDATADIR" ]]; then
      while IFS= read -r UF; do
        SN="$(grep -m1 '^servername:' "$UF" 2>/dev/null | awk '{print $2}' | tr 'A-Z' 'a-z' || true)"
        DR="$(grep -m1 '^documentroot:' "$UF" 2>/dev/null | awk '{print $2}' || true)"
        [[ "$SN" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || continue
        [[ "$SN" == "$DOMAIN" ]] && continue
        [[ "$SN" == *".$DOMAIN" ]] || continue
        REL="${DR#/home/$CPUSER/}"
        [[ "$REL" != "$DR" && -d "$HOMEROOT/$REL" ]] || continue
        case "$CLAIMED" in *"|$REL|"*) continue;; esac
        USRC="$HOMEROOT/$REL"
        if [[ -n "$PKGMAX" && "$PKGMAX" =~ ^[0-9]+$ ]]; then
          HAVE="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT COUNT(*) FROM domains WHERE user_id=$UIDN;" 2>/dev/null || echo 0)"
          if [[ "${HAVE:-0}" -ge "$PKGMAX" ]]; then REPORT="$REPORT; subdomain $SN: package domain cap ($PKGMAX), skipped"; continue; fi
        fi
        if MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT id FROM domains WHERE domain='$SN' LIMIT 1;" 2>/dev/null | grep -q .; then
          REPORT="$REPORT; subdomain $SN: name already hosted, skipped"; continue
        fi
        provision_site "$SN" "subdomain" "$USRC"
        CLAIMED="$CLAIMED$REL|"
      done < <(find "$UDATADIR" -maxdepth 1 -type f ! -name '*.cache' ! -name '*_SSL' ! -name '*.yaml' ! -name 'main' ! -name 'cache.json' 2>/dev/null | sort || true)
    fi
    # candidates from mail-config dirs AND own-dir docroots (a mail-less
    # subdomain has no etc entry but may still own homedir/<sub>.<domain>/)
    CANDS="$(find "$ETCROOT" -maxdepth 1 -mindepth 1 -type d -printf '%f\n' 2>/dev/null | sort || true)"
    for od in "$HOMEROOT"/*."$DOMAIN"; do
      [[ -d "$od" ]] || continue
      od="$(basename "$od")"
      grep -qxF "$od" <<< "$CANDS" 2>/dev/null || CANDS="$CANDS
$od"
    done
    while IFS= read -r EN; do
      [[ "$EN" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || continue
      [[ "$EN" == "$DOMAIN" ]] && continue
      if [[ "$EN" == *".$DOMAIN" ]]; then KIND="subdomain"
      else KIND="addon"; fi
      SRC="$(resolve_src "$EN" || true)"
      if [[ -z "$SRC" ]]; then REPORT="$REPORT; $KIND $EN: no docroot found, skipped"; continue; fi
      provision_site "$EN" "$KIND" "$SRC"
    done <<< "$CANDS"
  fi
  # ---- report-only remainder: orphans, cron, DNS ----
  # site-looking content under public_html/ that cPanel never registered
  # (hand-placed dirs, failed creations): listed, never auto-migrated — a
  # wrong guess here would fabricate sites the user never had
  if [[ -n "$HOMEROOT" && -d "$HOMEROOT/public_html" ]]; then
    while IFS= read -r OD; do
      [[ -d "$OD" ]] || continue
      ON="$(basename "$OD")"
      case "$MIGRATED" in *"|public_html/$ON|"*) continue;; esac
      if [[ -f "$OD/index.html" || -f "$OD/index.php" || -f "$OD/wp-config.php" ]]; then
        REPORT="$REPORT; unregistered content at public_html/$ON — review manually"
      fi
    done < <(find "$HOMEROOT/public_html" -maxdepth 1 -mindepth 1 -type d 2>/dev/null | sort || true)
  fi
  if [[ -f "$TMP/cron/$CPUSER" || -f "$W/cron/$CPUSER" ]]; then REPORT="$REPORT; cron entries present — review before re-adding in panel"; fi
  # ---- autoresponders (homedir/.autorespond/<local>@<domain> + .json) ----
  # Subject/body map straight across; interval/start/stop do not (this panel
  # replies weekly per sender — noted once in the final report, not per box)
  ARDIR=""
  if [[ -d "$TMP/home/.autorespond" ]]; then ARDIR="$TMP/home/.autorespond";
  elif [[ -n "${W:-}" && -d "$W/homedir/.autorespond" ]]; then ARDIR="$W/homedir/.autorespond"; fi
  if [[ -n "$ARDIR" ]]; then
    while IFS= read -r AF; do
      [[ "$AF" == *.json ]] && continue
      BASE="$(basename "$AF")"
      ALOCAL="${BASE%@*}"; ADOM="${BASE#*@}"
      [[ "$ALOCAL" =~ ^[a-z0-9._-]{1,64}$ && "$ADOM" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || continue
      ADID="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT d.id FROM domains d JOIN users u ON u.id=d.user_id WHERE d.domain='$ADOM' AND u.username='$PUSER' LIMIT 1;" 2>/dev/null || true)"
      [[ -n "$ADID" ]] || continue
      BID="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT id FROM email_accounts WHERE domain_id=$ADID AND localpart='$ALOCAL' LIMIT 1;" 2>/dev/null || true)"
      [[ -n "$BID" ]] || { REPORT="$REPORT; auto-reply $BASE: no such mailbox, skipped"; continue; }
      SUBJ="$(grep -m1 -i '^subject:' "$AF" 2>/dev/null | sed 's/^[Ss][Uu][Bb][Jj][Ee][Cc][Tt]: *//; s/[[:space:]]*$//' | head -c 190 || true)"
      BODY="$(awk 'BEGIN{p=0} /^[[:space:]]*$/{p=1; next} p{print}' "$AF" 2>/dev/null | head -c 2000 || true)"
      [[ -n "${SUBJ// }" && -n "${BODY// }" ]] || { REPORT="$REPORT; auto-reply $BASE: empty subject/body, skipped"; continue; }
      SUBJ="$(sqlq "$SUBJ")"; BODY="$(sqlq "$BODY")"
      MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "UPDATE email_accounts SET autoresp_enabled=1, autoresp_subject='$SUBJ', autoresp_text='$BODY' WHERE id=$BID;" 2>/dev/null || continue
      NRESP=$((NRESP+1))
    done < <(find "$ARDIR" -maxdepth 1 -type f 2>/dev/null | sort || true)
    if [[ "$NRESP" -gt 0 ]]; then
      /usr/local/skypanel/scripts/autoreply-sync.sh >/dev/null 2>&1 || true
      REPORT="$REPORT; auto-replies: $NRESP (this panel replies weekly per sender; cPanel intervals not carried)"
    fi
  fi
  # ---- user filters (homedir/etc/<domain>/<local>/filter, Exim syntax) ----
  # Mapped onto sieve filters: single-condition contains on subject/from/to;
  # save->folder, deliver->redirect, finish->discard. Multi-condition, exact
  # (`is`), regex and exotic actions are skipped with cause (no silent
  # misfiling — a guessed mapping delivers to the wrong place).
  if [[ -n "$ETCROOT" ]]; then
    while IFS= read -r FF; do
      FDIR="$(dirname "$FF")"; FLOCAL="$(basename "$FDIR")"; FDOM="$(basename "$(dirname "$FDIR")")"
      [[ "$FLOCAL" =~ ^[a-z0-9._-]{1,64}$ && "$FDOM" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || continue
      FDID="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT d.id FROM domains d JOIN users u ON u.id=d.user_id WHERE d.domain='$FDOM' AND u.username='$PUSER' LIMIT 1;" 2>/dev/null || true)"
      [[ -n "$FDID" ]] || continue
      FBID="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT id FROM email_accounts WHERE domain_id=$FDID AND localpart='$FLOCAL' LIMIT 1;" 2>/dev/null || true)"
      [[ -n "$FBID" ]] || { REPORT="$REPORT; filter $FLOCAL@$FDOM: no such mailbox, skipped"; continue; }
      INCOND=0; NEST=0; CFIELD=""; CMATCH=""; CTEXT=""; CACT=""; CTGT=""; SKIPR=""
      save_rule() { # insert the just-parsed rule, then reset for the next one
        if [[ -z "$SKIPR" && -n "$CFIELD" && -n "$CACT" ]]; then
          local TT="$CTEXT"; [[ "${#TT}" -le 160 ]] || TT="${TT:0:160}"
          TT="$(sqlq "$TT")"; local TG; TG="$(sqlq "$CTGT")"
          if [[ "$CACT" != discard && -z "$TG" ]]; then REPORT="$REPORT; filter $FLOCAL@$FDOM: empty target, skipped"
          else
            local DUP="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT COUNT(*) FROM email_filters WHERE domain_id=$FDID AND localpart='$FLOCAL' AND field='$CFIELD' AND match_text='$TT' AND action='$CACT' AND target='$TG';" 2>/dev/null || echo 0)"
            if [[ "${DUP:-0}" == "0" ]]; then
              MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "INSERT INTO email_filters(user_id,domain_id,localpart,field,match_text,action,target) VALUES($UIDN,$FDID,'$FLOCAL','$CFIELD','$TT','$CACT','$TG');" 2>/dev/null && NFILT=$((NFILT+1)) || true
            fi
          fi
        elif [[ -n "$SKIPR" ]]; then
          REPORT="$REPORT; filter $FLOCAL@$FDOM: skipped ($SKIPR)"
        fi
        CFIELD=""; CMATCH=""; CTEXT=""; CACT=""; CTGT=""; SKIPR=""; NEST=0
      }
      while IFS= read -r FL || [[ -n "$FL" ]]; do
        L="$(echo "$FL" | sed 's/^[[:space:]]*//; s/[[:space:]]*$//')"
        [[ -z "$L" || "$L" == \#* ]] && continue
        if [[ "$L" =~ ^if[[:space:]] ]]; then
          if [[ "$INCOND" != "0" ]]; then SKIPR="nested if"; NEST=$((NEST+1)); continue; fi
          INCOND=1
          if [[ "$L" =~ ^if[[:space:]]+\$header_(subject|from|to):[[:space:]]+contains[[:space:]]+\"([^\"]+)\"[[:space:]]*$ ]]; then
            CFIELD="${BASH_REMATCH[1]}"; CMATCH="contains"; CTEXT="${BASH_REMATCH[2]}"
          else SKIPR="non-contains/exact/multi condition"; fi
        elif [[ "$L" == "then" ]]; then
          [[ "$INCOND" == "1" ]] || { SKIPR="stray then"; }
          INCOND=2
        elif [[ "$L" == "endif" ]]; then
          if [[ "$NEST" -gt 0 ]]; then NEST=$((NEST-1)); continue; fi
          save_rule
          INCOND=0
        elif [[ "$INCOND" == "2" ]]; then
          if [[ "$L" =~ ^(unseen[[:space:]]+)?save[[:space:]]+(.+)$ ]]; then
            SP="${BASH_REMATCH[2]}"; SP="${SP%/}"; SP="${SP#/}"
            FN="$(basename "$SP")"; FN="${FN#.}"
            if [[ "$FN" =~ ^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)*$ ]]; then CACT="folder"; CTGT="$FN"
            else SKIPR="bad folder '$FN'"; fi
          elif [[ "$L" =~ ^(unseen[[:space:]]+)?deliver[[:space:]]+([^[:space:]]+)[[:space:]]*$ ]]; then
            DA="${BASH_REMATCH[2]}"
            if [[ "$DA" =~ ^[a-z0-9._-]+@[a-z0-9.-]+\.[a-z]{2,}$ ]]; then CACT="redirect"; CTGT="$DA"
            else SKIPR="bad redirect '$DA'"; fi
          elif [[ "$L" =~ ^(seen[[:space:]]+)?finish[[:space:]]*$ ]]; then CACT="discard"; CTGT=""
          else SKIPR="unsupported action"; fi
        fi
      done < "$FF"
      # unclosed trailing rule (missing endif) still counts if complete
      if [[ -n "$CFIELD" && -n "$CACT" && -z "$SKIPR" ]]; then save_rule; fi
    done < <(find "$ETCROOT" -mindepth 3 -maxdepth 3 -type f -name filter 2>/dev/null | sort || true)
    if [[ "$NFILT" -gt 0 ]]; then
      /usr/local/skypanel/scripts/email-filters-sync.sh >/dev/null 2>&1 || true
      REPORT="$REPORT; filters: $NFILT"
    fi
  fi
  REPORT="$REPORT; DNS: point/delegate $DOMAIN (and migrated subs/addons) here manually"
  # imported dirs are 755: close them (and the rest of the home) to other accounts
  "$(dirname "$(readlink -f "$0")")/home-perms.sh" "$PUSER" >/dev/null 2>&1 || true
  echo "=== import done: files=$NFILES dbs=$NDB mailboxes=$NMAIL forwarders=$NFWD responders=$NRESP filters=$NFILT$REPORT ==="
  rm -f "$RTARBALL"
) &
echo "import started in background — watch Logs → software"
