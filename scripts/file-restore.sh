#!/usr/bin/env bash
# file-restore.sh <username> <tarball> <member> — extract ONE file from a backup.
# GUARDED: member must be an exact tar listing entry under home/<user>/ (no
# globs — the panel passes one validated path); symlinks inside the tarball
# are never followed on extract (--no-same-owner, strip setuid).
set -euo pipefail
U="${1:?}"; F="${2:?}"; M="${3:?}"
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad user"; exit 1; }
[[ "$F" =~ ^[a-zA-Z0-9_.-]+\.tar\.gz$ && "$F" != *"/"* ]] || { echo "bad filename"; exit 1; }
[[ "$M" =~ ^home/[a-z][a-z0-9_]{2,31}/[a-zA-Z0-9_./-]{1,220}$ ]] || { echo "bad member"; exit 1; }
[[ "$M" != *..* ]] || { echo "bad member"; exit 1; }
[[ "$M" == "home/$U/"* ]] || { echo "not your file"; exit 1; }
SRC="/backup/skypanel/$F"
[[ -f "$SRC" ]] || { echo "backup not found"; exit 1; }
S="$("$(dirname "$0")/backup-crypt.sh" stage "$SRC")"
if [[ "$S" != "$SRC" ]]; then trap 'rm -f "$S"' EXIT; SRC="$S"; fi
# list-then-grep (never tar|grep -q: early exit SIGPIPEs tar under pipefail)
LIST="$(tar tzf "$SRC" 2>/dev/null || true)"
grep -qxF "$M" <<< "$LIST" || { echo "not in backup"; exit 1; }
tar xzf "$SRC" -C / --no-same-owner --no-same-permissions "$M" 2>/dev/null || { echo "extract failed"; exit 1; }
chown "$U:" "/$M"
chmod 640 "/$M" 2>/dev/null || true
# restored copies come from root-owned archives without ACLs — re-grant the
# web worker or the site 403s (chmod alone does not recreate entries)
setfacl -m u:nobody:r "/$M" 2>/dev/null || true
echo "restored $M"
