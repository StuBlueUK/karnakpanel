#!/usr/bin/env bash
# system-reboot.sh [go|cancel] — reboot (or cancel a pending reboot) DETACHED.
# The panel sudo wrapper kills scripts after 15s, but shutdown returns
# instantly: "go" schedules +1 min so the panel can flash back first.
# Without args it only REPORTS pending shutdown state (safe to call anytime).
set -euo pipefail
MODE="${1:-check}"
case "$MODE" in
  go)
    shutdown -r +1 "KarnakPanel admin requested reboot" 2>&1
    echo "reboot scheduled in 1 minute (cancel: system-reboot.sh cancel or: shutdown -c)"
    ;;
  cancel)
    shutdown -c 2>&1 || true
    echo "pending reboot cancelled"
    ;;
  *)
    if [[ -f /run/nologin ]]; then
      echo "a reboot/shutdown is already scheduled (cancel: system-reboot.sh cancel)"
    else
      echo "no reboot pending"
    fi
    [[ -f /var/run/reboot-required ]] && echo "REBOOT REQUIRED (kernel/services updated)" || true
    ;;
esac
