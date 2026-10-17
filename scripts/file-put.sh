#!/usr/bin/env bash
# file-put.sh <username> <relpath> <tmpfile> <filename> — place a panel-uploaded file
# into a user's home (relpath is relative to /home/<user>, e.g.
# "<domain>/public_html"). The web worker (nobody) can't write user homedirs, so PHP
# stages the upload under the panel temp dir and this (root) script moves it.
# GUARDED: tmpfile must live under the panel staging dir; relpath must stay inside
# the user's public_html; everything is regex-validated.
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/fm-common.sh"
U="${1:?}"; REL="${2:-}"; TMP="${3:?}"; FN="${4:?}"
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad user"; exit 1; }
fm_name_ok "$FN" || { echo "bad filename"; exit 1; }
fm_rel_ok "$REL" || { echo "bad path"; exit 1; }
STAGE="/usr/local/skypanel/panel/storage/tmp-uploads"
# panel/ is a symlink since self-update v1: compare REAL paths or every
# staged write (uploads, editor saves) is refused as "outside staging"
RSTAGE="$(realpath -m "$STAGE")"; RTMP="$(realpath -m "$TMP")"
case "$RTMP" in "$RSTAGE"/*) ;; *) echo "tmp outside staging"; exit 1;; esac
[[ -f "$TMP" ]] || { echo "no such upload"; exit 1; }
id "$U" >/dev/null 2>&1 || { echo "no such user"; exit 1; }
# webshell screen: famous shell basenames never land in a docroot (case-insensitive
# match on the lowercased name). Renames evade it — the nightly malware scan +
# upload clamscan below are the real net; this catches the casual/lazy case.
LOW="$(printf '%s' "$FN" | tr 'A-Z' 'a-z')"
case "$LOW" in
  c99*|r57*|b374k*|wso*|alfa*|filesman*|*webshell*|*backdoor*|*c1000*|*c1100*|*kacak*|*bypasser*|*injection*|*symlink*|*wp-console*|*wpmanager*)
    echo "REFUSED: known webshell name ($FN)"; exit 1;;
esac
# upload-time antivirus: clamdscan the staged file when ClamAV opted in.
# Fail-OPEN when clamd is absent/down (never break uploads); fail-CLOSED on
# a positive (refuse + log). Editor saves flow through here too.
if systemctl is-active --quiet clamav-daemon 2>/dev/null && command -v clamdscan >/dev/null 2>&1; then
  # exit 1 = infected (refuse); exit 0 = clean; exit 2+ = scanner error
  # (daemon wedged, socket busy) — fail OPEN, never break uploads on it
  # --stream (INSTREAM): fd-passing is blocked under LXC/AppArmor, stream works
  # everywhere. exit 1 = infected (refuse); 0 = clean; 2+ = scanner error → OPEN.
  # NOTE: the || guard is load-bearing — under set -e a bare $(...) with a
  # nonzero exit would kill the script before SC is even assigned.
  SC=0; OUT="$(clamdscan --stream --no-summary "$TMP" 2>&1)" || SC=$?
  if [[ "$SC" == "1" ]]; then
    echo "$(date -Is) file-put REFUSED infected upload $FN for $U: $OUT" >> /var/log/karnakpanel/malware.log 2>/dev/null || true
    echo "REFUSED: upload flagged by antivirus"; exit 1
  fi
fi
# HARDENED containment for DEST (fm-common.sh): component walk, no symlinks,
# trailing-slash boundary — mkdir -p through a symlinked REL would otherwise
# create dirs in a sibling home as root. The FINAL path is checked too: a
# symlink planted at DEST/FN would make mv/chown/chmod act on its target.
DEST="$(fm_resolve "/home/$U" "$REL" "")" || { echo "outside home"; exit 1; }
TGT="$(fm_resolve "/home/$U" "$REL" "$FN")" || { echo "outside home"; exit 1; }
fm_panel_path "$TGT" "$U" && { echo "refusing panel-managed path"; exit 1; }
[[ -L "$TGT" ]] && { echo "refusing: target is a symlink"; exit 1; }
[[ -d "$TGT" ]] && { echo "refusing: a folder has that name"; exit 1; }
if [[ ! -d "$DEST" ]]; then
  # create missing levels AS THE USER (never root-owned dirs in a home)
  runuser -u "$U" -- mkdir -p -- "$DEST"
fi
mv -fT -- "$TMP" "$TGT"
chown -h "$U:" "$TGT"
chmod 640 "$TGT"  # nobody reads via the dir's default ACL; other users denied
setfacl -m u:nobody:r "$TGT" 2>/dev/null || true
echo "stored $FN"
