#!/usr/bin/env bash
# restic-verify.sh — monthly restorability proof (cron): for every user with
# databases, stream that account's LATEST kind=db snapshot through the dump
# readability check (no live data touched). Failures notify like an outage.
set -euo pipefail
BIN="/usr/local/bin/restic"
PASSF="/root/.restic-pass"
REPO="/backup/restic"
[[ -x "$BIN" && -f "$PASSF" && -d "$REPO/keys" ]] || { echo "restic not initialized — nothing to verify"; exit 0; }
export RESTIC_PASSWORD_FILE="$PASSF"
P="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
[[ -n "$P" ]] || exit 0
FAIL=0
while IFS= read -r row; do
  U="${row%% *}"; DB="${row##* }"
  [[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ && "$DB" =~ ^[a-z][a-z0-9_]{2,63}$ ]] || continue
  SNAP="$("$BIN" snapshots -r "$REPO" --json --tag "account=$U" 2>/dev/null | python3 -c "import json,sys; ss=[s for s in json.load(sys.stdin) if 'kind=db' in ' '.join(s.get('tags',[]))]; print(max(ss, key=lambda s: s['time'])['id'][:8] if ss else '')" 2>/dev/null || true)"
  if [[ -z "$SNAP" ]]; then echo "no db snapshot for $U (skipped)"; continue; fi
  if "$(dirname "$0")/restic-db.sh" "$U" "$SNAP" "$DB" verify-only 2>&1 | tail -1 | grep -q "parses OK"; then
    echo "verify OK: $U/$DB@$SNAP"
  else
    echo "VERIFY FAILED: $U/$DB@$SNAP"; FAIL=1
  fi
done < <(MYSQL_PWD="$P" mysql -uskypanel skypanel -N -e "SELECT u.username, m.db_name FROM mysql_dbs m JOIN users u ON u.id=m.user_id;" 2>/dev/null || true)
if [[ "$FAIL" == "1" ]]; then
  MYSQL_PWD="$P" mysql -uskypanel skypanel -e "INSERT INTO notifications(user_id,type,title,body) SELECT NULL,'backup','Monthly restore proof FAILED','A restic dump failed its readability check — inspect before relying on it.' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM notifications WHERE user_id IS NULL AND type='backup' AND created_at > (NOW() - INTERVAL 24 HOUR));" >>/var/log/karnakpanel/panel.log 2>&1 || true
  echo "verify FAILED — admin paged"; exit 1
fi
echo "all restic dumps verify OK"
