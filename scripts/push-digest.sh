#!/usr/bin/env bash
# push-digest.sh [--test] — pager for box-level urgents: a Telegram bot, a
# Discord or Slack webhook, or any https URL taking JSON (push_provider).
# Polls recent admin-scope notifications (user_id NULL) of urgent types
# and sends each one. Default OFF (no settings = silent exit).
# Generic webhook body: {"source":"karnakpanel","server":<panel address>,
# "type":..,"title":..,"text":..,"urgent":true|false}.
# The bot token / webhook URL is a credential: it goes to curl on stdin
# (-K -), never in argv where every local user's ps could read it.
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
case "$PROV" in telegram|discord|slack|webhook) ;; *) exit 0 ;; esac  # off/unset: silent
# url on stdin as a curl config line; refuse anything that could break out of
# the quoted value (the panel validates the same, this is the last gate)
curl_to() { # url, then curl args
  local url="$1"; shift
  [[ "$url" =~ ^https://[A-Za-z0-9./:_?=\&%~+,\;@!\$\(\)\*-]+$ ]] || return 1
  printf 'url = "%s"\n' "$url" | curl -fsS --max-time 20 -K - "$@" >/dev/null 2>&1
}
send_tg() { # title body urgent(0/1) — urgent disables silent delivery
  local token chat silent
  token="$(get push_token)"; chat="$(get push_chat)"
  [[ "$token" =~ ^[0-9]{5,20}:[A-Za-z0-9_-]{20,80}$ && "$chat" =~ ^-?[0-9]{1,20}$ ]] || return 1
  silent="true"; [[ "${3:-0}" == "1" ]] && silent="false"
  curl_to "https://api.telegram.org/bot$token/sendMessage" --data-urlencode "chat_id=$chat" \
    --data-urlencode "text=KarnakPanel: $1 — $2" \
    --data-urlencode "disable_notification=$silent"
}
send_hook() { # type title body urgent(0/1)
  local url json
  url="$(get push_url)"; [[ -n "$url" ]] || return 1
  json="$(PROV="$PROV" T="$1" TI="$2" B="$3" U="$4" SRV="$(get panel_domain)" python3 -c '
import json, os
p, t, ti, b, u = os.environ["PROV"], os.environ["T"], os.environ["TI"], os.environ["B"], os.environ["U"] == "1"
line = ("🚨 " if u else "⚠️ ") + "KarnakPanel: " + ti + ("" if b == ti else " — " + b)
if p == "discord":
    print(json.dumps({"content": line[:1900], "allowed_mentions": {"parse": []}}))
elif p == "slack":
    print(json.dumps({"text": line[:3000]}))
else:
    print(json.dumps({"source": "karnakpanel", "server": os.environ.get("SRV", ""), "type": t,
                      "title": ti, "text": b, "urgent": u}))
')" || return 1
  curl_to "$url" -H "Content-Type: application/json" --data-binary "$json"
}
push_one() { # type title body
  local urg=0; case "$1" in service|integrity) urg=1;; esac
  if [[ "$PROV" == "telegram" ]]; then send_tg "$2" "$3" "$urg"; else send_hook "$1" "$2" "$3" "$urg"; fi
}
if [[ "${1:-}" == "--test" ]]; then
  if push_one service "test alert" "If this arrives, alerts work. (KarnakPanel test)"; then echo "test sent ($PROV)";
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
