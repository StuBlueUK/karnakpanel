#!/usr/bin/env bash
# inc-list.sh <username> — list incremental snapshot dates (newest first).
set -euo pipefail
U="${1:?}"
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad user"; exit 1; }
ls -1 /backup/skypanel/inc/"$U" 2>/dev/null | grep -E '^[0-9]{8}$' | sort -r || true
