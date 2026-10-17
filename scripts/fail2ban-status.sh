#!/usr/bin/env bash
# fail2ban-status.sh — read-only jail summary for Admin > Services (the panel
# can only sudo scripts, so a bare `sudo fail2ban-client` from PHP was refused
# and the card showed sudo's error). No args; prints the jail list, then each
# jail's current ban count + banned IPs.
set -euo pipefail
command -v fail2ban-client >/dev/null || { echo "fail2ban not installed"; exit 1; }
STATUS="$(timeout 5 fail2ban-client status 2>&1)" || { echo "$STATUS"; exit 1; }
echo "$STATUS"
JAILS="$(sed -n 's/.*Jail list:[[:space:]]*//p' <<<"$STATUS" | tr -d ' ' | tr ',' ' ')"
for j in $JAILS; do
  [[ "$j" =~ ^[A-Za-z0-9_.-]+$ ]] || continue
  out="$(timeout 5 fail2ban-client status "$j" 2>/dev/null || true)"
  n="$(sed -n 's/.*Currently banned:[[:space:]]*//p' <<<"$out")"
  ips="$(sed -n 's/.*Banned IP list:[[:space:]]*//p' <<<"$out")"
  printf '%s: %s banned%s\n' "$j" "${n:-?}" "${ips:+ — $ips}"
done
