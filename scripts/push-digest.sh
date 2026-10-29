#!/usr/bin/env bash
# push-digest.sh [--test] — phone pager for box-level urgents via a Telegram
# bot. Polls recent admin-scope notifications (user_id NULL) of urgent types
# and sends them with sendMessage. Default OFF (no settings = silent exit).
# No inbound ports, nothing credential-like leaves the box — short status text
# only, never IPs or secrets. Sent IDs tracked in a state file (no schema
# change); quiet hours configurable (service/integrity always bypass).
# Urgent types: service, integrity, egress, offsite, expiry, malware, quota.
# Setup (panel Notifications page): talk to @BotFather once (/newbot), paste
# the token, message the bot anything, press Find chat, Save, Test.
set -euo pipefail
PASS="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
[[ -n "$PASS" ]] || exit 0
STATE="/var/lib/karnakpanel/push-sent"
mkdir -p /var/lib/karnakpanel; touch "$STATE"
get() { MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT value FROM settings WHERE name='$1';" 2>/dev/null || true; }
PROV="$(get push_provider)"
[[ "$PROV" == "telegram" ]] || exit 0  # off/unset: silent
send_tg() { # title body urgent(0/1) — urgent disables silent delivery
  local token chat silent
  token="$(get push_token)"; chat="$(get push_chat)"
  [[ -n "$token" && -n "$chat" ]] || return 1
  silent="true"; [[ "${3:-0}" == "1" ]] && silent="false"
  curl -fsSL --max-time 20 --data-urlencode "chat_id=$chat" \
    --data-urlencode "text=KarnakPanel: $1 — $2" \
    --data-urlencode "disable_notification=$silent" \
    "https://api.telegram.org/bot$token/sendMessage" >/dev/null 2>&1
}
push_one() { # type title body
  case "$1" in service|integrity) send_tg "$2" "$3" 1;; *) send_tg "$2" "$3" 0;; esac
}
if [[ "${1:-}" == "--test" ]]; then
  if push_one service "push test" "If this arrives, phone alerts work. (KarnakPanel test)"; then echo "test sent";
  else echo "test FAILED — check provider settings"; exit 1; fi
  exit 0
fi
# quiet hours: non-critical types wait for daytime (server local time)
H="$(date +%H)"; QF="$(get push_quiet_from)"; QT="$(get push_quiet_to)"
[[ "$QF" =~ ^[0-9]{1,2}$ ]] || QF=22; [[ "$QT" =~ ^[0-9]{1,2}$ ]] || QT=8
quiet_now() {
  if (( QF <= QT )); then (( 10#$H >= QF && 10#$H < QT ))
  else (( 10#$H >= QF || 10#$H < QT )); fi
}
SENT=0
while IFS=$'\t' read -r ID TYPE TITLE; do
  [[ "$ID" =~ ^[0-9]+$ ]] || continue
  grep -qxF "$ID" "$STATE" 2>/dev/null && continue
  URG=0; case "$TYPE" in service|integrity) URG=1;; esac
  if (( URG == 0 )) && quiet_now; then continue; fi  # hold for daytime, keep unmarked
  BODY="$(echo "$TITLE" | cut -c1-180)"
  if push_one "$TYPE" "$TITLE" "$BODY"; then
    echo "$ID" >> "$STATE"; SENT=$((SENT+1))
  fi
done < <(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT id,type,title FROM notifications WHERE user_id IS NULL AND type IN ('service','integrity','egress','offsite','expiry','malware','quota') AND created_at > (NOW() - INTERVAL 6 HOUR) ORDER BY id;" 2>/dev/null)
# prune state (keep 500 recent ids)
tail -500 "$STATE" > "$STATE.tmp" 2>/dev/null && mv "$STATE.tmp" "$STATE"
echo "pushed $SENT"
