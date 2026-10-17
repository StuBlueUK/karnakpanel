#!/usr/bin/env bash
# cron-glue.sh <on|off> — add or remove the nightly dns-glue-check line in
# /etc/cron.d/skypanel. Its only consumer is the setup-checklist step 6, so
# dismissing the guide retires it (no nightly DNS noise on configured boxes).
# Anchored exact-line match + backup before write; anything unexpected aborts
# without touching the file. IDEMPOTENT.
set -euo pipefail
MODE="${1:?usage: cron-glue.sh <on|off>}"
CRON=/etc/cron.d/skypanel
LINE="19 3 * * * root /usr/local/skypanel/scripts/dns-glue-check.sh >/dev/null 2>&1"
[[ "$MODE" == on || "$MODE" == off ]] || { echo "mode must be on|off"; exit 1; }
[[ -f "$CRON" ]] || { echo "no cron file $CRON"; exit 1; }
if [[ "$MODE" == off ]]; then
  grep -qF "dns-glue-check.sh" "$CRON" || { echo "already absent"; exit 0; }
  cp -a "$CRON" "$CRON.bak-$(date +%Y%m%d)"
  grep -vF "dns-glue-check.sh" "$CRON" > "$CRON.tmp" && mv "$CRON.tmp" "$CRON"
  chmod 644 "$CRON"
  echo "glue check retired from cron"
else
  grep -qF "dns-glue-check.sh" "$CRON" && { echo "already present"; exit 0; }
  cp -a "$CRON" "$CRON.bak-$(date +%Y%m%d)"
  printf '%s\n' "$LINE" >> "$CRON"
  chmod 644 "$CRON"
  echo "glue check added to cron"
fi
