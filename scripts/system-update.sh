#!/usr/bin/env bash
# system-update.sh [go] — apt update + upgrade for the whole box, DETACHED.
# The panel sudo wrapper kills scripts after 15s, so the real work backgrounds
# into /var/log/karnakpanel/apt-upgrade.log (watch it in admin Logs). Without
# "go" it only refreshes package lists and REPORTS what would change.
# Upgrade (not dist-upgrade): no new packages, no removals — services restart
# in place; a reboot may still be flagged (shown in the panel UI).
set -euo pipefail
LOG="/var/log/karnakpanel/apt-upgrade.log"
export DEBIAN_FRONTEND=noninteractive
(
  echo "=== $(date -Is) update started (mode: ${1:-check}) ==="
  apt-get update 2>&1 | tail -2
  if [[ "${1:-}" == "go" ]]; then
    apt-get upgrade -y 2>&1 | tail -5
    [[ -f /var/run/reboot-required ]] && echo "REBOOT REQUIRED" || echo "no reboot required"
  else
    apt-get -s upgrade 2>&1 | grep -E "^[0-9]+ upgraded" || echo "nothing to upgrade"
  fi
  echo "=== $(date -Is) done ==="
) >> "$LOG" 2>&1 &
echo "started, follow /admin/logs?log=upgrade"
