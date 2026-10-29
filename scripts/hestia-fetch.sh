#!/usr/bin/env bash
# hestia-fetch.sh <job> <host> <port> <sshuser> <secretfile> <hestiauser> <paneluser>
# HestiaCP import over SSH: run v-backup-user on the source, scp the new
# backup tar, remove it there, hand to hestia-import.sh. (Hestia's API can
# make backups but not download them — SSH is the only pull path.)
# DETACHED background; progress in import_jobs. SSH password via 600 file
# (never argv — panel logs full commands), shredded after reading.
# Note: Hestia rotates — if the user already holds BACKUPS copies, its oldest
# backup is deleted to make room (the Migrate page warns about this).
set -euo pipefail
JOB="${1:?}"; HOST="${2:?}"; PORT="${3:?}"; RUSER="${4:?}"; SECF="${5:?}"; HUSER="${6:?}"; PUSER="${7:?}"
LOG="/var/log/karnakpanel/software.log"
STAGE="/usr/local/skypanel/panel/storage/tmp-uploads"
DBP="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
prog() {
  local d="${3//\'/}"; d="${d//\\/}"
  MYSQL_PWD="$DBP" mysql -uskypanel skypanel -e "INSERT INTO import_jobs(job,stage,pct,detail) VALUES('$JOB','$1',$2,'${d:0:250}') ON DUPLICATE KEY UPDATE stage=VALUES(stage),pct=VALUES(pct),detail=VALUES(detail);" 2>/dev/null || true
}
[[ "$JOB" =~ ^[a-f0-9]{16}$ ]] || { echo "bad job"; exit 1; }
[[ "$HOST" =~ ^([a-z0-9.-]+\.[a-z]{2,}|[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)$ ]] || { echo "bad host"; exit 1; }
[[ "$PORT" =~ ^[0-9]{1,5}$ && "$PORT" -ge 1 && "$PORT" -le 65535 ]] || { echo "bad port"; exit 1; }
[[ "$RUSER" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] || { echo "bad ssh user"; exit 1; }
[[ "$HUSER" =~ ^[a-z0-9][a-z0-9._-]{0,30}[a-z0-9]$ ]] || { echo "bad hestia user"; exit 1; }
[[ "$PUSER" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad panel user"; exit 1; }
# the secret file is the one the panel staged for THIS job — never any other
# path (root reads, then shreds, what it is handed)
[[ "$SECF" == "$(readlink -f /usr/local/skypanel/panel/storage/tmp-uploads)/cpsecret-$JOB" && -f "$SECF" && ! -L "$SECF" ]] || { echo "missing secret"; exit 1; }
RPASS="$(cat "$SECF")"; shred -u "$SECF" 2>/dev/null || rm -f "$SECF"
[[ ${#RPASS} -ge 1 && ${#RPASS} -le 256 ]] || { echo "bad secret"; exit 1; }
id "$PUSER" >/dev/null 2>&1 || { echo "no such panel user: $PUSER"; exit 1; }
( exec >>"$LOG" 2>&1
  echo "=== $(date -Is) hestia fetch ($HUSER@$HOST:$PORT -> $PUSER) job=$JOB ==="
  fail() { prog "failed" 0 "$1"; echo "hestia fetch FAILED: $1"; exit 1; }
  command -v sshpass >/dev/null 2>&1 || apt-get install -y sshpass 2>&1 | tail -1 || true
  command -v sshpass >/dev/null 2>&1 || fail "sshpass missing"
  export SSHPASS="$RPASS"; unset RPASS
  OPTS=(-o StrictHostKeyChecking=accept-new -o ConnectTimeout=15 -o ServerAliveInterval=30 -o BatchMode=no)
  rsh() { sshpass -e ssh "${OPTS[@]}" -p "$PORT" "$RUSER@$HOST" "$1"; }
  # non-interactive shells don't load Hestia's profile: export + full paths
  PRE='export HESTIA=/usr/local/hestia; B=$(grep -oP "^BACKUP='"'"'\K[^'"'"']*" $HESTIA/conf/hestia.conf 2>/dev/null); B=${B:-/backup};'
  prog "backup" 0 "connecting to $HOST"
  rsh "test -x /usr/local/hestia/bin/v-backup-user && /usr/local/hestia/bin/v-list-user $HUSER >/dev/null" >/dev/null 2>&1 \
    || fail "login failed, not a HestiaCP server, or no Hestia user $HUSER (root SSH needed)"
  BEFORE="$(rsh "$PRE ls -1 \"\$B\"/$HUSER.*.tar 2>/dev/null" || true)"
  prog "backup" 0 "making backup on source (v-backup-user — can take a while)"
  rsh "$PRE /usr/local/hestia/bin/v-backup-user $HUSER" 2>&1 | tail -5 || fail "v-backup-user failed (disk limit? backup already running?)"
  AFTER="$(rsh "$PRE ls -1 \"\$B\"/$HUSER.*.tar 2>/dev/null" || true)"
  REMOTE="$(comm -13 <(sort <<<"$BEFORE") <(sort <<<"$AFTER") | grep -E "^/[A-Za-z0-9/._-]+/$HUSER\.[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{2}-[0-9]{2}-[0-9]{2}\.tar$" | tail -1 || true)"
  [[ -n "$REMOTE" ]] || fail "no new backup appeared on the source (check Hestia's backup log)"
  SIZE="$(rsh "stat -c %s '$REMOTE'" 2>/dev/null || echo 0)"
  [[ "$SIZE" =~ ^[0-9]+$ && "$SIZE" -gt 0 ]] || fail "backup size unreadable"
  echo "remote backup: $REMOTE ($SIZE bytes)"
  FREE="$(df -Pk "$STAGE" | awk 'NR==2{print $4}')"
  [[ "${FREE:-0}" -gt $(( SIZE * 4 / 1024 )) ]] || fail "not enough disk here for a $((SIZE/1048576)) MB backup (+unpack)"
  DEST="$STAGE/hestia-$JOB.tar"
  sshpass -e scp "${OPTS[@]}" -P "$PORT" "$RUSER@$HOST:$REMOTE" "$DEST.part" 2>/dev/null &
  CPID=$!
  while kill -0 $CPID 2>/dev/null; do
    GOT="$(stat -c %s "$DEST.part" 2>/dev/null || echo 0)"
    PCT=$(( GOT * 100 / SIZE )); [[ $PCT -gt 99 ]] && PCT=99
    prog "download" "$PCT" "$GOT / $SIZE bytes"
    sleep 15
  done
  wait $CPID || { rm -f "$DEST.part"; fail "download failed"; }
  mv "$DEST.part" "$DEST"; chmod 600 "$DEST"
  prog "download" 100 "$SIZE bytes received"
  rsh "/usr/local/hestia/bin/v-delete-user-backup $HUSER '$(basename "$REMOTE")'" >/dev/null 2>&1 \
    || echo "remote cleanup skipped (delete $REMOTE in Hestia → Backups)"
  unset SSHPASS
  # the importer detaches and owns progress from here (ends at 'done')
  SRCIP="$HOST"; [[ "$HOST" =~ ^[0-9.]+$ ]] || SRCIP="$(getent ahostsv4 "$HOST" 2>/dev/null | awk 'NR==1{print $1}' || true)"
  HESTIA_SRC_IP="$SRCIP" "$(dirname "$(readlink -f "$0")")/hestia-import.sh" "$DEST" "$HUSER" "$PUSER" "$JOB" \
    || { rm -f "$DEST"; fail "import refused to start"; }
) &
echo "fetch started in background — progress on the Migrate page"
