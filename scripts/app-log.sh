#!/usr/bin/env bash
# app-log.sh <username> <name> [lines] — journal tail for one supervised app.
set -euo pipefail
U="${1:?}"; N="${2:?}"; L="${3:-50}"
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad user"; exit 1; }
[[ "$N" =~ ^[a-z0-9_-]{1,32}$ ]] || { echo "bad name"; exit 1; }
[[ "$L" =~ ^[0-9]{1,3}$ ]] || L=50
(( L >= 10 && L <= 200 )) || L=50
journalctl -u "karnak-$U-$N" -n "$L" --no-pager 2>&1 | tail -n "$L"
