#!/usr/bin/env bash
# restic-forget.sh — retention pins + prune (weekly via cron, NOT nightly:
# prune rewrites indexes and is the expensive op). Pins, not a sliding
# window: a quietly-corrupted backup can no longer age out the last good
# copy. Same restic lock as backup/offsite (never prune mid-sync).
set -euo pipefail
BIN="/usr/local/bin/restic"
PASSF="/root/.restic-pass"
REPO="/backup/restic"
[[ -x "$BIN" && -f "$PASSF" && -d "$REPO/keys" ]] || { echo "restic not initialized"; exit 1; }
export RESTIC_PASSWORD_FILE="$PASSF"
mkdir -p /run/skypanel
exec 9>/run/skypanel/restic.lock
# wait out the 03:45 offsite sync (a big repo can take longer than minutes;
# a short wait silently skipped the weekly prune)
flock -w 14400 9 || { echo "restic lock busy"; exit 1; }
ionice -c3 -p $$ 2>/dev/null || true
renice -n 10 -p $$ >/dev/null 2>&1 || true
"$BIN" forget -r "$REPO" --keep-daily 14 --keep-weekly 8 --keep-monthly 6 --prune 2>&1 | tail -4
echo "forget+prune done"
