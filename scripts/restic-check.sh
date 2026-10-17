#!/usr/bin/env bash
# restic-check.sh — scheduled integrity proof (weekly via cron). A backup
# that can't restore is an outage: failures notify like one (same pattern
# as offsite-sync). Metadata check weekly; the monthly test-restore to a
# scratch DB rides restic-db.sh from a separate cron line (see below).
set -euo pipefail
BIN="/usr/local/bin/restic"
PASSF="/root/.restic-pass"
REPO="/backup/restic"
[[ -x "$BIN" && -f "$PASSF" && -d "$REPO/keys" ]] || { echo "restic not initialized"; exit 1; }
export RESTIC_PASSWORD_FILE="$PASSF"
fail() {
  echo "restic CHECK FAILED — see output above"
  P="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
  if [[ -n "$P" ]]; then
    MYSQL_PWD="$P" mysql -uskypanel skypanel -e "INSERT INTO notifications(user_id,type,title,body) SELECT NULL,'backup','Backup integrity check FAILED','Scheduled restic check found errors — inspect before the next prune.' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM notifications WHERE user_id IS NULL AND type='backup' AND created_at > (NOW() - INTERVAL 24 HOUR));" >>/var/log/karnakpanel/panel.log 2>&1 || true
  fi
  exit 1
}
"$BIN" check -r "$REPO" 2>&1 | tail -2 || fail
echo "restic check OK"
