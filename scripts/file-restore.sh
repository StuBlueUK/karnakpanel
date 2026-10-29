#!/usr/bin/env bash
# file-restore.sh <username> <tarball> <member> — extract ONE file from a backup.
# GUARDED: member must be an exact tar listing entry under home/<user>/ (no
# globs — the panel passes one validated path); symlinks inside the tarball
# are never followed on extract (--no-same-owner, strip setuid).
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/as-user.sh"
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
# extract AS THE USER (root opens the archive, feeds it on stdin): a symlink
# the user swapped into the path (x -> ../../etc) then reaches only what they
# could write anyway — as root, tar + chown planted user-owned files in /etc.
cd /
as_user "$U" tar xzf - -C / --no-same-owner --no-same-permissions -- "$M" < "$SRC" 2>/dev/null || { echo "extract failed"; exit 1; }
if as_user "$U" test -f "/$M" -a ! -L "/$M"; then
  as_user "$U" chmod 640 -- "/$M" 2>/dev/null || true
  # restored copies come from root-owned archives without ACLs — re-grant the
  # web worker or the site 403s (chmod alone does not recreate entries)
  as_user "$U" setfacl -m "$(acl_for "/home/$U" "/$M" r)" -- "/$M" 2>/dev/null || true
fi
echo "restored $M"
