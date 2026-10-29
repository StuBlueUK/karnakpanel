#!/usr/bin/env bash
# file-paste.sh <username> copy|move <srcrel> <dstrel> <maxbytes> <name>... —
# copy or move items from one folder to another inside the user's home.
# GUARDED (fm-common.sh): names + both paths validated and contained (no
# symlinks), never the home root, panel-owned dot paths (either side) or —
# for move — a live domain's folder/docroot; never a folder into itself.
# Copies AS THE USER (cp -a: symlinks copied as links, never followed); a
# name clash on copy gets " (copy)", " (copy 2)"…; on move it is refused.
# maxbytes (panel: remaining quota, 0 = no cap) bounds a copy's total size.
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/fm-common.sh"
U="${1:?}"; MODE="${2:?}"; SREL="${3:-}"; DREL="${4:-}"; MAX="${5:-0}"; shift 5
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad user"; exit 1; }
[[ "$MODE" == copy || "$MODE" == move ]] || { echo "bad mode"; exit 1; }
fm_rel_ok "$SREL" && fm_rel_ok "$DREL" || { echo "bad path"; exit 1; }
[[ "$MAX" =~ ^[0-9]{1,15}$ ]] || { echo "bad size cap"; exit 1; }
[[ $# -ge 1 && $# -le 100 ]] || { echo "select 1-100 items"; exit 1; }
id "$U" >/dev/null 2>&1 || { echo "no such user"; exit 1; }
H="/home/$U"; AS=(as_user "$U")
SDIR="$(fm_resolve "$H" "$SREL" "")" || { echo "source outside home"; exit 1; }
DDIR="$(fm_resolve "$H" "$DREL" "")" || { echo "destination outside home"; exit 1; }
[[ -d "$SDIR" && -d "$DDIR" ]] || { echo "no such folder"; exit 1; }
fm_panel_path "$DDIR" "$U" && { echo "refusing panel-managed destination"; exit 1; }
[[ "$MODE" == move && "$SDIR" == "$DDIR" ]] && { echo "already in that folder"; exit 1; }
SRCS=()
for N in "$@"; do
  fm_name_ok "$N" || { echo "bad name: $N"; exit 1; }
  S="$(fm_resolve "$H" "$SREL" "$N")" || { echo "outside home: $N"; exit 1; }
  [[ "$S" != "$H" ]] || { echo "refusing home root"; exit 1; }
  [[ -e "$S" || -L "$S" ]] || { echo "missing: $N"; exit 1; }
  fm_panel_path "$S" "$U" && { echo "refusing panel-managed path: $N"; exit 1; }
  [[ "$MODE" == move ]] && fm_live_root "$S" "$U" && { echo "refusing live domain folder: $N"; exit 1; }
  [[ "$DDIR" == "$S" || "$DDIR" == "$S"/* ]] && { echo "can't put $N inside itself"; exit 1; }
  SRCS+=("$N")
done
if [[ "$MODE" == copy && "$MAX" -gt 0 ]]; then
  TOT=0
  for N in "${SRCS[@]}"; do
    B="$("${AS[@]}" du -sb -- "$SDIR/$N" 2>/dev/null | cut -f1)"; TOT=$((TOT + ${B:-0}))
  done
  [[ "$TOT" -le "$MAX" ]] || { echo "copy is $(( (TOT+1048575)/1048576 )) MB — more than your remaining disk quota"; exit 1; }
fi
OK=0; FAIL=""
for N in "${SRCS[@]}"; do
  D="$DDIR/$N"
  if [[ -e "$D" || -L "$D" ]]; then
    [[ "$MODE" == copy ]] || { FAIL="$FAIL $N(exists)"; continue; }
    base="$N" ext=""
    if [[ ! -d "$SDIR/$N" && "$N" == ?*.* ]]; then base="${N%.*}"; ext=".${N##*.}"; fi
    D="$DDIR/$base (copy)$ext"; i=2
    while [[ -e "$D" || -L "$D" ]]; do D="$DDIR/$base (copy $i)$ext"; i=$((i+1)); [[ $i -lt 100 ]] || break; done
    fm_name_ok "${D##*/}" || { FAIL="$FAIL $N(name)"; continue; }
  fi
  if [[ "$MODE" == copy ]]; then
    if "${AS[@]}" cp -a -T -- "$SDIR/$N" "$D" 2>/dev/null; then
      "${AS[@]}" setfacl -R -P -m "$(acl_for "$H" "$D" rX)" -- "$D" 2>/dev/null || true
      OK=$((OK+1))
    else FAIL="$FAIL $N"; fi
  else
    if "${AS[@]}" mv -T -- "$SDIR/$N" "$D" 2>/dev/null; then OK=$((OK+1)); else FAIL="$FAIL $N"; fi
  fi
done
VERB=$([[ "$MODE" == copy ]] && echo copied || echo moved)
[[ -z "$FAIL" ]] || { echo "$VERB $OK; failed:$FAIL"; exit 1; }
echo "$VERB $OK item(s)"
