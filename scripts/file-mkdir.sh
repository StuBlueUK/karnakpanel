#!/usr/bin/env bash
# file-mkdir.sh / file-new.sh <username> <relpath> <name> — create an empty
# dir (file-mkdir.sh) or empty file (file-new.sh, a symlink to this script).
# GUARDED (fm-common.sh): name validated, contained (no symlinks), must not
# exist, never inside a panel-owned dot path.
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/fm-common.sh"
MODE="$(basename "$0")"
U="${1:?}"; REL="${2:-}"; N="${3:?}"
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad user"; exit 1; }
fm_name_ok "$N" || { echo "bad name"; exit 1; }
fm_rel_ok "$REL" || { echo "bad path"; exit 1; }
id "$U" >/dev/null 2>&1 || { echo "no such user"; exit 1; }
TARGET="$(fm_resolve "/home/$U" "$REL" "$N")" || { echo "outside home"; exit 1; }
[[ "$TARGET" != "/home/$U" ]] || { echo "refusing home root"; exit 1; }
fm_panel_path "$TARGET" "$U" && { echo "refusing panel-managed path"; exit 1; }
[[ -d "$(dirname "$TARGET")" ]] || { echo "no such dir"; exit 1; }
[[ -e "$TARGET" || -L "$TARGET" ]] && { echo "already exists"; exit 1; }
if [[ "$MODE" == "file-new.sh" ]]; then
  : > "$TARGET"
  chown "$U:" "$TARGET"; chmod 640 "$TARGET"
  setfacl -m u:nobody:r "$TARGET" 2>/dev/null || true
  echo "created file $N"
else
  mkdir "$TARGET"
  chown "$U:" "$TARGET"; chmod 755 "$TARGET"
  setfacl -m u:nobody:rX "$TARGET" 2>/dev/null || true
  echo "created dir $N"
fi
