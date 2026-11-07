#!/usr/bin/env bash
# symlink-scan.sh — READ-ONLY report of symlinks under /home/<user>/:
#   CROSS     the link resolves into a different home (or a target owned by
#             another account) — the cross-account leak; investigate.
#   MISMATCH  same account, but link owner != target owner (e.g. a link made
#             by root): OLS allowSymbolLink 2 answers 403 for these, fix with
#             `chown -h <user>: <link>` if the link is wanted.
# Changes nothing. Exit 0 always; one line per finding, then a summary.
set -uo pipefail
nc=0; nm=0
while IFS= read -r -d '' L; do
  U="${L#/home/}"; U="${U%%/*}"
  LO="$(stat -c %U -- "$L" 2>/dev/null)" || continue
  R="$(readlink -f -- "$L" 2>/dev/null)" || R=""
  TO="$(stat -L -c %U -- "$L" 2>/dev/null || echo '?')"
  if [[ -n "$R" && "$R" == /home/* && "$R" != "/home/$U" && "$R" != "/home/$U/"* ]] \
     || [[ "$TO" != "?" && "$TO" != "$U" && "$TO" != root && "$TO" != "$LO" && "$R" == /home/* ]]; then
    echo "CROSS     $L -> ${R:-$(readlink -- "$L")} (link $LO, target $TO)"; nc=$((nc+1))
  elif [[ "$TO" != "?" && "$LO" != "$TO" ]]; then
    echo "MISMATCH  $L -> $R (link $LO, target $TO)"; nm=$((nm+1))
  fi
done < <(find /home -mindepth 2 -type l -print0 2>/dev/null)
echo "symlink-scan: $nc cross-account, $nm owner-mismatch"
