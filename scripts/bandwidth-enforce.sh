#!/usr/bin/env bash
# bandwidth-enforce.sh — suspend active non-admin users at/over their monthly
# bandwidth quota (cPanel-style). Monthly usage = SUM(bandwidth_daily) for the
# current calendar month. Sets users.bw_suspended=1 so the monthly reset knows
# this was AUTOMATIC (manual suspends via the panel clear the flag — manual
# state always wins). Skips already-suspended users entirely. Daily cron.
set -euo pipefail
PASS="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
[[ -n "$PASS" ]] || { echo "no panel db pass"; exit 1; }
notify() { # notify <username> <title> (hourly dedup, best-effort)
  MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "INSERT INTO notifications(user_id,type,title) SELECT (SELECT id FROM users WHERE username='$1'),'bandwidth','$2' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM notifications n JOIN users u ON u.id=n.user_id WHERE u.username='$1' AND n.type='bandwidth' AND n.title='$2' AND n.created_at > (NOW() - INTERVAL 1 HOUR));" >>/var/log/karnakpanel/panel.log 2>&1 || echo "$(date -Is) bandwidth-enforce: notify insert failed" >>/var/log/karnakpanel/panel.log
}
SUSPENDER="$(dirname "$0")/suspend-account.sh"
while IFS=$'\t' read -r U Q ST; do
  [[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || continue
  [[ "$ST" == "active" ]] || continue
  USED=$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT COALESCE(SUM(bytes),0) FROM bandwidth_daily b JOIN users u ON u.id=b.user_id WHERE u.username='$U' AND b.day >= DATE_FORMAT(CURDATE(),'%Y-%m-01');" 2>/dev/null || echo 0)
  LIMIT=$((Q * 1073741824))
  if [[ "$Q" -gt 0 && "$USED" -ge "$LIMIT" ]]; then
    "$SUSPENDER" "$U" suspend 2>/dev/null || true
    MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "UPDATE users SET status='suspended', bw_suspended=1 WHERE username='$U' AND status='active';" 2>/dev/null || true
    echo "$(date -Is) bandwidth-enforce: suspended $U (${USED}B >= ${Q}GB)" | tee -a /var/log/karnakpanel/panel.log
    notify "$U" "Account suspended: bandwidth quota exceeded"
  fi
done < <(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT username, bandwidth_quota_gb, status FROM users WHERE role != 'admin';" 2>/dev/null)
