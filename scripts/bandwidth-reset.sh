#!/usr/bin/env bash
# bandwidth-reset.sh — monthly (1st, 00:05): lift ONLY automatic bandwidth
# suspensions (bw_suspended=1). Manual suspends are untouched — the panel clears
# the flag on any manual suspend/unsuspend, so human decisions always stick.
set -euo pipefail
PASS="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
[[ -n "$PASS" ]] || { echo "no panel db pass"; exit 1; }
SUSPENDER="$(dirname "$0")/suspend-account.sh"
while IFS= read -r U; do
  [[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || continue
  "$SUSPENDER" "$U" unsuspend 2>/dev/null || true
  MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "UPDATE users SET status='active', bw_suspended=0 WHERE username='$U' AND bw_suspended=1;" 2>/dev/null || true
  UIDN="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT id FROM users WHERE username='$U';" 2>/dev/null || true)"
  if [[ -n "$UIDN" ]]; then
    MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "INSERT INTO notifications(user_id,type,title) VALUES($UIDN,'bandwidth','Account reactivated for the new month');" >>/var/log/karnakpanel/panel.log 2>&1 || echo "$(date -Is) bandwidth-reset: notify insert failed" >>/var/log/karnakpanel/panel.log
  fi
  echo "$(date -Is) bandwidth-reset: lifted $U for the new month" | tee -a /var/log/karnakpanel/panel.log
done < <(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT username FROM users WHERE bw_suspended=1 AND status='suspended';" 2>/dev/null)
echo "bandwidth monthly reset done"
