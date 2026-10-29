#!/usr/bin/env bash
# file-unzip.sh <username> <relpath> <archive> [maxbytes] — extract an archive
# in place: .zip, .tar, .tar.gz/.tgz, .tar.bz2/.tbz2, .tar.xz/.txz.
# GUARDED (fm-common.sh): archive validated + contained; the member listing is
# scanned BEFORE anything is written — absolute paths, ".." components,
# symlink/hardlink/device members and an uncompressed total above maxbytes
# (the panel passes the user's remaining quota; zip-bomb net) are refused.
# Extraction runs AS THE USER (runuser) with no ownership/permission restore
# from the archive, so it can only write what the user could write anyway; no
# root chown/chmod sweep afterwards (that used to reset wp-config 600 → 644).
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/fm-common.sh"
U="${1:?}"; REL="${2:-}"; N="${3:?}"; MAX="${4:-0}"
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad user"; exit 1; }
fm_name_ok "$N" || { echo "bad name"; exit 1; }
[[ "$MAX" =~ ^[0-9]{1,15}$ ]] || { echo "bad size cap"; exit 1; }
fm_rel_ok "$REL" || { echo "bad path"; exit 1; }
id "$U" >/dev/null 2>&1 || { echo "no such user"; exit 1; }
LOW="$(printf '%s' "$N" | tr 'A-Z' 'a-z')"
case "$LOW" in
  *.zip) KIND=zip ;;
  *.tar) KIND=tar; TF="" ;;
  *.tar.gz|*.tgz) KIND=tar; TF="-z" ;;
  *.tar.bz2|*.tbz2) KIND=tar; TF="-j" ;;
  *.tar.xz|*.txz) KIND=tar; TF="-J" ;;
  *) echo "not a supported archive (.zip .tar .tar.gz .tgz .tar.bz2 .tar.xz)"; exit 1 ;;
esac
DIR="$(fm_resolve "/home/$U" "$REL" "")" || { echo "outside home"; exit 1; }
ARC="$(fm_resolve "/home/$U" "$REL" "$N")" || { echo "outside home"; exit 1; }
fm_panel_path "$ARC" "$U" && { echo "refusing panel-managed path"; exit 1; }
[[ -d "$DIR" ]] || { echo "no such dir"; exit 1; }
[[ -f "$ARC" ]] || { echo "no such archive"; exit 1; }
AS=(as_user "$U")
LIST="$(mktemp)"; trap 'rm -f "$LIST"' EXIT
if [[ "$KIND" == zip ]]; then
  command -v unzip >/dev/null 2>&1 || { echo "unzip not installed"; exit 1; }
  # zipinfo: one member per line, names may hold spaces — name is field 9+;
  # -1 gives bare names. Perm column 'l' = symlink member. (awk, not grep -q:
  # grep -q exits early → SIGPIPE → pipefail turns a hit into a miss)
  "${AS[@]}" zipinfo -1 "$ARC" > "$LIST" 2>/dev/null || { echo "not a valid zip"; exit 1; }
  if "${AS[@]}" zipinfo "$ARC" 2>/dev/null | awk '/^l/{f=1} END{exit !f}'; then echo "REFUSED: archive contains symlinks"; exit 1; fi
  TOTAL="$("${AS[@]}" unzip -l "$ARC" 2>/dev/null | tail -1 | awk '{print $1}')"
else
  "${AS[@]}" tar $TF -tf "$ARC" > "$LIST" 2>/dev/null || { echo "not a valid tar archive"; exit 1; }
  # verbose listing: type char first (- d only; l h c b p refused); size col 3
  if "${AS[@]}" tar $TF -tvf "$ARC" 2>/dev/null | awk '!/^[-d]/{f=1} END{exit !f}'; then echo "REFUSED: archive contains links or special files"; exit 1; fi
  TOTAL="$("${AS[@]}" tar $TF -tvf "$ARC" 2>/dev/null | awk '{s+=$3} END{printf "%d", s}')"
fi
[[ -s "$LIST" ]] || { echo "archive is empty"; exit 1; }
# NOTE: -E with \. in single quotes = literal dot (an earlier \\. let ../ through)
if grep -qE '^/|(^|/)\.\.(/|$)' "$LIST"; then echo "REFUSED: archive contains unsafe paths"; exit 1; fi
# never land on panel-owned dot paths at the home root
if [[ "$DIR" == "/home/$U" ]] && grep -qE '^(\./)?(\.ssh|\.php-sessions|\.skypanel-cron|\.trash)(/|$)' "$LIST"; then
  echo "REFUSED: archive would write panel-managed paths"; exit 1
fi
[[ "$TOTAL" =~ ^[0-9]+$ ]] || TOTAL=0
if [[ "$MAX" -gt 0 && "$TOTAL" -gt "$MAX" ]]; then
  echo "REFUSED: unpacks to $(( (TOTAL+1048575)/1048576 )) MB — more than your remaining disk quota"; exit 1
fi
COUNT="$(grep -cvE '/$' "$LIST" || true)"
cd "$DIR"
if [[ "$KIND" == zip ]]; then
  # unzip exit 1 = warnings only (e.g. odd timestamps) — still extracted
  RC=0; MSG="$("${AS[@]}" unzip -o -q "$ARC" -d "$DIR" 2>&1)" || RC=$?
  [[ "$RC" -le 1 ]] || { echo "extract failed: $(printf '%s' "$MSG" | tail -1)"; exit 1; }
else
  RC=0; MSG="$("${AS[@]}" tar $TF -xf "$ARC" -C "$DIR" --no-same-owner --no-same-permissions --no-overwrite-dir 2>&1)" || RC=$?
  [[ "$RC" -eq 0 ]] || { echo "extract failed: $(printf '%s' "$MSG" | tail -1)"; exit 1; }
fi
# panel (nobody) read access on what was just extracted — as the user (owner
# may set ACLs), physical walk only, top-level members of the archive only.
if command -v setfacl >/dev/null 2>&1; then
  sed -E 's#^\./##; s#/.*##' "$LIST" | sort -u | while IFS= read -r TOP; do
    [[ -n "$TOP" && "$TOP" != "." && -e "$DIR/$TOP" && ! -L "$DIR/$TOP" ]] || continue
    "${AS[@]}" setfacl -R -P -m "$(acl_for "/home/$U" "$DIR/$TOP" rX)" -- "$DIR/$TOP" 2>/dev/null || true
  done
fi
echo "extracted $N ($COUNT files)"
