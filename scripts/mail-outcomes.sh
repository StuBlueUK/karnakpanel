#!/usr/bin/env bash
# mail-outcomes.sh [max=200] — recent delivery outcomes from the Exim mainlog,
# newest first: "time|id|result|addr|detail" (result = delivered|bounced|
# deferred). Live log only (rotated logs excluded). Panel paginates.
set -uo pipefail
MAX="${1:-200}"
[[ "$MAX" =~ ^[0-9]{1,3}$ ]] || MAX=200
(( MAX >= 1 && MAX <= 500 )) || MAX=200
LOG="/var/log/exim4/mainlog"
[[ -r "$LOG" ]] || exit 0
tail -3000 "$LOG" 2>/dev/null | grep -E " [A-Za-z0-9]{6}-[A-Za-z0-9]{2,}-[A-Za-z0-9]{2,} (=>|\*\*|==) " | awk -v max="$MAX" '
  {
    ts=$1 " " $2; id=$3; arrow=$4; addr=$5
    gsub(/[\[\]|]/, "", addr); sub(/:$/, "", addr)
    res=""; if (arrow == "=>") res="delivered"; else if (arrow == "**") res="bounced"; else if (arrow == "==") res="deferred"; else next
    det=$0; sub(/^[^ ]+ [^ ]+ [^ ]+ (=>|\*\*|==) [^ ]+ +/, "", det)
    gsub(/\|/, "", det); det=substr(det, 1, 160)
    out[n++]=ts "|" id "|" res "|" addr "|" det
    if (n >= max) exit
  }
  END { for (i=n-1; i>=0; i--) print out[i] }
'
