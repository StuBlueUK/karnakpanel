#!/usr/bin/env bash
# backup-bg.sh start|status <username> — run backup-account.sh in a transient
# unit (karnak-backup-<user>) so "Create backup now" never holds the page or
# hits the panel's request timeout. backup-account.sh registers its own row
# when done. status prints running|idle. One run per account.
set -euo pipefail
M="${1:-}"; U="${2:-}"
[[ "$M" =~ ^(start|status)$ && "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "usage: $0 start|status <user>"; exit 1; }
HERE="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
UNIT="karnak-backup-$U"
if [[ "$M" == status ]]; then
  systemctl is-active --quiet "$UNIT" && echo running || echo idle
  exit 0
fi
# same scope as backup-account.sh (admin included): a real user with a home
getent passwd "$U" >/dev/null && [[ -d "/home/$U" ]] || { echo "refused: no such account $U"; exit 1; }
systemctl is-active --quiet "$UNIT" && { echo "already running"; exit 0; }
systemctl reset-failed "$UNIT" 2>/dev/null || true
# flock: panel-started runs take turns — runs in the same second would
# otherwise write the same db-<TS>.sql.gz at once
systemd-run --unit="$UNIT" --collect --quiet -p Nice=10 -p IOSchedulingClass=idle /usr/bin/flock /run/lock/karnak-backup.lock "$HERE/backup-account.sh" "$U"
echo "started"
