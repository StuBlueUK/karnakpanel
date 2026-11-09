#!/usr/bin/env bash
# transfer-pull.sh — Account Transfer, TARGET side (docs/account-transfer-spec.md).
#   accounts <pairing>   the source's movable accounts, as JSON (sync)
#   start <job>          DETACHED: move the job's queued accounts one by one
#   run <job>            (internal: the detached worker)
#   cutover <item>       DETACHED: switch a copied ("ready") account over to
#                        this server (cut_one below); cutrun = its worker
#   cutundo <item>       a cutover whose worker died (OOM, reboot) before the
#                        old server passed mail on: put the old server back
# Everything travels over the pairing's own SSH link (kt_ssh: its one key,
# pinned host key, forced read-only command on the source). Progress lands
# in transfer_items (the Migrate page polls it); the full log goes to
# /var/log/karnakpanel/transfer.log. The source copy is never changed here.
# Per account: read rows → checks → rows + system user + logins → sites/zones
# → files → databases → mail + DKIM → settings → checks → "ready".
# Cron jobs and app daemons are imported but NOT started: they start at
# cutover, so nothing runs twice while both copies are live.
set -euo pipefail
HERE="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
. "$HERE/transfer-common.sh"
. "$HERE/panel-php.sh"
. "$HERE/db-caps.sh"         # DB_CAPS: per-user MariaDB caps (admin-set)
DB_CAPS="${DB_CAPS:-$(db_caps_clause)}"
. "$HERE/php-versions.sh"
S="$HERE"
# 022, not 077: the system scripts run from here (cron-sync, list-sync,
# vhost/zone/FPM writers, ssl-issue-bg) write files Exim, BIND, OLS and FPM
# must read. Private things stay private by place: KT_DIR is 700 and every
# work dir is install -m 700; the log is made 600.
umask 022
[[ -d "$KT_DIR" ]] && chmod 700 "$KT_DIR"
install -d -m 750 "$(dirname "$KT_LOG")"; [[ -f "$KT_LOG" ]] || install -m 600 /dev/null "$KT_LOG"
CMD="${1:-}"; ARG="${2:-}"
PASS="$(cat /root/.skypanel-dbpass 2>/dev/null || true)"
[[ -n "$PASS" ]] || { echo "no panel db pass"; exit 1; }
sql() { MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -B -e "$1"; }
clean() { local s="${1//\'/’}"; s="${s//\\/}"; printf '%s' "${s:0:250}"; }

pairing_ok() {
  [[ "$1" =~ $KT_ID_RE && -f "$KT_DIR/$1/meta" && -f "$KT_DIR/$1/id_ed25519" ]] || { echo "refused: no such pairing here"; exit 3; }
  [[ "$(kt_meta state "$KT_DIR/$1/meta")" == linked ]] || { echo "refused: pairing not linked yet (paste the SOURCE code first)"; exit 3; }
  kt_exp_ok "$(kt_meta exp "$KT_DIR/$1/meta")" || { echo "refused: pairing expired: pair again"; exit 3; }
}

find_pair() {  # <source ip> → a linked, unexpired pairing to it (the copy's own may have expired)
  local d id m
  for d in "$KT_DIR"/*/; do
    id="$(basename "$d")"; m="$d/meta"
    [[ "$id" =~ $KT_ID_RE && -f "$m" && -f "$d/id_ed25519" ]] || continue
    [[ "$(kt_meta state "$m")" == linked && "$(kt_meta src_ip "$m")" == "$1" ]] || continue
    kt_exp_ok "$(kt_meta exp "$m")" || continue
    echo "$id"; return 0
  done
  return 1
}

case "$CMD" in
accounts)
  pairing_ok "$ARG"
  OUT="$(timeout 60 bash -c '. "$1"; kt_ssh "$2" list' _ "$HERE/transfer-common.sh" "$ARG" 2>&1)" || { echo "refused: source didn't answer: ${OUT:0:200}"; exit 4; }
  # pass on only well-formed JSON
  php -r '$j = json_decode(stream_get_contents(STDIN), true); if (!is_array($j)) exit(1); echo json_encode($j, JSON_UNESCAPED_SLASHES), "\n";' <<<"$OUT" || { echo "refused: odd answer from source"; exit 4; }
  exit 0 ;;
start)
  [[ "$ARG" =~ ^[0-9]{1,9}$ ]] || { echo "bad job"; exit 1; }
  P="$(sql "SELECT pairing FROM transfer_jobs WHERE id=$ARG AND state IN ('queued','failed','done')")"
  [[ -n "$P" ]] || { echo "refused: no such job, or it is already running"; exit 3; }
  pairing_ok "$P"
  sql "UPDATE transfer_jobs SET state='running', updated_at=NOW() WHERE id=$ARG"
  setsid -f "$0" run "$ARG" >> "$KT_LOG" 2>&1 < /dev/null
  echo "started job $ARG"; exit 0 ;;
cutover)
  [[ "$ARG" =~ ^[0-9]{1,9}$ ]] || { echo "bad item"; exit 1; }
  IFS=$'\t' read -r STG SIP NU <<<"$(sql "SELECT i.stage, j.source_ip, IFNULL(i.new_user_id,'') FROM transfer_items i JOIN transfer_jobs j ON j.id=i.job_id WHERE i.id=$ARG")"
  [[ "${STG:-}" == ready && "${NU:-}" =~ ^[0-9]+$ ]] || { echo "refused: only a copied (ready) account can be cut over"; exit 3; }
  kt_ip_ok "${SIP:-}" || { echo "refused: no source address on that transfer"; exit 3; }
  find_pair "$SIP" > /dev/null || { echo "refused: no live pairing with $SIP: pair the two servers again (codes 1 and 2), then cut over"; exit 3; }
  [[ "$(sql "UPDATE transfer_items SET stage='cut-queued', pct=0, detail='cutover queued', updated_at=NOW() WHERE id=$ARG AND stage='ready'; SELECT ROW_COUNT()")" == 1 ]] \
    || { echo "refused: already cutting over"; exit 3; }
  setsid -f "$0" cutrun "$ARG" >> "$KT_LOG" 2>&1 < /dev/null
  echo "started cutover $ARG"; exit 0 ;;
cutundo)
  [[ "$ARG" =~ ^[0-9]{1,9}$ ]] || { echo "bad item"; exit 1; }
  IFS=$'\t' read -r STG SIP U <<<"$(sql "SELECT i.stage, j.source_ip, i.username FROM transfer_items i JOIN transfer_jobs j ON j.id=i.job_id WHERE i.id=$ARG")"
  [[ "${STG:-}" == cut-* && "${U:-}" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "refused: that account is not mid-cutover"; exit 3; }
  # a live worker holds the lock for the whole cutover: free lock = it died
  exec 8>/run/karnak-transfer.lock
  flock -n 8 || { echo "refused: a transfer or cutover is still running here: wait for it"; exit 3; }
  PAIR="$(find_pair "${SIP:-}")" || { echo "refused: no live pairing with $SIP: pair again, then try this again"; exit 3; }
  if OUT="$(kt_ssh "$PAIR" cut-undo "$U" 2>&1)"; then
    sql "UPDATE transfer_items SET stage='ready', pct=100, detail='cutover stopped (its worker died) · the old server is live again', updated_at=NOW() WHERE id=$ARG"
    kt_log "cutundo item $ARG ($U): $OUT"; echo "ok"; exit 0
  fi
  kt_log "cutundo item $ARG ($U): $OUT"
  echo "refused: the old server said: $(grep -o 'refused: .*' <<<"$OUT" | head -1 | cut -c10-200)"; exit 3 ;;
run|cutrun) [[ "$ARG" =~ ^[0-9]{1,9}$ ]] || exit 1 ;;
*) echo "usage: transfer-pull.sh accounts <pairing> | start <job> | cutover <item> | cutundo <item>"; exit 1 ;;
esac

# ------------------------------------------------------------------ worker
exec 8>/run/karnak-transfer.lock
if [[ "$CMD" == cutrun ]]; then
  CIT="$ARG"; JOB="$(sql "SELECT job_id FROM transfer_items WHERE id=$CIT")"
  [[ "$JOB" =~ ^[0-9]+$ ]] || exit 1
  if ! flock -n 8; then
    sql "UPDATE transfer_items SET detail='cutover waiting: another transfer is running here', updated_at=NOW() WHERE id=$CIT"
    flock 8
  fi
  PAIR="$(find_pair "$(sql "SELECT source_ip FROM transfer_jobs WHERE id=$JOB")")" \
    || { sql "UPDATE transfer_items SET stage='ready', detail='cutover not started: the pairing ended: pair again, then cut over', updated_at=NOW() WHERE id=$CIT"; exit 1; }
else
JOB="$ARG"
# one transfer at a time: a second job waits its turn (not 'queued', so a
# second Start can't spawn a duplicate waiter) and runs when the first ends
if ! flock -n 8; then
  sql "UPDATE transfer_jobs SET state='waiting', updated_at=NOW() WHERE id=$JOB"
  sql "UPDATE transfer_items SET detail='waiting: another transfer is running here', updated_at=NOW() WHERE job_id=$JOB AND stage='queued'"
  flock 8
  sql "UPDATE transfer_jobs SET state='running', updated_at=NOW() WHERE id=$JOB"
fi
PAIR="$(sql "SELECT pairing FROM transfer_jobs WHERE id=$JOB")"
fi
[[ "$PAIR" =~ $KT_ID_RE ]] || exit 1
say() { printf '%s job %s: %s\n' "$(date -u '+%F %T')" "$JOB" "$*"; }
ssh_src() { kt_ssh "$PAIR" "$@"; }
RSH="$(kt_rsync_e "$PAIR")"
SRC="$KT_USER@$(kt_meta src_ip "$KT_DIR/$PAIR/meta")"

prog() {  # item stage pct detail
  sql "UPDATE transfer_items SET stage='$2', pct=$3, detail='$(clean "$4")', updated_at=NOW() WHERE id=$1" || true
}
bytes() { # item done total rate
  sql "UPDATE transfer_items SET bytes_done=$2, bytes_total=$3, rate_bps=$4, updated_at=NOW() WHERE id=$1" || true
}
# rsync with a live bar: --info=progress2 lines "  1,234,567  45%  10.2MB/s  0:01:02"
# mapped into [lo..hi] of the item's percentage. Args: item lo hi label total -- rsync args
# (stage shown: $RB_STAGE, default files)
rsync_bar() {
  local it="$1" lo="$2" hi="$3" label="$4" total="$5" last=0 now b p rate; shift 6
  local rc=0
  { rsync "$@" --info=progress2 --no-inc-recursive -e "$RSH" 2>"$W/rsync.err" | stdbuf -oL tr '\r' '\n' | while IFS= read -r l; do
      [[ "$l" =~ ^\ *([0-9,]+)\ +([0-9]{1,3})%\ +([0-9.]+)([kMG]?B)/s ]] || continue
      now="$(date +%s)"; (( now - last >= 2 )) || continue; last="$now"
      b="${BASH_REMATCH[1]//,/}"; p="${BASH_REMATCH[2]}"
      case "${BASH_REMATCH[4]}" in kB) rate=1000;; MB) rate=1000000;; GB) rate=1000000000;; *) rate=1;; esac
      rate="$(awk -v r="${BASH_REMATCH[3]}" -v m="$rate" 'BEGIN{printf "%d", r*m}')"
      bytes "$it" "$b" "$total" "$rate"
      prog "$it" "${RB_STAGE:-files}" $(( lo + (hi - lo) * p / 100 )) "$label: $p%"
    done; } || rc=$?
  return "$rc"
}

# rsync -a as root keeps the PEER's owners (by name), setuid bits and device
# nodes: a compromised source could ship a root-owned setuid shell into the
# home. Owners map to the account (or its own FTP logins, which own their
# uploads), groups to its group; set-id bits, devices and specials never land.
# Sets HOMESYNC / MAILSYNC (rsync option arrays). Args: user export.json
sync_opts() {
  local U="$1" DOC="$2" UG M="" F
  UG="$(id -gn "$U" 2>/dev/null)" || return 1
  [[ "$UG" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] || return 1
  while IFS= read -r F; do
    [[ "$F" =~ ^${U}_[a-z0-9_]{1,32}$ ]] && id "$F" > /dev/null 2>&1 && M="$M,$F:$F"
  done < <(php -r '$d=json_decode(file_get_contents($argv[1]),true); foreach($d["tables"]["ftp_accounts"]??[] as $f) echo $f["ftp_user"],"\n";' "$DOC")
  HOMESYNC=(--no-devices --no-specials --chmod=ug-s "--usermap=$U:$U$M,*:$U" "--groupmap=*:$UG")
  MAILSYNC=(--no-devices --no-specials --chmod=ug-s "--usermap=*:vmail" "--groupmap=*:vmail")
}

# The peer names the database and its login; both then get root-run CREATE /
# GRANT / ALTER USER ... IDENTIFIED with the PEER's hash. So: the pair must be
# one the import registered for THIS account, never a system schema, and the
# login, if it already exists in MariaDB, may hold nothing beyond USAGE and
# this account's own databases (root, debian-sys-maint, skypanel, roundcube,
# mariadb.sys and every other tenant's login all fail that). Args: user db login
db_login_ok() {
  local U="$1" DB="$2" DBU="$3" OWN G EX
  case "$DB" in mysql|information_schema|performance_schema|sys|skypanel|roundcube*|phpmyadmin) return 1;; esac
  case "$DBU" in root|mysql|mariadb.sys|skypanel|debian-sys-maint|roundcube*|phpmyadmin|pma) return 1;; esac
  OWN="$(sql "SELECT m.db_name FROM mysql_dbs m JOIN users u ON u.id=m.user_id WHERE u.username='$U'")" || return 1
  sql "SELECT 1 FROM mysql_dbs m JOIN users u ON u.id=m.user_id WHERE u.username='$U' AND m.db_name='$DB' AND m.db_user='$DBU'" | grep -q 1 || return 1
  EX="$(mysql -uroot -N -B -e "SELECT COUNT(*) FROM mysql.global_priv WHERE User='$DBU' AND Host='localhost'" 2>/dev/null)" || return 1
  [[ "$EX" =~ ^[0-9]+$ ]] || return 1
  (( EX > 0 )) || return 0   # a new login: the import's own
  while IFS= read -r G; do
    [[ "$G" == *"WITH GRANT OPTION"* ]] && return 1
    [[ "$G" =~ ^GRANT\ USAGE\ ON\ \*\.\*\ TO\  ]] && continue
    if [[ "$G" =~ ^GRANT\ ALL\ PRIVILEGES\ ON\ \`([^\`]+)\`\.\*\ TO\ [^\ ]+$ ]] \
       && grep -qxF -- "${BASH_REMATCH[1]//\\/}" <<< "$OWN"; then continue; fi
    return 1
  done < <(mysql -uroot -N -B -e "SHOW GRANTS FOR '$DBU'@'localhost'" 2>/dev/null || echo REFUSE)
  return 0
}

# every database of the account: dump on the source, load here as the db's
# own login, then its moved password hash back. Args: item user stage lo hi export.json
# (re-run at cutover: the dump's DROP TABLE IF EXISTS replaces the first copy)
copy_dbs() {
  local IT="$1" U="$2" ST="$3" LO="$4" HI="$5" DOC="$6"
  ssh_src dbauth "$U" > "$W/dbauth" 2>/dev/null || true
  local DB DBU PL AU FINAL TP NDB=0 TOT GOT WANT BAD=0
  TOT="$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo count($d["tables"]["mysql_dbs"]??[]);' "$DOC")"
  while IFS=$'\t' read -r DB DBU; do
    [[ "$DB" =~ ^[a-z0-9][a-z0-9_]{0,63}$ && "$DBU" =~ ^[a-z0-9_]{1,64}$ ]] || { rep "db $DB: odd name, skipped"; continue; }
    db_login_ok "$U" "$DB" "$DBU" || { rep "db $DB: login $DBU is not this account's own (system or another account's login), skipped"; BAD=$((BAD+1)); continue; }
    prog "$IT" "$ST" $(( LO + (HI - LO) * NDB / (TOT > 0 ? TOT : 1) )) "database $DB"
    # the moved login: same password as on the source (hash only, never seen)
    PL="$(awk -F'\t' -v u="$DBU" '$1==u{print $2; exit}' "$W/dbauth")"; AU="$(awk -F'\t' -v u="$DBU" '$1==u{print $3; exit}' "$W/dbauth")"
    if [[ "$PL" == mysql_native_password && "$AU" =~ ^\*[0-9A-F]{40}$ ]]; then FINAL="IDENTIFIED BY PASSWORD '$AU'"
    elif [[ "$PL" == ed25519 && "$AU" =~ ^[A-Za-z0-9+/]{43}$ ]]; then FINAL="IDENTIFIED VIA ed25519 USING '$AU'"
    else FINAL=""; rep "TODO db login $DBU: password not portable (${PL:-unknown}): set a new one in Databases and in the site's config"; fi
    # the dump is customer SQL: it runs as the database's OWN login (rights
    # on its own databases only, never MariaDB root), with a one-off
    # password; its triggers/views/routines keep that login as DEFINER.
    TP="$(openssl rand -hex 16)"
    mysql -uroot <<<"CREATE DATABASE IF NOT EXISTS \`$DB\` CHARACTER SET utf8mb4; CREATE USER IF NOT EXISTS '$DBU'@'localhost'; ALTER USER '$DBU'@'localhost' IDENTIFIED BY '$TP'; GRANT ALL PRIVILEGES ON \`$DB\`.* TO '$DBU'@'localhost';" 2>"$W/err" \
      || { rep "db $DB: create failed: $(head -c 150 "$W/err")"; BAD=$((BAD+1)); continue; }
    if ssh_src dbdump "$U" "$DB" 2>"$W/dumperr" | tee >(grep -c '^CREATE TABLE' > "$W/tables.$DB" || true) \
         | sed -E "/^INSERT /! s/DEFINER=\`[^\`]+\`@\`[^\`]+\`/DEFINER=\`$DBU\`@\`localhost\`/g" \
         | MYSQL_PWD="$TP" mysql -u"$DBU" "$DB" 2>"$W/err"; then
      sleep 0.3
      GOT="$(mysql -uroot -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB' AND TABLE_TYPE='BASE TABLE'")"
      WANT="$(cat "$W/tables.$DB" 2>/dev/null || echo '?')"
      rep "db $DB: copied, $GOT of $WANT tables$([[ "$GOT" == "$WANT" ]] || echo ' (CHECK)')"
    else rep "db $DB: copy FAILED: $(head -c 150 "$W/dumperr") $(head -c 150 "$W/err")"; BAD=$((BAD+1)); fi
    # real password back (or a random one nobody knows), then the caps
    [[ -n "$FINAL" ]] || FINAL="IDENTIFIED BY '$(openssl rand -hex 16)'"
    mysql -uroot <<<"ALTER USER '$DBU'@'localhost' $FINAL; GRANT USAGE ON *.* TO '$DBU'@'localhost' WITH $DB_CAPS; FLUSH PRIVILEGES;" 2>"$W/err" \
      || rep "db login $DBU: couldn't restore its password: $(head -c 150 "$W/err")"
    rm -f "$W/tables.$DB"
    NDB=$((NDB+1))
  done < <(php -r '$d=json_decode(file_get_contents($argv[1]),true); foreach($d["tables"]["mysql_dbs"]??[] as $m) echo $m["db_name"],"\t",$m["db_user"],"\n";' "$DOC")
  shred -u "$W/dbauth" 2>/dev/null || rm -f "$W/dbauth"
  (( BAD == 0 ))
}

move_one() {  # item username
  local IT="$1" U="$2" NEWUID="" DOC
  W="$KT_DIR/$PAIR/work/$U"; install -d -m 700 "$W"; DOC="$W/export.json"
  REPORT="$W/report.txt"; : > "$REPORT"
  rep() { echo "$*" >> "$REPORT"; say "$U: $*"; }
  fail() { rep "FAILED: $*"; prog "$IT" failed 0 "$*"; return 1; }

  # 1. read + checks -------------------------------------------------------
  prog "$IT" checks 1 "reading the account from the source"
  ssh_src export "$U" > "$DOC" 2>"$W/err" || { fail "source refused the export: $(head -c 150 "$W/err")"; return 1; }
  local CL; CL="$(KP_F="$DOC" PANEL_PHP 'try { $d = json_decode(file_get_contents(getenv("KP_F")), true, 64, JSON_THROW_ON_ERROR); echo implode("; ", Transfer::clashes($d)); } catch (Throwable $e) { echo "bad export: ", $e->getMessage(); }' 2>&1)"
  [[ -z "$CL" ]] || { fail "can't move: $CL"; return 1; }
  local PHPS PHPV KIDS; PHPS="$(php -r '$d=json_decode(file_get_contents($argv[1]),true); foreach(array_unique(array_column($d["tables"]["domains"]??[],"php_version")) as $v) echo $v,"\n";' "$DOC")"
  for v in $PHPS; do php_known "$v" || { fail "PHP $v is used by this account but not installed here (PHP page)"; return 1; }; done
  PHPV="$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo $d["package"]["php_version"] ?? "";' "$DOC")"
  php_known "$PHPV" || PHPV="$PHP_DEFAULT"
  KIDS="$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo (int)($d["package"]["max_children"] ?? 10);' "$DOC")"
  [[ "$KIDS" =~ ^[0-9]{1,3}$ && "$KIDS" -ge 1 ]] || KIDS=10
  prog "$IT" checks 2 "measuring files"
  local HB FREE
  HB="$(rsync -a --dry-run --stats --exclude=/.tmp/ -e "$RSH" --rsync-path="rsync-home $U" "$SRC:./" "$W/dry/" 2>/dev/null | awk -F': ' '/^Total file size/{gsub(/[^0-9]/,"",$2); print $2}')"
  [[ "$HB" =~ ^[0-9]+$ ]] || { fail "couldn't read the source home folder"; return 1; }
  FREE="$(df -B1 --output=avail /home | tail -1 | tr -d ' ')"
  (( FREE > HB * 12 / 10 + 1073741824 )) || { fail "not enough disk here: needs $(( HB / 1048576 * 12 / 10 )) MB + 1 GB spare, $(( FREE / 1048576 )) MB free"; return 1; }
  bytes "$IT" 0 "$HB" 0
  rep "home folder: $(( HB / 1048576 )) MB"

  # 2. rows + system account + logins -------------------------------------
  prog "$IT" account 4 "creating the account"
  NEWUID="$(KP_F="$DOC" PANEL_PHP 'echo Transfer::importFile(getenv("KP_F"));' 2>&1)" || true
  [[ "$NEWUID" =~ ^[0-9]+$ ]] || { fail "panel rows: ${NEWUID:0:200}"; return 1; }
  sql "UPDATE transfer_items SET new_user_id=$NEWUID WHERE id=$IT"
  KP_I="$NEWUID" KP_T="$IT" PANEL_PHP 'Database::query("UPDATE transfer_items SET held=? WHERE id=?", [Transfer::holdJobs((int)getenv("KP_I")), (int)getenv("KP_T")]);' \
    || { fail "couldn't pause the moved cron jobs"; return 1; }
  if ! printf '%s\0' "$(openssl rand -hex 16)" | "$S/create-account.sh" "$U" "$PHPV" @stdin "$KIDS" > "$W/create.out" 2>&1; then
    KP_I="$NEWUID" PANEL_PHP 'Transfer::purgeRows((int)getenv("KP_I"));' || true
    sql "UPDATE transfer_items SET new_user_id=NULL WHERE id=$IT"
    fail "system account: $(tail -1 "$W/create.out")"; return 1
  fi
  ssh_src shadow "$U" > "$W/shadow" 2>/dev/null || true
  local L H NFTP=0
  while IFS=$'\t' read -r L H; do
    [[ "$L" =~ ^[a-z][a-z0-9_.-]{0,63}$ && "$H" =~ ^(\$(1|5|6|y|2[aby])\$[./A-Za-z0-9\$=]{8,200}|[!*]{1,2})$ ]] || continue
    if [[ "$L" != "$U" ]]; then
      local FH; FH="$(php -r '$d=json_decode(file_get_contents($argv[1]),true); foreach($d["tables"]["ftp_accounts"]??[] as $f) if($f["ftp_user"]===$argv[2]) echo $f["homedir"];' "$DOC" "$L")"
      [[ -n "$FH" ]] || continue
      printf '%s\0' "$(openssl rand -hex 16)" | "$S/ftp-add.sh" "$L" @stdin "$FH" > /dev/null 2>&1 || { rep "FTP login $L: couldn't create"; continue; }
      NFTP=$((NFTP+1))
    fi
    # the moved hash, on stdin: same password as before, never seen here
    [[ "$H" == \$* ]] && printf '%s:%s\n' "$L" "$H" | chpasswd -e
  done < "$W/shadow"
  shred -u "$W/shadow" 2>/dev/null || rm -f "$W/shadow"
  rep "account created (PHP $PHPV), $NFTP FTP login(s), passwords kept"

  # 3. sites + zones (empty docroots) --------------------------------------
  prog "$IT" sites 6 "creating sites and DNS zones"
  KP_I="$NEWUID" PANEL_PHP 'foreach (Transfer::rebuildSites((int)getenv("KP_I")) as $l) echo $l, "\n";' >> "$REPORT" 2>&1 || rep "sites: some steps failed"

  # 4. files ----------------------------------------------------------------
  prog "$IT" files 8 "copying files"
  sync_opts "$U" "$DOC" || { fail "file copy: can't read $U's group"; return 1; }
  rsync_bar "$IT" 8 70 "files" "$HB" -- -aH --delete --exclude=/.tmp/ "${HOMESYNC[@]}" --rsync-path="rsync-home $U" "$SRC:./" "/home/$U/" \
    || { fail "file copy: $(tail -2 "$W/rsync.err" | tr '\n' ' ')"; return 1; }
  bytes "$IT" "$HB" "$HB" 0
  "$S/home-perms.sh" "$U" > /dev/null 2>&1 || rep "home-perms: warning"
  rep "files copied"

  # 5. databases ------------------------------------------------------------
  prog "$IT" databases 72 "copying databases"
  copy_dbs "$IT" "$U" databases 72 85 "$DOC" || true   # each failure is in the report

  # 6. mail + DKIM ----------------------------------------------------------
  prog "$IT" mail 86 "copying mail"
  local D SEL
  while IFS= read -r D; do
    [[ "$D" =~ ^[a-z0-9.-]+$ ]] || continue
    # everything lands as vmail (mail is never anyone else's), modes as on the source
    if rsync -aH --delete "${MAILSYNC[@]}" -e "$RSH" --rsync-path="rsync-mail $U $D" "$SRC:./" "/var/mail/virtual/$D/" 2>"$W/err"; then rep "mail $D: copied"
    elif ! grep -q "no mail store" "$W/err"; then rep "mail $D: copy FAILED: $(head -c 150 "$W/err")"; fi
    if ssh_src dkim "$U" "$D" > "$W/dkim" 2>/dev/null && SEL="$(sed -n '1s/^SELECTOR \([a-z0-9_-]\{1,32\}\)$/\1/p' "$W/dkim")" && [[ -n "$SEL" ]]; then
      sed 1d "$W/dkim" > "$W/dkim.pem"
      if openssl pkey -in "$W/dkim.pem" -noout 2>/dev/null; then
        mkdir -p /etc/exim4/dkim   # as dkim-gen.sh: never re-mode an existing dir (Exim must traverse it)
        [[ -f "/etc/exim4/dkim/$D.$SEL.pem" ]] || install -m 640 -o root -g Debian-exim "$W/dkim.pem" "/etc/exim4/dkim/$D.$SEL.pem"
        "$S/dkim-gen.sh" "$D" "$SEL" > /dev/null 2>&1 && rep "mail $D: DKIM key kept ($SEL)" || rep "mail $D: DKIM map failed"
      fi
      shred -u "$W/dkim" "$W/dkim.pem" 2>/dev/null || rm -f "$W/dkim" "$W/dkim.pem"
    fi
  done < <(php -r '$d=json_decode(file_get_contents($argv[1]),true); foreach($d["tables"]["domains"]??[] as $x) echo $x["domain"],"\n";' "$DOC")
  "$S/email-reconcile.sh" > /dev/null 2>&1 || rep "email-reconcile: warning"
  "$S/email-filters-sync.sh" > /dev/null 2>&1 || true
  "$S/list-sync.sh" > /dev/null 2>&1 || true
  "$S/autoreply-sync.sh" > /dev/null 2>&1 || true

  # 7. per-domain settings ---------------------------------------------------
  prog "$IT" settings 92 "applying site settings"
  KP_I="$NEWUID" PANEL_PHP 'foreach (Transfer::applySettings((int)getenv("KP_I")) as $l) echo $l, "\n";' >> "$REPORT" 2>&1 || rep "settings: some steps failed"
  local NC ND
  NC="$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo count($d["tables"]["cron_jobs"]??[]);' "$DOC")"
  ND="$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo count($d["tables"]["app_daemons"]??[]);' "$DOC")"
  (( NC + ND == 0 )) || rep "NOTE $NC cron job(s) and $ND app daemon(s) are copied but paused: they start here at cutover"

  # 8. checks -----------------------------------------------------------------
  prog "$IT" verify 96 "checking the copy"
  local CODE BAD=0
  while IFS= read -r D; do
    [[ "$D" =~ ^[a-z0-9.-]+$ ]] || continue
    CODE="$(curl -s -o /dev/null -m 20 -w '%{http_code}' --resolve "$D:80:127.0.0.1" "http://$D/" || echo 000)"
    case "$CODE" in 2??|3??|401|403) rep "check http://$D/ → $CODE";; *) rep "check http://$D/ → $CODE (look at this site)"; BAD=1;; esac
  done < <(php -r '$d=json_decode(file_get_contents($argv[1]),true); foreach($d["tables"]["domains"]??[] as $x) echo $x["domain"],"\n";' "$DOC")
  local MB MBX
  while IFS= read -r MBX; do
    [[ "$MBX" =~ ^[a-zA-Z0-9._%+-]+@[a-z0-9.-]+$ ]] || continue
    doveadm user "$MBX" > /dev/null 2>&1 && MB=ok || { MB="NOT FOUND by Dovecot"; BAD=1; }
    rep "check mailbox $MBX: $MB"
  done < <(KP_I="$NEWUID" PANEL_PHP 'foreach (Database::query("SELECT CONCAT(e.localpart,\"@\",d.domain) a FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE e.user_id=?", [(int)getenv("KP_I")])->fetchAll() as $r) echo $r["a"], "\n";')
  (( BAD == 0 )) || rep "some checks need a look (above)"
  prog "$IT" ready 100 "copied: check it, then cut over"
  shred -u "$DOC" 2>/dev/null || rm -f "$DOC"
  return 0
}

# ------------------------------------------------------------------ cutover
# Switch one copied account over to this server. Until the old server passes
# mail on (cut-mail), every failure is undone there (cut-undo): holding page
# off, held mail delivered there, account active, so visitors notice nothing.
# After that this server is the live one: later problems become TODO lines.
#   checks → hold (old: holding page, mail held, suspended) → last copy
#   (files, databases, mail, passwords) → mail passed on → cron + daemons
#   here → DNS on both servers → live; SSL is requested once public DNS
#   shows this server (retried for up to 3 hours, then a TODO).
pub_a() { { dig +short +time=3 +tries=1 A "$1" @1.1.1.1 || dig +short +time=3 +tries=1 A "$1" @8.8.8.8; } 2>/dev/null | grep -E '^[0-9.]+$' | sort -u | tr '\n' ' ' | sed 's/ $//'; }
cut_one() {  # item
  local IT="$1" U NU MYIP ZIP V SV CI OUT D L H HB N EM DOC HELD=0
  U="$(sql "SELECT username FROM transfer_items WHERE id=$IT")"; NU="$(sql "SELECT new_user_id FROM transfer_items WHERE id=$IT")"
  [[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ && "$NU" =~ ^[0-9]+$ ]] || { prog "$IT" ready 100 "cutover not started: bad item"; return 1; }
  W="$KT_DIR/$PAIR/work/$U-cut"; install -d -m 700 "$W"; DOC="$W/export.json"
  REPORT="$W/report.txt"; : > "$REPORT"
  rep() { echo "$*" >> "$REPORT"; say "$U: $*"; }
  cfail() {
    rep "CUTOVER STOPPED: $*"
    if (( HELD )); then
      if ssh_src cut-undo "$U" > "$W/undo" 2>&1; then rep "old server: put back as it was (sites, mail, account): nothing changed for visitors"
      else rep "old server: UNDO FAILED ($(head -c 150 "$W/undo")): on the OLD server turn the sites' maintenance off, and unsuspend $U"; fi
    fi
    prog "$IT" ready 100 "cutover stopped: $* · the old server is live again"
    return 1
  }
  say "cutover of $U (pairing $PAIR)"
  # stopped (TERM/INT/HUP) while the old server is held: put it back first
  trap 'if (( HELD )); then cfail "the cutover worker was stopped"; fi; exit 1' TERM INT HUP

  # 1. checks: nothing changes anywhere yet ---------------------------------
  prog "$IT" cut-checks 2 "checking both servers"
  V="$(ssh_src version 2>"$W/err")" || { cfail "the old server didn't answer: $(head -c 120 "$W/err")"; return 1; }
  read -r _ SV CI _ <<<"$V"
  [[ "$SV" == "$(kt_ver)" ]] || { cfail "versions differ (old $SV, here $(kt_ver)): update both first"; return 1; }
  { kt_ip_ok "$CI" && kt_is_own_ip "$CI"; } || { cfail "the old server sees this one as $CI, not an address of this server (NAT): point DNS by hand"; return 1; }
  MYIP="$CI"
  ssh_src export "$U" > "$DOC" 2>"$W/err" || { cfail "the old server refused the export: $(head -c 150 "$W/err")"; return 1; }
  OUT="$(KP_F="$DOC" KP_I="$NU" PANEL_PHP 'try { $d = json_decode(file_get_contents(getenv("KP_F")), true, 64, JSON_THROW_ON_ERROR); echo implode("; ", Transfer::drift($d, (int)getenv("KP_I"))); } catch (Throwable $e) { echo "bad export: ", $e->getMessage(); }' 2>&1)"
  [[ -z "$OUT" ]] || { cfail "the account changed on the old server since the copy ($OUT): delete the copy here and transfer it again"; return 1; }
  HB="$(rsync -a --dry-run --stats --exclude=/.tmp/ -e "$RSH" --rsync-path="rsync-home $U" "$SRC:./" "$W/dry/" 2>/dev/null | awk -F': ' '/^Total file size/{gsub(/[^0-9]/,"",$2); print $2}')"
  [[ "$HB" =~ ^[0-9]+$ ]] || { cfail "couldn't read the old home folder"; return 1; }
  rep "checks ok: same version $SV, this server is $MYIP, nothing new on the old account"

  # 2. hold the old copy -------------------------------------------------------
  prog "$IT" cut-hold 8 "old server: holding page on, mail held, account suspended"
  HELD=1
  V="$(ssh_src cut-hold "$U" 2>"$W/err")" || { cfail "the old server refused the hold: $(head -c 150 "$W/err")"; return 1; }
  ZIP="${V#ok hold }"
  kt_ip_ok "$ZIP" || { cfail "odd answer from the old server: ${V:0:80}"; return 1; }
  rep "old server: sites show a holding page, new mail waits in its queue, $U suspended"

  # 3. last copy: only what changed ---------------------------------------------
  bytes "$IT" 0 "$HB" 0
  sync_opts "$U" "$DOC" || { cfail "file top-up: can't read $U's group"; return 1; }
  RB_STAGE=cut-sync rsync_bar "$IT" 12 60 "last copy: files" "$HB" -- -aH --delete --exclude=/.tmp/ "${HOMESYNC[@]}" --rsync-path="rsync-home $U" "$SRC:./" "/home/$U/" \
    || { cfail "file top-up: $(tail -2 "$W/rsync.err" | tr '\n' ' ')"; return 1; }
  bytes "$IT" "$HB" "$HB" 0
  "$S/home-perms.sh" "$U" > /dev/null 2>&1 || rep "home-perms: warning"
  rep "files: last copy done"
  copy_dbs "$IT" "$U" cut-sync 62 76 "$DOC" || { cfail "a database didn't copy (see the report)"; return 1; }
  prog "$IT" cut-sync 78 "last copy: mail"
  while IFS= read -r D; do
    [[ "$D" =~ ^[a-z0-9.-]+$ ]] || continue
    if rsync -aH --delete "${MAILSYNC[@]}" -e "$RSH" --rsync-path="rsync-mail $U $D" "$SRC:./" "/var/mail/virtual/$D/" 2>"$W/err"; then rep "mail $D: last copy done"
    elif ! grep -q "no mail store" "$W/err"; then cfail "mail $D: $(head -c 150 "$W/err")"; return 1; fi
  done < <(php -r '$d=json_decode(file_get_contents($argv[1]),true); foreach($d["tables"]["domains"]??[] as $x) echo $x["domain"],"\n";' "$DOC")
  # passwords changed since the copy come along too (hashes only, on stdin)
  ssh_src shadow "$U" > "$W/shadow" 2>/dev/null || true
  while IFS=$'\t' read -r L H; do
    [[ "$L" =~ ^[a-z][a-z0-9_.-]{0,63}$ && "$H" =~ ^\$(1|5|6|y|2[aby])\$[./A-Za-z0-9\$=]{8,200}$ ]] || continue
    # only this account's own logins (as in the first pass): the account
    # itself, or one of its FTP logins — never root or any other local user
    if [[ "$L" != "$U" ]]; then
      [[ "$L" =~ ^${U}_[a-z0-9_]{1,32}$ ]] || continue
      sql "SELECT 1 FROM ftp_accounts f JOIN users u ON u.id=f.user_id WHERE u.username='$U' AND f.ftp_user='$L'" | grep -q 1 || continue
    fi
    [[ "$(id -u "$L" 2>/dev/null)" =~ ^[0-9]+$ && "$(id -u "$L")" -ge 1000 ]] || continue
    printf '%s:%s\n' "$L" "$H" | chpasswd -e || true
  done < "$W/shadow"
  shred -u "$W/shadow" 2>/dev/null || rm -f "$W/shadow"

  # 4. mail passed on: from here this server is the live copy ----------------------
  prog "$IT" cut-mail 84 "old server: passing mail on to this server"
  V="$(ssh_src cut-mail "$U" 2>"$W/err")" || { cfail "the old server refused to pass mail on: $(head -c 150 "$W/err")"; return 1; }
  HELD=0
  trap - TERM INT HUP
  rep "old server: mail for the account (and what it held) now goes to $MYIP"

  # 5. jobs here ------------------------------------------------------------------
  prog "$IT" cut-start 88 "starting cron jobs and app daemons here"
  N="$(KP_T="$IT" PANEL_PHP 'echo Transfer::releaseHeld((int)getenv("KP_T"));' 2>/dev/null || echo '?')"
  if "$S/cron-sync.sh" "$U" > /dev/null 2>&1; then rep "cron: $N paused job(s) running here now"
  else rep "TODO cron: couldn't rebuild $U's cron jobs: open Cron Jobs as $U and save one"; fi
  KP_I="$NU" PANEL_PHP 'foreach (Transfer::startDaemons((int)getenv("KP_I")) as $l) echo $l, "\n";' >> "$REPORT" 2>&1 || rep "TODO app daemons: some failed (Daemons page)"

  # 6. DNS on both servers -----------------------------------------------------------
  prog "$IT" cut-dns 92 "pointing DNS at this server"
  KP_I="$NU" KP_O="$ZIP" KP_N="$MYIP" PANEL_PHP 'foreach (Transfer::repoint((int)getenv("KP_I"), (string)getenv("KP_O"), (string)getenv("KP_N")) as $l) echo "here: ", $l, "\n";' >> "$REPORT" 2>&1 \
    || rep "TODO DNS here: the rewrite failed: change $ZIP to $MYIP on the DNS page"
  if ssh_src cut-dns "$U" > "$W/dns" 2>"$W/err"; then grep -v '^ok ' "$W/dns" | sed 's/^/old server: /' >> "$REPORT" || true
  else rep "TODO old server DNS: $(head -c 150 "$W/err"): change $ZIP to $MYIP in its DNS page"; fi
  # IPv6 is never repointed (no old→new v6 mapping is known): say so, by name
  sql "SELECT CONCAT(IF(r.host IN ('@',''), d.domain, CONCAT(r.host, '.', d.domain)), ' ', r.value) FROM dns_records r JOIN domains d ON d.id=r.domain_id WHERE d.user_id=$NU AND r.type='AAAA'" 2>/dev/null \
    | while read -r H V; do rep "TODO IPv6: $H has an AAAA record ($V) the cutover didn't change: IPv6 visitors still go there. If that is the old server, update or remove it on the DNS page"; done || true
  local NS NSA PEND="" EMAIL SSLD SEC
  EMAIL="$(KP_I="$NU" PANEL_PHP 'echo SslController::acmeEmail((int)getenv("KP_I")) ?? "";' 2>/dev/null || true)"
  while IFS=$'\t' read -r D SSLD SEC; do
    [[ "$D" =~ ^[a-z0-9.-]+$ ]] || continue
    if [[ -f "/etc/bind/zones/db.$D" ]]; then
      NSA=""
      for NS in $({ dig +short +time=3 +tries=1 NS "$D" @1.1.1.1 || true; } 2>/dev/null | head -4); do NSA+=" $(pub_a "${NS%.}")"; done
      if [[ " $NSA " == *" $ZIP "* ]]; then rep "NOTE $D: its nameservers are on the OLD server, which now answers with $MYIP. Before you switch the old server off, point the nameservers (and any glue) at this server at the registrar"
      elif [[ -n "${NSA// /}" && " $NSA " != *" $MYIP "* ]]; then rep "TODO $D: its DNS is hosted elsewhere ($(dig +short NS "$D" @1.1.1.1 2>/dev/null | head -2 | tr '\n' ' ')): change its A records from $ZIP to $MYIP there"; fi
    fi
    [[ "$SEC" == 1 ]] && rep "TODO $D: DNSSEC: the old server still signs this zone. When the nameservers move here, turn DNSSEC on here and swap the DS record at the registrar (not before)"
    [[ "$SSLD" == 1 ]] && PEND+=" $D"
  done < <(php -r '$d=json_decode(file_get_contents($argv[1]),true); foreach($d["tables"]["domains"]??[] as $x) echo $x["domain"],"\t",(int)($x["ssl_enabled"]??0),"\t",(int)($x["dnssec"]??0),"\n";' "$DOC")
  shred -u "$DOC" 2>/dev/null || rm -f "$DOC"

  # 7. live; SSL once public DNS shows this server ---------------------------------------
  [[ -n "$PEND" && -z "$EMAIL" ]] && { rep "TODO SSL: the account has no usable email for Let's Encrypt: issue SSL on the Domains page"; PEND=""; }
  cut_report "$IT"
  prog "$IT" live 100 "live here$([[ -n "$PEND" ]] && echo " · SSL waits for DNS:$PEND")"
  say "$U: cutover done"
  exec 8>&-   # other transfers may run while SSL waits on DNS
  local TRY LEFT
  for TRY in $(seq 1 36); do
    [[ -n "${PEND// /}" ]] || break
    LEFT=""
    for D in $PEND; do
      if [[ "$(pub_a "$D")" == "$MYIP" ]]; then
        if "$S/ssl-issue-bg.sh" "$D" "$EMAIL" > /dev/null 2>&1; then rep "SSL $D: requested (public DNS shows this server)"; else rep "TODO SSL $D: request failed: Domains page"; fi
      else LEFT+=" $D"; fi
    done
    PEND="$LEFT"
    [[ -n "${PEND// /}" ]] && prog "$IT" live 100 "live here · SSL waits for DNS:$PEND"
    [[ -n "${PEND// /}" ]] && sleep 300
  done
  [[ -n "${PEND// /}" ]] && rep "TODO SSL:$PEND: public DNS still showed the old server after 3 hours: issue SSL on the Domains page once it shows $MYIP"
  prog "$IT" live 100 "live here$([[ -n "${PEND// /}" ]] && echo " · SSL to do:$PEND")"
  cut_report "$IT"
  return 0
}
cut_report() {  # append this cutover's report to the item's
  [[ -n "${REPORT:-}" && -f "$REPORT" ]] || return 0
  KP_I="$1" KP_F="$REPORT" PANEL_PHP '$r = (string)file_get_contents(getenv("KP_F"));
    Database::query("UPDATE transfer_items SET report=LEFT(CONCAT(IFNULL(SUBSTRING_INDEX(report, \"\n--- cutover ---\n\", 1), \"\"), \"\n--- cutover ---\n\", ?), 60000) WHERE id=?", [$r, (int)getenv("KP_I")]);' || true
}

if [[ "$CMD" == cutrun ]]; then
  if cut_one "$CIT"; then :; else cut_report "$CIT"; fi
  sql "UPDATE transfer_items SET stage='ready', detail='cutover stopped unexpectedly: see Logs → account transfers' WHERE id=$CIT AND stage LIKE 'cut-%'" || true
  rm -rf -- "${KT_DIR:?}/$PAIR/work/"*-cut
  exit 0
fi

say "started (pairing $PAIR)"
while :; do
  ROW="$(sql "SELECT id, username FROM transfer_items WHERE job_id=$JOB AND stage='queued' ORDER BY id LIMIT 1")"
  [[ -n "$ROW" ]] || break
  IT="${ROW%%$'\t'*}"; U="${ROW#*$'\t'}"
  [[ "$IT" =~ ^[0-9]+$ && "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { sql "UPDATE transfer_items SET stage='failed', detail='bad item' WHERE id=${IT//[^0-9]/}"; continue; }
  sql "UPDATE transfer_items SET started_at=NOW() WHERE id=$IT"
  say "moving $U"
  if move_one "$IT" "$U"; then say "$U ready"
  else
    say "$U failed"
    # a half-made copy here is removed again (the source copy is untouched),
    # so a retry starts clean instead of tripping over its own leftovers
    NU="$(sql "SELECT new_user_id FROM transfer_items WHERE id=$IT")"
    if [[ "$NU" =~ ^[0-9]+$ ]]; then
      sql "UPDATE transfer_items SET detail=LEFT(CONCAT(detail, ' · removing the partial copy here…'), 250) WHERE id=$IT"
      if KP_I="$NU" PANEL_PHP '$t = Database::query("SELECT * FROM users WHERE id=? AND role=\"user\"", [(int)getenv("KP_I")])->fetch(); if ($t) User::teardown($t);' >> "$KT_LOG" 2>&1
      then echo "rolled back: the partial copy on this server was removed" >> "$KT_DIR/$PAIR/work/$U/report.txt"
           sql "UPDATE transfer_items SET new_user_id=NULL, detail=REPLACE(detail, 'removing the partial copy here…', 'partial copy removed: safe to retry') WHERE id=$IT"
      else echo "ROLLBACK FAILED: delete $U on this server by hand before retrying" >> "$KT_DIR/$PAIR/work/$U/report.txt"
           sql "UPDATE transfer_items SET detail=REPLACE(detail, 'removing the partial copy here…', 'ROLLBACK FAILED: delete $U here by hand') WHERE id=$IT"; fi
    fi
  fi
  F="$KT_DIR/$PAIR/work/$U/report.txt"
  [[ -f "$F" ]] && KP_I="$IT" KP_F="$F" PANEL_PHP 'Database::query("UPDATE transfer_items SET report=? WHERE id=?", [substr((string)file_get_contents(getenv("KP_F")), 0, 60000), (int)getenv("KP_I")]);' || true
  # never leave a half-made stage showing "running"
  sql "UPDATE transfer_items SET stage='failed', detail='stopped unexpectedly: see Logs → transfer' WHERE id=$IT AND stage NOT IN ('ready','failed')"
done
N="$(sql "SELECT COUNT(*) FROM transfer_items WHERE job_id=$JOB AND stage<>'ready'")"
sql "UPDATE transfer_jobs SET state='$([[ "$N" == 0 ]] && echo done || echo failed)', updated_at=NOW() WHERE id=$JOB"
rm -rf -- "${KT_DIR:?}/$PAIR/work"
say "finished ($N not ready)"
