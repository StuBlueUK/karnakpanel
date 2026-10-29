#!/usr/bin/env bash
# file-chmod.sh <username> <relpath> <name> <mode> — set permissions on one
# file or dir in the user's home. GUARDED (fm-common.sh): name validated,
# mode 3 octal digits, contained (no symlinks), never the home root or a
# panel-owned dot path.
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/fm-common.sh"
U="${1:?}"; REL="${2:-}"; N="${3:?}"; M="${4:?}"
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad user"; exit 1; }
fm_name_ok "$N" || { echo "bad name"; exit 1; }
[[ "$M" =~ ^[0-7]{3}$ ]] || { echo "mode must be 3 octal digits (e.g. 644)"; exit 1; }
fm_rel_ok "$REL" || { echo "bad path"; exit 1; }
id "$U" >/dev/null 2>&1 || { echo "no such user"; exit 1; }
# Every write runs AS THE USER: fm_resolve's symlink walk is a check, and a
# user racing a dir swap (x <-> symlink to /etc) between it and a ROOT
# chmod/mv/rm/create turned it into root-owned-file takeover. As the user,
# a won race only reaches what they could touch anyway.
cd /
AS=(as_user "$U")
TARGET="$(fm_resolve "/home/$U" "$REL" "$N")" || { echo "outside home"; exit 1; }
[[ "$TARGET" != "/home/$U" ]] || { echo "refusing home root"; exit 1; }
fm_panel_path "$TARGET" "$U" && { echo "refusing panel-managed path"; exit 1; }
[[ -e "$TARGET" ]] || { echo "no such file"; exit 1; }
# "other" is every OTHER hosting account (cron runs as them): never granted.
# The web server reads via the u:nobody ACL (docroots), the panel via its
# own (acl_for), not "other", so 644/755 habits lose nothing. (chmod on an ACL file sets the mask from the group digit.)
# Files without the ACL (PHP move_uploaded_file(), mv'd in by cron) were
# served only via "other": give nobody its entry FIRST or 644 -> 640 = 403.
REQ="$M"; M="${M:0:2}0"
S="$(acl_for "/home/$U" "$TARGET" rX)"; W="${S#u:}"; W="${W%%:*}"   # first uid the spec names
"${AS[@]}" getfacl -cp -- "$TARGET" 2>/dev/null | grep -q "^user:$W:" || "${AS[@]}" setfacl -m "$S" -- "$TARGET" 2>/dev/null || true
"${AS[@]}" chmod "$M" -- "$TARGET" 2>/dev/null || { echo "can't change $N (not owned by this account)"; exit 1; }
# 604/705-style requests: the web server's read came from "other"; keep it
# via the mask (the mask follows the group digit after chmod)
if (( ${REQ:2:1} & 4 )) && ! (( ${M:1:1} & 4 )); then "${AS[@]}" setfacl -m m::rX -- "$TARGET" 2>/dev/null || true; fi
if [[ "$M" != "$REQ" ]]; then echo "set $N to $M (other-account access stays off)"; else echo "set $N to $M"; fi
