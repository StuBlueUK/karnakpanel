#!/usr/bin/env bash
# file-trashctl.sh <username> list|restore|purge|empty [id] — manage a user's
# file-manager trash (see file-trash.sh). AS THE USER for every read/write.
#   list           TSV: id, deleted epoch, bytes, d|f, rel, name (max 500)
#   restore <id>   back to its original folder; refuses if something already
#                  has that name there, re-validates the stored path (the info
#                  file is user-writable — never trusted)
#   purge <id>     delete one entry permanently
#   empty          delete everything in the trash permanently
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/fm-common.sh"
U="${1:?}"; ACT="${2:?}"; ID="${3:-}"
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad user"; exit 1; }
id "$U" >/dev/null 2>&1 || { echo "no such user"; exit 1; }
H="/home/$U"; T="$H/.trash"
AS=(runuser -u "$U" --)
[[ -L "$T" || -L "$T/files" || -L "$T/info" ]] && { echo "refusing: trash is a symlink"; exit 1; }
# info_get <id> <key> — value of key= from the entry's info file (as the user)
info_get() { "${AS[@]}" cat -- "$T/info/$1" 2>/dev/null | sed -n "s/^$2=//p" | head -1; }
need_id() {
  [[ "$ID" =~ $FM_TRASH_ID_RE ]] || { echo "bad id"; exit 1; }
  [[ -e "$T/files/$ID" || -L "$T/files/$ID" ]] || { echo "no such trash entry"; exit 1; }
}
case "$ACT" in
  list)
    [[ -d "$T/info" ]] || exit 0
    n=0
    while IFS= read -r I; do
      [[ "$I" =~ $FM_TRASH_ID_RE && ( -e "$T/files/$I" || -L "$T/files/$I" ) ]] || continue
      R="$(info_get "$I" rel)"; N="$(info_get "$I" name)"; D="$(info_get "$I" deleted)"
      fm_rel_ok "$R" && fm_name_ok "$N" && [[ "$D" =~ ^[0-9]{1,11}$ ]] || continue
      B="$("${AS[@]}" du -sb -- "$T/files/$I" 2>/dev/null | cut -f1)"
      [[ -d "$T/files/$I" && ! -L "$T/files/$I" ]] && K=d || K=f
      printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$I" "$D" "${B:-0}" "$K" "$R" "$N"
      n=$((n+1)); [[ $n -lt 500 ]] || break
    done < <("${AS[@]}" find "$T/info" -mindepth 1 -maxdepth 1 -type f -printf '%f\n' 2>/dev/null | sort -r)
    ;;
  restore)
    need_id
    R="$(info_get "$ID" rel)"; N="$(info_get "$ID" name)"
    fm_rel_ok "$R" || { echo "bad stored path"; exit 1; }
    fm_name_ok "$N" || { echo "bad stored name"; exit 1; }
    DST="$(fm_resolve "$H" "$R" "$N")" || { echo "original folder is gone or unsafe"; exit 1; }
    fm_panel_path "$DST" "$U" && { echo "refusing panel-managed path"; exit 1; }
    [[ ! -e "$DST" && ! -L "$DST" ]] || { echo "something named $N already exists there — rename it first"; exit 1; }
    "${AS[@]}" mkdir -p -- "$(dirname "$DST")"
    "${AS[@]}" mv -T -- "$T/files/$ID" "$DST"
    "${AS[@]}" rm -f -- "$T/info/$ID"
    echo "restored $N to /${R}"
    ;;
  purge)
    need_id
    "${AS[@]}" rm -rf -- "$T/files/$ID" "$T/info/$ID"
    echo "deleted permanently"
    ;;
  empty)
    [[ -d "$T/files" ]] || { echo "trash is empty"; exit 0; }
    "${AS[@]}" find "$T/files" "$T/info" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} + 2>/dev/null || true
    echo "trash emptied"
    ;;
  *) echo "unknown action"; exit 1 ;;
esac
