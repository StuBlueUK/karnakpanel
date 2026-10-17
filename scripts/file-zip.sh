#!/usr/bin/env bash
# file-zip.sh <username> <relpath> <archive> <name>... — pack a selection into
# one archive in the same dir: .zip, or .tar.gz/.tgz by the archive's name.
# GUARDED (fm-common.sh): all names validated + contained (no symlinks),
# archive must not exist, never includes itself or panel-owned dot paths.
# Packs AS THE USER with symlinks stored as links (zip -y, tar default) and NO
# root fallback — a root retry used to follow links into root-only files.
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/fm-common.sh"
U="${1:?}"; REL="${2:-}"; ARC="${3:?}"; shift 3
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad user"; exit 1; }
fm_name_ok "$ARC" || { echo "bad archive name"; exit 1; }
case "$(printf '%s' "$ARC" | tr 'A-Z' 'a-z')" in
  *.zip) KIND=zip ;;
  *.tar.gz|*.tgz) KIND=tgz ;;
  *) echo "archive must end .zip or .tar.gz"; exit 1 ;;
esac
fm_rel_ok "$REL" || { echo "bad path"; exit 1; }
id "$U" >/dev/null 2>&1 || { echo "no such user"; exit 1; }
[[ $# -ge 1 && $# -le 100 ]] || { echo "select 1-100 files"; exit 1; }
DIR="$(fm_resolve "/home/$U" "$REL" "")" || { echo "outside home"; exit 1; }
OUT="$(fm_resolve "/home/$U" "$REL" "$ARC")" || { echo "outside home"; exit 1; }
fm_panel_path "$OUT" "$U" && { echo "refusing panel-managed path"; exit 1; }
[[ -d "$DIR" ]] || { echo "no such dir"; exit 1; }
[[ ! -e "$OUT" && ! -L "$OUT" ]] || { echo "archive exists"; exit 1; }
ARGS=()
for N in "$@"; do
  fm_name_ok "$N" || { echo "bad name: $N"; exit 1; }
  [[ "$N" != "$ARC" ]] || { echo "refusing to include the archive itself"; exit 1; }
  T="$(fm_resolve "$DIR" "" "$N")" || { echo "outside dir: $N"; exit 1; }
  fm_panel_path "$T" "$U" && { echo "refusing panel-managed path: $N"; exit 1; }
  [[ -e "$T" ]] || { echo "missing: $N"; exit 1; }
  ARGS+=("$N")
done
cd "$DIR"
if [[ "$KIND" == zip ]]; then
  runuser -u "$U" -- zip -qry -- "$ARC" "${ARGS[@]}" || { echo "zip failed (unreadable files?)"; exit 1; }
else
  runuser -u "$U" -- tar -czf "$ARC" -- "${ARGS[@]}" || { echo "tar failed (unreadable files?)"; exit 1; }
fi
setfacl -m u:nobody:r -- "$OUT" 2>/dev/null || true
echo "packed ${#ARGS[@]} item(s) into $ARC"
