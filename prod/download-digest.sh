#!/usr/bin/env bash
# download-digest.sh — weekly append of download-stats.sh to a cumulative log
# (log rotation would otherwise eat history). Runs Mondays 06:15 via cron.
set -euo pipefail
OUT="/var/log/karnakpanel/downloads-digest.log"
# boxes that don't serve the website have no downloads log — stay silent
[[ -r /var/log/karnakpanel/karnakpanel.com.access.log ]] || exit 0
{
  echo "===== $(date -u +%FT%TZ) ====="
  "$(dirname "$0")/download-stats.sh" 2>&1 || echo "(stats failed)"
  echo
} >> "$OUT" 2>/dev/null || exit 0
chmod 640 "$OUT" 2>/dev/null || true
