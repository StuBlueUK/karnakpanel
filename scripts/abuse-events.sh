# abuse-events.sh — sourced (never run): one line per cron/daemon event the
# Abuse board flags (proc-live.sh reads the last 24 h, user-abuse.sh trims).
#   <epoch> TAB <user> TAB <kind> TAB <detail>
# kinds: cron-timeout cron-killed cron-skip cron-oom daemon-oom
#        daemon-refused daemon-crashloop
# Root-only file. Detail is squeezed to plain characters here: it never
# carries tenant text (commands) — the board shows names and counts only.
ABUSE_EVENTS=/var/lib/skypanel/abuse-events.log
abuse_event() { # <user> <kind> [detail]
  [[ "$1" =~ ^[a-z][a-z0-9_]{2,31}$ && "$2" =~ ^[a-z-]{3,24}$ ]] || return 0
  local d="${3:-}"; d="${d//[^A-Za-z0-9 _.:\/-]/}"
  install -d -m 755 "${ABUSE_EVENTS%/*}" 2>/dev/null || true
  printf '%s\t%s\t%s\t%s\n' "$(date +%s)" "$1" "$2" "${d:0:80}" >> "$ABUSE_EVENTS" 2>/dev/null || true
  chmod 600 "$ABUSE_EVENTS" 2>/dev/null || true
}
