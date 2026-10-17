#!/usr/bin/env bash
# inc-restore.sh <username> <date> <relpath> — restore one path from an
# incremental snapshot (date = YYYYMMDD dir). relpath is relative to the
# snapshot root, e.g. "home/admin/cloudknight.co.uk/public_html/index.html".
# Ownership repaired to the user (or vmail for mail paths).
set -euo pipefail
U="${1:?}"; TS="${2:?}"; REL="${3:?}"
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad user"; exit 1; }
[[ "$TS" =~ ^[0-9]{8}$ ]] || { echo "bad date"; exit 1; }
[[ "$REL" =~ ^(home/[a-z][a-z0-9_]{2,31}/|var/mail/virtual/[a-z0-9.-]+\.[a-z]{2,}/)[a-zA-Z0-9_./-]{1,220}$ ]] || { echo "bad path"; exit 1; }
[[ "$REL" != *..* ]] || { echo "bad path"; exit 1; }
[[ "$REL" == "home/$U/"* || "$REL" == var/mail/virtual/* ]] || { echo "not your data"; exit 1; }
SRC="/backup/skypanel/inc/$U/$TS/$REL"
[[ -e "$SRC" && ! -L "$SRC" ]] || { echo "not in snapshot"; exit 1; }
DST="/$REL"
mkdir -p "$(dirname "$DST")"
cp -a "$SRC" "$DST"
if [[ "$REL" == var/mail/virtual/* ]]; then
  chown vmail:vmail "$DST"
  [[ -f "$DST" ]] && chmod 600 "$DST"
else
  chown "$U:" "$DST"; chmod 640 "$DST" 2>/dev/null || true
  setfacl -m u:nobody:r "$DST" 2>/dev/null || true
fi
echo "restored $REL from $TS"
