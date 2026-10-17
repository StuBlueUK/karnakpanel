#!/usr/bin/env bash
# cpremote-fetch.sh <job> <host> <cpuser> <secretfile> <paneluser> <domain>
# Standard cPanel import (NO source root): trigger a full backup on the source
# (UAPI home-dir build), pull it over FTP, delete the remote copy, then hand
# the tarball to cpmove-import.sh.
# Auth reality on modern cPanel (11.138+: password UAPI gets 401 while the
# same password logs into the web UI fine — tokens are required): the secret
# file carries "password\ntoken-or-empty". Password trigger is tried first
# (works on older hosts, keeps logins to one credential), token second.
# FTP pull/delete always use the password (plain FTP auth is unaffected).
# DETACHED background (backup builds take minutes-hours); progress rows go to
# import_jobs for the Migrate page poller. Secrets shredded after reading.
set -euo pipefail
JOB="${1:?}"; HOST="${2:?}"; CPUSER="${3:?}"; SECF="${4:?}"; PUSER="${5:?}"; DOMAIN="${6:?}"
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
[[ "$CPUSER" =~ ^[a-z][a-z0-9_]{1,15}$ ]] || { echo "bad cpanel user"; exit 1; }
[[ "$PUSER" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad panel user"; exit 1; }
[[ "$DOMAIN" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad domain"; exit 1; }
[[ -f "$SECF" ]] || { echo "missing secret"; exit 1; }
CPPASS="$(head -1 "$SECF")"; CPTOKEN="$(sed -n '2p' "$SECF")"; shred -u "$SECF" 2>/dev/null || rm -f "$SECF"
[[ ${#CPPASS} -ge 1 && ${#CPPASS} -le 256 ]] || { echo "bad secret"; exit 1; }
[[ ${#CPTOKEN} -le 256 ]] || CPTOKEN=""
id "$PUSER" >/dev/null 2>&1 || { echo "no such panel user: $PUSER"; exit 1; }
( exec >>"$LOG" 2>&1
  echo "=== $(date -Is) cpanel remote fetch $DOMAIN (cp $CPUSER@$HOST -> $PUSER) job=$JOB ==="
  prog "backup" 0 "requesting full backup on source"
  # source FTP posture varies (stock cPanel: plaintext OK; hardened hosts:
  # TLS-only): probe explicit FTPS first, fall back to plain — BEFORE the
  # baseline below (it uses these helpers, and set -u dies on unset vars).
  if curl -sk --connect-timeout 15 --max-time 20 -u "$CPUSER:$CPPASS" "ftps://$HOST/" -o /dev/null 2>/dev/null; then
    FSCHEME="ftps"; echo "source FTP: explicit TLS"
  elif curl -sk --connect-timeout 15 --max-time 20 -u "$CPUSER:$CPPASS" "ftp://$HOST/" -o /dev/null 2>/dev/null; then
    FSCHEME="ftp"; echo "source FTP: plain"
  else
    FSCHEME="ftp"
    echo "WARN: source FTP probe failed for both schemes (wrong password?) — continuing, pull may fail"
  fi
  # baseline BEFORE triggering: tiny accounts build in seconds, so a
  # post-trigger snapshot would already include our file and exclude it
  # forever. Only names appearing after this point count as ours.
  ftp_names() { curl -sk --connect-timeout 15 --max-time 30 -u "$CPUSER:$CPPASS" "$FSCHEME://$HOST/" 2>/dev/null | awk '{print $NF}' | grep -E '^backup-.*\.tar\.gz$' || true; }
  ftp_size() { curl -sk --connect-timeout 15 --max-time 30 -u "$CPUSER:$CPPASS" -I "$FSCHEME://$HOST/$1" 2>/dev/null | grep -i '^content-length:' | tr -dc '0-9' || true; }
  KNOWN="$(ftp_names | sort -u || true)"
  # 1. trigger the build in the customer's home dir (pull model: nothing
  # inbound needed here, no firewall/TLS interop gamble with their FTP client)
  RESP="$(curl -sk --max-time 30 -u "$CPUSER:$CPPASS" \
    --data-urlencode "email=" \
    "https://$HOST:2083/execute/Backup/fullbackup_to_homedir" 2>&1 || true)"
  UAPI=(-u "$CPUSER:$CPPASS")
  if ! echo "$RESP" | grep -q '"status":1'; then
    echo "password trigger refused — trying API token"
    if [[ -n "$CPTOKEN" ]]; then
      RESP="$(curl -sk --max-time 30 -H "Authorization: cpanel $CPUSER:$CPTOKEN" \
        --data-urlencode "email=" \
        "https://$HOST:2083/execute/Backup/fullbackup_to_homedir" 2>&1 || true)"
      echo "$RESP" | grep -q '"status":1' && UAPI=(-H "Authorization: cpanel $CPUSER:$CPTOKEN")
    fi
  fi
  echo "$RESP" | head -c 300; echo
  echo "$RESP" | grep -q '"status":1' || { prog "failed" 0 "source refused backup request (bad login/token? UAPI blocked?)"; echo "backup request refused"; exit 1; }
  # 2. poll for completion. Two sources: list_backups JSON (carries size;
  # broken on hosts without configured backup dir) and FTP NLIST (always
  # available with the password). A stale same-named backup must never
  # false-complete: JSON sorts by filetime, FTP only accepts names unseen in
  # the pre-trigger baseline AND stable across two consecutive polls.
  # (ftp_names/ftp_size defined once, above the trigger.)
  FILE=""; SIZE=0; WAIT=0; PREV=""; PREVSIZE=0
  while [[ $WAIT -lt 360 ]]; do
    LIST="$(curl -sk --max-time 30 "${UAPI[@]}" "https://$HOST:2083/execute/Backup/list_backups" 2>/dev/null || true)"
    FILE="$(printf '%s' "$LIST" | python3 -c "import json,sys
try:
  d=json.load(sys.stdin)['data']
  fs=[f for f in d if str(f.get('file','')).startswith('backup-') and str(f.get('file','')).endswith('.tar.gz')]
  fs.sort(key=lambda f: str(f.get('filetime',f.get('file',''))))
  print(fs[-1]['file'] if fs else '')" 2>/dev/null || true)"
    if [[ -n "$FILE" ]]; then
      SIZE="$(printf '%s' "$LIST" | python3 -c "import json,sys
try:
  d=json.load(sys.stdin)['data']
  print([f.get('size',0) for f in d if f.get('file')=='''$FILE'''][0])" 2>/dev/null || echo 0)"
      # still building while cPanel holds its marker: BackupInfo/progress
      ST="$(curl -sk --max-time 30 "${UAPI[@]}" "https://$HOST:2083/execute/BackupInfo/progress" 2>/dev/null | head -c 200 || true)"
      echo "$ST" | grep -qi '"complete"' && break || true
      # no-progress endpoint on old hosts: accept a stable, nonzero listing
      [[ "$SIZE" != "0" ]] && break || true
      FILE=""; SIZE=0
    else
      # JSON path unusable here — FTP directory watch instead
      CAND="$(ftp_names | sort -u | grep -vxF -f <(printf '%s\n' "$KNOWN") | tail -1 || true)"
      if [[ -n "$CAND" ]]; then
        SZ="$(ftp_size "$CAND")"; [[ "$SZ" =~ ^[0-9]+$ ]] || SZ=0
        if [[ "$CAND" == "$PREV" && "$SZ" == "$PREVSIZE" && "$SZ" != "0" ]]; then
          FILE="$CAND"; SIZE="$SZ"; break
        fi
        PREV="$CAND"; PREVSIZE="$SZ"
      fi
    fi
    WAIT=$((WAIT+1))
    prog "waiting" 0 "backup building on source (${WAIT}m elapsed)"
    sleep 60
  done
  [[ -n "$FILE" && "$SIZE" != "0" ]] || { prog "failed" 0 "backup never completed (6h cap)"; echo "backup wait timed out"; exit 1; }
  echo "remote file: $FILE ($SIZE bytes)"
  # 3. pull it (byte polling against the listed size = honest %)
  DEST="$STAGE/cpmove-$CPUSER.tar.gz"
  curl -sk --connect-timeout 20 --max-time 7200 -u "$CPUSER:$CPPASS" "$FSCHEME://$HOST/$FILE" -o "$DEST.part" 2>/dev/null &
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
  # 4. free their disk (best-effort FTP delete — the quota is theirs)
  curl -sk --connect-timeout 20 --max-time 60 -u "$CPUSER:$CPPASS" "$FSCHEME://$HOST/" -Q "DELE $FILE" -o /dev/null 2>/dev/null || echo "remote cleanup skipped (delete $FILE by hand)"
  # 5. hand off to the standard importer (synchronous here — we are already
  # detached; its tracking rows + report land in the same log)
  prog "importing" 0 "running cPanel import"
  if "$(dirname "$0")/cpmove-import.sh" "$DEST" "$CPUSER" "$PUSER" "$DOMAIN" 2>&1 | tail -20; then
    prog "done" 100 "imported (see Logs → software for the report)"
    echo "remote import $DOMAIN COMPLETE"
  else
    prog "failed" 0 "import step failed (tarball kept at $DEST)"
    echo "import step failed"
    exit 1
  fi
) &
echo "fetch started in background — progress on the Migrate page"
