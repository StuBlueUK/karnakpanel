#!/usr/bin/env bash
# cron-monitor.sh — dead-man alerts for user cron jobs. Every 5 min via cron.
# A job with expect_min set must FINISH (any exit) at least that often, plus
# grace_min slack. Overdue jobs mail the OWNER once per episode + panel-notify
# them; a fresh run clears state with a recovery notice. Jobs that never ran
# show "never" in the UI but never alert (nothing to compare against).
# Reads ~/.skypanel-cron/<id>.log START/END lines written by cron-wrap.sh.
# Test hooks: CRONMON_STATE_DIR + CRONMON_ALERT_TO (space-separated override).
set -uo pipefail
LOG="/var/log/karnakpanel/panel.log"
STATE_BASE="${CRONMON_STATE_DIR:-/var/lib/skypanel/cronmon}"
mkdir -p "$STATE_BASE" 2>/dev/null || true
HOST="$(hostname -f 2>/dev/null || hostname)"
PASS="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
[[ -n "$PASS" ]] || { echo "no panel db pass"; exit 1; }
notify_user() {
  local uid="$1" msg="$2"
  MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "INSERT INTO notifications(user_id,type,title) SELECT $uid,'cron','$msg' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM notifications WHERE user_id=$uid AND type='cron' AND title='$msg' AND created_at > (NOW() - INTERVAL 1 HOUR));" >>/var/log/karnakpanel/panel.log 2>&1 || echo "$(date -Is) cron-monitor: notify insert failed" >>/var/log/karnakpanel/panel.log
}
recipients_for() {
  local owner_email="$1" out=""
  if [[ "$owner_email" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[a-z]{2,}$ && ! "$owner_email" =~ @localhost$ ]]; then out="$owner_email"; fi
  if [[ -z "$out" ]]; then
    out="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT value FROM settings WHERE name='alert_email';" 2>/dev/null | grep -E '^[^@[:space:]]+@[^@[:space:]]+\.[a-z]{2,}$' | grep -vE '@localhost$' | head -1 || true)"
  fi
  printf '%s' "$out"
}
send_mail() {
  local to="$1" subject="$2" body="$3"
  [[ -n "${to// }" ]] || { echo "$(date -Is) cronmon: no routable recipient — logged only: $subject" >> "$LOG"; return 0; }
  /usr/sbin/sendmail -t 2>/dev/null <<EOF || echo "$(date -Is) cronmon: sendmail to $to failed" >> "$LOG"
To: $to
From: KarnakPanel Cron <cron@$HOST>
Auto-Submitted: auto-generated
Subject: $subject

$body
EOF
}
NOW="$(date +%s)"
while IFS=$'\t' read -r JID OUID UNAME UEMAIL EXPECT GRACE; do
  [[ -n "${JID:-}" && -n "${EXPECT:-}" ]] || continue
  [[ "$JID" =~ ^[0-9]+$ && "$OUID" =~ ^[0-9]+$ ]] || continue
  [[ "$EXPECT" =~ ^[0-9]+$ && "$EXPECT" -ge 5 ]] || continue
  [[ "$GRACE" =~ ^[0-9]+$ ]] || GRACE=15
  [[ "$UNAME" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || continue
  LOGF="/home/$UNAME/.skypanel-cron/$JID.log"
  LAST_END=""
  if [[ -f "$LOGF" ]]; then
    LAST_END="$(grep -E '^=== END exit=[0-9]+ ' "$LOGF" 2>/dev/null | tail -1 | grep -oE '[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9:.+-]+' | head -1 || true)"
  fi
  STATE="$STATE_BASE/$JID"
  if [[ -z "$LAST_END" ]]; then
    [[ -f "$STATE" ]] && rm -f "$STATE"
    continue  # never ran: UI shows it, no alert possible
  fi
  LAST_TS="$(date -d "$LAST_END" +%s 2>/dev/null || echo 0)"
  OVER=$(( EXPECT * 60 + GRACE * 60 ))
  if (( NOW - LAST_TS > OVER )); then
    if [[ ! -f "$STATE" ]]; then
      touch "$STATE"
      TO="${CRONMON_ALERT_TO:-$(recipients_for "$UEMAIL")}"
      echo "$(date -Is) cronmon: OVERDUE job $JID ($UNAME, last run $LAST_END)" >> "$LOG"
      notify_user "$OUID" "cron job #$JID overdue (last run $LAST_END)"
      send_mail "$TO" "[KarnakPanel $HOST] cron job #$JID overdue" "Cron job #$JID (owner $UNAME) has not finished for over $EXPECT min (+$GRACE grace; last run $LAST_END). Check the Cron page."
    fi
  else
    if [[ -f "$STATE" ]]; then
      rm -f "$STATE"
      TO="${CRONMON_ALERT_TO:-$(recipients_for "$UEMAIL")}"
      echo "$(date -Is) cronmon: RECOVERED job $JID ($UNAME)" >> "$LOG"
      notify_user "$OUID" "cron job #$JID running again"
      send_mail "$TO" "[KarnakPanel $HOST] cron job #$JID running again" "Cron job #$JID (owner $UNAME) finished at $LAST_END. No action needed."
    fi
  fi
done < <(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT c.id, c.user_id, u.username, u.email, c.expect_min, c.grace_min FROM cron_jobs c JOIN users u ON u.id=c.user_id WHERE c.active=1 AND c.expect_min IS NOT NULL;")
echo "cronmon done"
