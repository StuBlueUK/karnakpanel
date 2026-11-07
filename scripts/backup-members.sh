#!/usr/bin/env bash
# backup-members.sh <tarball> [prefix] — list members (cap 300) for restore browsing.
set -euo pipefail
F="${1:?}"; PRE="${2:-}"
[[ "$F" =~ ^[a-zA-Z0-9_.-]+\.tar\.gz$ ]] || { echo "bad filename"; exit 1; }
[[ "$F" != *"/"* && "$F" != *".."* ]] || { echo "bad filename"; exit 1; }
SRC="/backup/skypanel/$F"
[[ -f "$SRC" ]] || { echo "backup not found"; exit 1; }
S="$("$(dirname "$0")/backup-crypt.sh" stage "$SRC")"
if [[ "$S" != "$SRC" ]]; then trap 'rm -f "$S"' EXIT; SRC="$S"; fi
LIST="$(tar tzf "$SRC" 2>/dev/null || true)"
if [[ -z "$PRE" ]]; then
  echo "$LIST" | head -300
else
  [[ "$PRE" =~ ^[a-zA-Z0-9_./-]{1,160}$ ]] || { echo "bad prefix"; exit 1; }
  echo "$LIST" | grep -F "$PRE" | head -300
fi
