#!/usr/bin/env bash
# file-del.sh <username> <relpath> <name> — delete one file or directory
# inside a user's home. GUARDED (fm-common.sh): no .., no absolute paths, no
# symlinks on the parent path (a symlink LEAF is removed as a link), never "."/"..", never the home root, never a
# panel-owned dot path, never a live domain's folder or docroot (use domain
# delete for those — it tears down vhost/DNS/mail too). Directories delete
# RECURSIVELY (UI confirms first). This is the PERMANENT delete — the file
# manager's normal Delete goes to the trash (file-trash.sh).
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/fm-common.sh"
U="${1:?}"; REL="${2:-}"; N="${3:?}"
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad user"; exit 1; }
fm_name_ok "$N" || { echo "bad name"; exit 1; }
fm_rel_ok "$REL" || { echo "bad path"; exit 1; }
id "$U" >/dev/null 2>&1 || { echo "no such user"; exit 1; }
TARGET="$(fm_resolve_leaf "/home/$U" "$REL" "$N")" || { echo "outside home"; exit 1; }
[[ "$TARGET" != "/home/$U" ]] || { echo "refusing home root"; exit 1; }
fm_panel_path "$TARGET" "$U" && { echo "refusing panel-managed path"; exit 1; }
fm_live_root "$TARGET" "$U" && { echo "refusing live domain folder — use domain delete"; exit 1; }
[[ -e "$TARGET" || -L "$TARGET" ]] || { echo "no such file"; exit 1; }
if [[ -L "$TARGET" ]]; then
  rm -f -- "$TARGET" && echo "removed link $N"
elif [[ -d "$TARGET" ]]; then
  rm -rf -- "$TARGET" && echo "removed dir $N"
else
  rm -f -- "$TARGET" && echo "removed $N"
fi
