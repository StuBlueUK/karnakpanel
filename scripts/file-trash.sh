#!/usr/bin/env bash
# file-trash.sh <username> <relpath> <name>... — move items to the user's
# trash (/home/<user>/.trash/files/<id>, metadata in .trash/info/<id>).
# GUARDED (fm-common.sh) like file-del.sh: names validated + contained, never
# the home root, panel-owned dot paths or a live domain's folder/docroot.
# Every write runs AS THE USER (runuser): a .trash swapped for a symlink can
# only move the user's own files somewhere the user could write anyway.
# Same filesystem as the home, so the move is a rename (instant, atomic), and
# trashed bytes still count toward quota until purged (30 days, cron).
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/fm-common.sh"
U="${1:?}"; REL="${2:-}"; shift 2
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad user"; exit 1; }
fm_rel_ok "$REL" || { echo "bad path"; exit 1; }
[[ $# -ge 1 && $# -le 100 ]] || { echo "select 1-100 items"; exit 1; }
id "$U" >/dev/null 2>&1 || { echo "no such user"; exit 1; }
H="/home/$U"; T="$H/.trash"
AS=(runuser -u "$U" --)
TARGETS=()
for N in "$@"; do
  fm_name_ok "$N" || { echo "bad name: $N"; exit 1; }
  S="$(fm_resolve_leaf "$H" "$REL" "$N")" || { echo "outside home: $N"; exit 1; }
  [[ "$S" != "$H" ]] || { echo "refusing home root"; exit 1; }
  fm_panel_path "$S" "$U" && { echo "refusing panel-managed path: $N"; exit 1; }
  fm_live_root "$S" "$U" && { echo "refusing live domain folder: $N — use domain delete"; exit 1; }
  [[ -e "$S" || -L "$S" ]] || { echo "missing: $N"; exit 1; }
  TARGETS+=("$N")
done
[[ -L "$T" || -L "$T/files" || -L "$T/info" ]] && { echo "refusing: trash is a symlink"; exit 1; }
"${AS[@]}" mkdir -p -m 700 -- "$T/files" "$T/info"
OK=0; FAIL=""
for N in "${TARGETS[@]}"; do
  ID="$(date +%s)-$(od -An -N4 -tx1 /dev/urandom | tr -d ' \n')"
  S="$(fm_resolve_leaf "$H" "$REL" "$N")" || { FAIL="$FAIL $N"; continue; }
  if "${AS[@]}" mv -T -- "$S" "$T/files/$ID" 2>/dev/null; then
    printf 'rel=%s\nname=%s\ndeleted=%s\n' "$REL" "$N" "$(date +%s)" | "${AS[@]}" tee -- "$T/info/$ID" >/dev/null
    OK=$((OK+1))
  else
    FAIL="$FAIL $N"
  fi
done
[[ -z "$FAIL" ]] || { echo "moved $OK to trash; could not move:$FAIL (not yours? use Delete permanently)"; exit 1; }
echo "moved $OK item(s) to trash"
