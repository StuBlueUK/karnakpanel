#!/usr/bin/env bash
# restic-ls.sh [account] — snapshots --json for the panel browser (and CLI).
# No arg: every snapshot (admin view). With an account: only that account's
# tag (per-user view — a tenant's browser must not enumerate other tenants'
# backup history; the restore endpoints re-verify ownership anyway).
set -euo pipefail
ACC="${1:-}"
BIN="/usr/local/bin/restic"
PASSF="/root/.restic-pass"
REPO="/backup/restic"
[[ -x "$BIN" && -f "$PASSF" && -d "$REPO/keys" ]] || { echo "restic not initialized"; exit 1; }
export RESTIC_PASSWORD_FILE="$PASSF"
if [[ -z "$ACC" ]]; then
  "$BIN" snapshots -r "$REPO" --json 2>/dev/null || echo "[]"
  exit 0
fi
[[ "$ACC" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad account"; exit 1; }
"$BIN" snapshots -r "$REPO" --json --tag "account=$ACC" 2>/dev/null || echo "[]"
