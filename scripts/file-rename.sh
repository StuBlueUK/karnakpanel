#!/usr/bin/env bash
# file-rename.sh <username> <relpath> <oldname> <newname> — rename within one dir.
# GUARDED (fm-common.sh): both names validated, both paths contained (no
# symlinks), target must not exist, never the home root, panel-owned dot
# paths or a live domain's folder/docroot (renaming those breaks the site).
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/fm-common.sh"
U="${1:?}"; REL="${2:-}"; OLD="${3:?}"; NEW="${4:?}"
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad user"; exit 1; }
fm_name_ok "$OLD" || { echo "bad old name"; exit 1; }
fm_name_ok "$NEW" || { echo "bad new name"; exit 1; }
fm_rel_ok "$REL" || { echo "bad path"; exit 1; }
id "$U" >/dev/null 2>&1 || { echo "no such user"; exit 1; }
# Every write runs AS THE USER: fm_resolve's symlink walk is a check, and a
# user racing a dir swap (x <-> symlink to /etc) between it and a ROOT
# chmod/mv/rm/create turned it into root-owned-file takeover. As the user,
# a won race only reaches what they could touch anyway.
cd /
AS=(as_user "$U")
SRC="$(fm_resolve "/home/$U" "$REL" "$OLD")" || { echo "outside home"; exit 1; }
DST="$(fm_resolve "/home/$U" "$REL" "$NEW")" || { echo "outside home"; exit 1; }
[[ "$SRC" != "/home/$U" && "$DST" != "/home/$U" ]] || { echo "refusing home root"; exit 1; }
fm_panel_path "$SRC" "$U" || fm_panel_path "$DST" "$U" && { echo "refusing panel-managed path"; exit 1; }
fm_live_root "$SRC" "$U" && { echo "refusing live domain folder"; exit 1; }
[[ -e "$SRC" ]] || { echo "no such file"; exit 1; }
[[ ! -e "$DST" && ! -L "$DST" ]] || { echo "target exists"; exit 1; }
"${AS[@]}" mv -T -- "$SRC" "$DST" 2>/dev/null || { echo "can't rename $OLD (not owned by this account)"; exit 1; }
echo "renamed $OLD to $NEW"
