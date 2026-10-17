#!/usr/bin/env bash
# backup-inc.sh <username> — rsync hardlinked-daily incremental (complements,
# never replaces, the tarball backups). Layout:
# /backup/skypanel/inc/<user>/<date>/ with --link-dest to yesterday, keep 7.
# Covers the home tree + the user's virtual maildirs (same set as tarballs).
set -euo pipefail
U="${1:?}"; TS="$(date +%Y%m%d)"
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad user"; exit 1; }
id "$U" >/dev/null 2>&1 || { echo "no such user"; exit 1; }
BASE="/backup/skypanel/inc/$U"
mkdir -p "$BASE"
PREV="$(ls -dt "$BASE"/*/ 2>/dev/null | head -1 || true)"
DST="$BASE/$TS"
mkdir -p "$DST"
SRCS=("/home/$U")
PASS="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
if [[ -n "$PASS" ]]; then
  while IFS= read -r d; do
    [[ -n "$d" && -d "/var/mail/virtual/$d" ]] && SRCS+=("/var/mail/virtual/$d")
  done < <(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT d.domain FROM domains d JOIN users u ON u.id=d.user_id WHERE u.username='$U';" 2>/dev/null)
fi
# --relative keeps full paths (home/... + var/mail/...) so inc-restore.sh
# addresses snapshots exactly like tarball members
if [[ -n "$PREV" && "$PREV" != "$DST/" ]]; then
  rsync -aR --delete --link-dest="$PREV" "${SRCS[@]}" "$DST/" 2>&1 | tail -1 || true
else
  rsync -aR --delete "${SRCS[@]}" "$DST/" 2>&1 | tail -1 || true
fi
chown -R root:root "$DST" 2>/dev/null || true
chmod 700 "$DST" 2>/dev/null || true
# keep 7 newest day-dirs (never the one just written)
KEPT=0
for d in $(ls -dt "$BASE"/*/ 2>/dev/null); do
  KEPT=$((KEPT+1))
  if [[ "$KEPT" -gt 7 ]]; then rm -rf "$d"; echo "pruned $d"; fi
done
echo "incremental done for $U ($TS)"
