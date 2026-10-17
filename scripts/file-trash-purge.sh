#!/usr/bin/env bash
# file-trash-purge.sh [days] — cron (daily): permanently delete file-manager
# trash entries older than <days> (default 30) for every panel home. Per user,
# AS THE USER; a .trash that is a symlink (or a home not owned by its user)
# is skipped. Orphans (files without info, info without files) go too.
# Age = the entry id's epoch prefix (written by root at trash time) — NOT
# mtime: mv keeps the item's own mtime, so a file last edited months ago
# would be purged the night it was trashed.
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/fm-common.sh"
DAYS="${1:-30}"
[[ "$DAYS" =~ ^[0-9]{1,3}$ && "$DAYS" -ge 1 ]] || { echo "bad days"; exit 1; }
CUT=$(( $(date +%s) - DAYS*86400 ))
for H in /home/*/; do
  H="${H%/}"; U="${H##*/}"
  [[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || continue
  id "$U" >/dev/null 2>&1 || continue
  T="$H/.trash"
  [[ -d "$T" && ! -L "$T" && ! -L "$T/files" && ! -L "$T/info" ]] || continue
  [[ "$(stat -c %U "$H")" == "$U" ]] || continue
  n=0
  while IFS= read -r I; do
    [[ "$I" =~ $FM_TRASH_ID_RE ]] || continue
    [[ "${I%%-*}" -lt "$CUT" ]] || continue
    runuser -u "$U" -- rm -rf -- "$T/files/$I" "$T/info/$I" && n=$((n+1))
  done < <(runuser -u "$U" -- find "$T/files" "$T/info" -mindepth 1 -maxdepth 1 -printf '%f\n' 2>/dev/null | sort -u)
  [[ $n -eq 0 ]] || echo "$(date -Is) trash-purge $U: $n entr(ies) older than $DAYS days"
done
