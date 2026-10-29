#!/usr/bin/env bash
# restic-offsite.sh — sync the restic repo to the encrypted offsite remote
# (same rclone crypt remote as the tarball mirror, under restic/). Shares the
# restic lock: a sync must never copy the repo mid-prune (torn remote).
# NOTE: this is a mirror (deletions propagate), NOT append-only — the UI
# must say so (honesty rule). True append-only is a later phase.
set -euo pipefail
RCLONE="$(command -v rclone || echo /usr/bin/rclone)"
CONF="/root/.config/rclone/rclone.conf"
LOG="/var/log/karnakpanel/offsite.log"
SRC="/backup/restic"
# --after-backup (nightly cron only — the panel's on-demand run has a short
# timeout): wait up to 4h for a backup-all still running, then sync anyway
# (a partial set beats none; failing here would raise a false alert).
if [[ "${1:-}" == "--after-backup" ]]; then
  flock -w 14400 /run/lock/karnak-backup-all.lock true 2>/dev/null || echo "$(date -Is) restic-offsite: backup-all still running after 4h — syncing anyway" >> /var/log/karnakpanel/offsite.log
fi
[[ -x "$RCLONE" ]] || { echo "rclone missing"; exit 1; }
[[ -f "$CONF" ]] || { echo "offsite not configured (Backups page)"; exit 1; }
grep -q '^\[offsite-crypt\]' "$CONF" || { echo "crypt remote missing — re-run setup"; exit 1; }
[[ -d "$SRC/keys" ]] || { echo "no local restic repo yet"; exit 1; }
mkdir -p /run/skypanel
exec 9>/run/skypanel/restic.lock
flock -w 900 9 || { echo "restic lock busy"; exit 1; }
mkdir -p "$(dirname "$LOG")"
echo "=== $(date -Is) restic offsite sync start ===" >> "$LOG"
if "$RCLONE" --config "$CONF" sync "$SRC" offsite-crypt:restic/ --fast-list --transfers 4 --log-file "$LOG" >> "$LOG" 2>&1; then
  echo "=== $(date -Is) restic offsite sync OK ===" >> "$LOG"
  echo "sync OK"
else
  echo "=== $(date -Is) restic offsite sync FAILED ===" >> "$LOG"
  echo "sync FAILED — see Logs → offsite"
  exit 1
fi
