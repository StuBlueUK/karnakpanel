#!/usr/bin/env bash
# cproot-fetch.sh <job> <host> <rootuser> <secretfile> <cpuser> <paneluser> <domain>
# Root cPanel import (source server owner): pkgacct over root SSH, scp home,
# remove the remote archive, then hand to cpmove-import.sh. Fewer moving parts
# than the UAPI path (no FTP, no polling-for-push) and immune to FTP blocks.
# DETACHED background; progress at /var/lib/skypanel/cpmove-<job>.progress as
# "stage|pct|detail". Root secret via 600 file (never argv — panel logs full
# commands); shredded after reading. Needs sshpass (installed on demand).
set -euo pipefail
JOB="${1:?}"; HOST="${2:?}"; RUSER="${3:?}"; SECF="${4:?}"; CPUSER="${5:?}"; PUSER="${6:?}"; DOMAIN="${7:?}"
LOG="/var/log/karnakpanel/software.log"
STAGE="/usr/local/skypanel/panel/storage/tmp-uploads"
DBP="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
# progress via DB (import_jobs), not files: panel file reads under /var/lib
# proved unreliable across stacks, and rows give free history.
prog() {
  local d="${3//\'/}"
  MYSQL_PWD="$DBP" mysql -uskypanel skypanel -e "INSERT INTO import_jobs(job,stage,pct,detail) VALUES('$JOB','$1',$2,'$d') ON DUPLICATE KEY UPDATE stage=VALUES(stage),pct=VALUES(pct),detail=VALUES(detail);" 2>/dev/null || true
}
[[ "$JOB" =~ ^[a-f0-9]{16}$ ]] || { echo "bad job"; exit 1; }
[[ "$HOST" =~ ^([a-z0-9.-]+\.[a-z]{2,}|[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)$ ]] || { echo "bad host"; exit 1; }
[[ "$RUSER" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] || { echo "bad root user"; exit 1; }
[[ "$CPUSER" =~ ^[a-z][a-z0-9_]{1,15}$ ]] || { echo "bad cpanel user"; exit 1; }
[[ "$PUSER" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad panel user"; exit 1; }
[[ "$DOMAIN" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad domain"; exit 1; }
# the secret file is the one the panel staged for THIS job — never any other
# path (root reads, then shreds, what it is handed)
[[ "$SECF" == "$(readlink -f /usr/local/skypanel/panel/storage/tmp-uploads)/cpsecret-$JOB" && -f "$SECF" && ! -L "$SECF" ]] || { echo "missing secret"; exit 1; }
RPASS="$(cat "$SECF")"; shred -u "$SECF" 2>/dev/null || rm -f "$SECF"
[[ ${#RPASS} -ge 1 && ${#RPASS} -le 256 ]] || { echo "bad secret"; exit 1; }
id "$PUSER" >/dev/null 2>&1 || { echo "no such panel user: $PUSER"; exit 1; }
( exec >>"$LOG" 2>&1
  echo "=== $(date -Is) cpanel root fetch $DOMAIN (cp $CPUSER@$HOST -> $PUSER) job=$JOB ==="
  command -v sshpass >/dev/null 2>&1 || apt-get install -y sshpass 2>&1 | tail -1 || { prog "failed" 0 "sshpass missing"; echo "sshpass missing"; exit 1; }
  export SSHPASS="$RPASS"; unset RPASS
  SSH="sshpass -e ssh -o StrictHostKeyChecking=accept-new -o ConnectTimeout=15 -o BatchMode=no $RUSER@$HOST"
  SCP="sshpass -e scp -o StrictHostKeyChecking=accept-new -o ConnectTimeout=15"
  prog "backup" 0 "packaging account on source (pkgacct)"
  if ! $SSH "/scripts/pkgacct $CPUSER" 2>&1 | tail -3; then
    prog "failed" 0 "pkgacct failed (root login? account exists?)"; echo "pkgacct failed"; exit 1
  fi
  REMOTE="/home/cpmove-$CPUSER.tar.gz"
  SIZE="$($SSH "stat -c %s $REMOTE" 2>/dev/null || echo 0)"
  [[ "$SIZE" != "0" ]] || { prog "failed" 0 "no archive on source after pkgacct"; echo "no archive"; exit 1; }
  echo "remote archive: $SIZE bytes"
  DEST="$STAGE/cpmove-$CPUSER.tar.gz"
  $SCP "$RUSER@$HOST:$REMOTE" "$DEST.part" 2>/dev/null &
  CPID=$!
  while kill -0 $CPID 2>/dev/null; do
    GOT="$(stat -c %s "$DEST.part" 2>/dev/null || echo 0)"
    PCT=$(( GOT * 100 / SIZE )); [[ $PCT -gt 99 ]] && PCT=99
    prog "download" "$PCT" "$GOT / $SIZE bytes"
    sleep 15
  done
  wait $CPID || { prog "failed" 0 "download failed"; echo "download failed"; exit 1; }
  mv "$DEST.part" "$DEST"
  prog "download" 100 "$SIZE bytes received"
  $SSH "rm -f $REMOTE" 2>/dev/null || echo "remote cleanup skipped (remove $REMOTE by hand)"
  unset SSHPASS
  prog "importing" 0 "running cPanel import"
  if "$(dirname "$0")/cpmove-import.sh" "$DEST" "$CPUSER" "$PUSER" "$DOMAIN" 2>&1 | tail -20; then
    prog "done" 100 "imported (see Logs → software for the report)"
    echo "root import $DOMAIN COMPLETE"
  else
    prog "failed" 0 "import step failed (tarball kept at $DEST)"
    echo "import step failed"
    exit 1
  fi
) &
echo "fetch started in background — progress on the Migrate page"
