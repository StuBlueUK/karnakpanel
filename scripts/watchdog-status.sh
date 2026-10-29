#!/usr/bin/env bash
# watchdog-status.sh — last-run age for the panel banners (Services +
# Notifications). Prints "age=<seconds>" or "age=never". Panel runs as nobody;
# the heartbeat file is world-readable by design (a timestamp, no secrets).
set -euo pipefail
F="/var/lib/karnakpanel/watchdog-last"
if [[ -f "$F" ]]; then
  LAST="$(cat "$F" 2>/dev/null || echo 0)"
  NOW="$(date +%s)"
  [[ "$LAST" =~ ^[0-9]+$ ]] || LAST=0
  echo "age=$(( NOW - LAST ))"
else
  echo "age=never"
fi
