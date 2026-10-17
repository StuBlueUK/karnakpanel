#!/usr/bin/env bash
# offsite-sync.sh — nightly mirror of /backup/skypanel to the encrypted offsite
# remote (configured via offsite-setup.sh). rclone sync = offsite converges to
# local (deletes remote files deleted locally past retention — the 14-day
# local prune in backup-all.sh bounds remote growth the same way). Runs after
# backup-all via cron; also on demand from Backups. Failures notify (the box
# without offsite copies is one disk away from nothing).
set -euo pipefail
RCLONE="$(command -v rclone || echo /usr/bin/rclone)"
CONF="/root/.config/rclone/rclone.conf"
LOG="/var/log/karnakpanel/offsite.log"
SRC="/backup/skypanel"
[[ -x "$RCLONE" ]] || { echo "rclone missing"; exit 1; }
[[ -f "$CONF" ]] || { echo "offsite not configured (Backups page)"; exit 1; }
grep -q '^\[offsite-crypt\]' "$CONF" || { echo "crypt remote missing — re-run setup"; exit 1; }
mkdir -p "$(dirname "$LOG")"
echo "=== $(date -Is) offsite sync start ===" >> "$LOG"
if "$RCLONE" --config "$CONF" sync "$SRC" offsite-crypt: --fast-list --transfers 4 --log-file "$LOG" >> "$LOG" 2>&1; then
  echo "=== $(date -Is) offsite sync OK ===" >> "$LOG"
  echo "sync OK"
else
  echo "=== $(date -Is) offsite sync FAILED ===" >> "$LOG"
  if [[ -f /root/.skypanel-dbpass ]]; then
    P="$(cat /root/.skypanel-dbpass)"
    MYSQL_PWD="$P" mysql -uskypanel skypanel -e "INSERT INTO notifications(user_id,type,title) SELECT NULL,'offsite','Offsite backup sync FAILED' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM notifications WHERE user_id IS NULL AND type='offsite' AND created_at > (NOW() - INTERVAL 24 HOUR));" >>/var/log/karnakpanel/panel.log 2>&1 || echo "$(date -Is) offsite-sync: notify insert failed" >>/var/log/karnakpanel/panel.log
  fi
  echo "sync FAILED — see Logs → offsite"
  exit 1
fi
