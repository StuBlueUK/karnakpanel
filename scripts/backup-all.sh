#!/usr/bin/env bash
# backup-all.sh — loop all panel users. Engine-aware: when the restic engine
# is enabled (Backups page toggle -> settings restic_enabled=1) AND its repo
# is healthy, accounts snapshot incrementally INSTEAD of full tarballs.
# Tarball prune still runs (old sets age out on their own).
# Locks: the whole run holds karnak-backup-all.lock (a run still going at
# the next start is left alone; offsite-sync/restic-offsite --after-backup
# wait on it), each account takes karnak-backup.lock in turn with panel
# "Create backup now" runs (backup-bg.sh) — same-second runs would write the
# same db-<TS>.sql.gz.
set -euo pipefail
exec 9>/run/lock/karnak-backup-all.lock
flock -n 9 || { echo "backup-all: previous run still going — skipped"; exit 0; }
BL=/run/lock/karnak-backup.lock
RESTIC_ON=0
P="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
if [[ -n "$P" && -x /usr/local/bin/restic && -d /backup/restic/keys ]] && MYSQL_PWD="$P" mysql -uskypanel skypanel -N -e "SELECT value FROM settings WHERE name='restic_enabled';" 2>/dev/null | grep -qx 1; then
  RESTIC_ON=1
fi
for h in /home/*; do
  [[ -d "$h" ]] || continue
  if [[ "$RESTIC_ON" == "1" ]]; then
    flock "$BL" /usr/local/skypanel/scripts/restic-backup.sh "$(basename "$h")" || true
  else
    flock "$BL" /usr/local/skypanel/scripts/backup-account.sh "$(basename "$h")" || true
    flock "$BL" /usr/local/skypanel/scripts/backup-inc.sh "$(basename "$h")" || true
  fi
done
find /backup/skypanel -mtime +14 -delete 2>/dev/null || true
echo "all backed up (engine: $([[ "$RESTIC_ON" == "1" ]] && echo restic || echo tarball))"
