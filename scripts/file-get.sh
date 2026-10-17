#!/usr/bin/env bash
# file-get.sh <username> <relpath> <name> — print a text file for the editor,
# BASE64 (one line): exec() in PHP strips each line's trailing whitespace —
# CRs, spaces, the final newline — so plain output wasn't byte-exact.
# GUARDED (fm-common.sh): contained (no symlinks), regular file, 512KB max,
# text only (NUL byte = binary, refused), never a panel-owned dot path.
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/fm-common.sh"
U="${1:?}"; REL="${2:-}"; N="${3:?}"
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad user"; exit 1; }
fm_name_ok "$N" || { echo "bad name"; exit 1; }
fm_rel_ok "$REL" || { echo "bad path"; exit 1; }
id "$U" >/dev/null 2>&1 || { echo "no such user"; exit 1; }
TARGET="$(fm_resolve "/home/$U" "$REL" "$N")" || { echo "outside home"; exit 1; }
fm_panel_path "$TARGET" "$U" && { echo "refusing panel-managed path"; exit 1; }
[[ -f "$TARGET" ]] || { echo "not a file"; exit 1; }
[[ "$(stat -c%s "$TARGET")" -le 524288 ]] || { echo "file too big to edit (512KB max)"; exit 1; }
perl -0777 -ne 'exit 1 if /\0/' "$TARGET" 2>/dev/null || { echo "binary file — not editable"; exit 1; }
# read AS THE USER: a leaf swapped for a symlink after the checks above
# then only reaches what the user could read anyway (root cat could not)
runuser -u "$U" -- base64 -w0 -- "$TARGET"
