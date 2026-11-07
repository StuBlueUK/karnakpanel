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
    SKY_NO_DB=1 flock "$BL" /usr/local/skypanel/scripts/backup-account.sh "$(basename "$h")" || true
    flock "$BL" /usr/local/skypanel/scripts/backup-inc.sh "$(basename "$h")" || true
  fi
done
# one whole-server DB dump per run (the per-account calls skip it)
[[ "$RESTIC_ON" == "1" ]] || flock "$BL" /usr/local/skypanel/scripts/backup-account.sh --db-only || true
find /backup/skypanel -mtime +14 -delete 2>/dev/null || true
# ...and their panel rows: a listed backup with no file can't be downloaded
# or restored. Rows are only ever inserted after their file exists
# (backup-account.sh, offsite fetch), so a missing file here means gone.
# Only rows past the prune age: an unmounted backup disk can't wipe the list.
if [[ -n "$P" ]]; then
  while IFS=$'\t' read -r id fn; do
    [[ "$id" =~ ^[0-9]+$ && "$fn" =~ ^[a-zA-Z0-9_.-]+$ ]] || continue
    [[ -e "/backup/skypanel/$fn" ]] && continue
    MYSQL_PWD="$P" mysql -uskypanel skypanel -e "DELETE FROM backups WHERE id=$id" 2>/dev/null && echo "unlisted pruned backup $fn"
  done < <(MYSQL_PWD="$P" mysql -uskypanel skypanel -N -e "SELECT id, filename FROM backups WHERE created_at < NOW() - INTERVAL 14 DAY" 2>/dev/null)
fi
echo "all backed up (engine: $([[ "$RESTIC_ON" == "1" ]] && echo restic || echo tarball))"
