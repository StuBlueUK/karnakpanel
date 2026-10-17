#!/usr/bin/env bash
# app-status.sh <username> <name> — "active|inactive enabled|disabled" for one daemon.
set -euo pipefail
U="${1:?}"; N="${2:?}"
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad user"; exit 1; }
[[ "$N" =~ ^[a-z0-9_-]{1,32}$ ]] || { echo "bad name"; exit 1; }
UNIT="karnak-$U-$N"
ST="inactive"; systemctl is-active --quiet "$UNIT" 2>/dev/null && ST="active"
EN="disabled"; systemctl is-enabled --quiet "$UNIT" 2>/dev/null && EN="enabled"
echo "$ST $EN"
