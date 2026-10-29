#!/usr/bin/env bash
# cron-glue.sh <on|off> — add or remove the nightly dns-glue-check line in
# /etc/cron.d/skypanel. Its only consumer is the setup-checklist step 6, so
# dismissing the guide retires it (no nightly DNS noise on configured boxes).
# "off" comments the line out instead of deleting it: cron-table-sync treats
# dns-glue-check as admin-owned, so a commented line survives updates (a
# deleted one would be re-added by the next release's table). Backup goes
# outside /etc/cron.d. IDEMPOTENT.
set -euo pipefail
MODE="${1:?usage: cron-glue.sh <on|off>}"
CRON=/etc/cron.d/skypanel
LINE="19 3 * * * root /usr/local/skypanel/scripts/dns-glue-check.sh >/dev/null 2>&1"
[[ "$MODE" == on || "$MODE" == off ]] || { echo "mode must be on|off"; exit 1; }
[[ -f "$CRON" ]] || { echo "no cron file $CRON"; exit 1; }
ACTIVE='^[^#].*root[[:space:]]+/usr/local/skypanel/scripts/dns-glue-check\.sh'
OFF='^#.*root[[:space:]]+/usr/local/skypanel/scripts/dns-glue-check\.sh'
install -d -m 700 /var/lib/karnakpanel/cron-bak
TMP="/etc/cron.d/.skypanel.glue-$$"
if [[ "$MODE" == off ]]; then
  grep -qE "$ACTIVE" "$CRON" || { echo "already absent"; exit 0; }
  cp -p "$CRON" "/var/lib/karnakpanel/cron-bak/skypanel.$(date +%Y%m%d-%H%M%S)"
  sed -E "\%$ACTIVE%s/^/#/" "$CRON" > "$TMP"
  echo "glue check retired from cron"
else
  grep -qE "$ACTIVE" "$CRON" && { echo "already present"; exit 0; }
  cp -p "$CRON" "/var/lib/karnakpanel/cron-bak/skypanel.$(date +%Y%m%d-%H%M%S)"
  if grep -qE "$OFF" "$CRON"; then sed -E "\%$OFF%s/^#+//" "$CRON" > "$TMP"
  else { cat "$CRON"; printf '%s\n' "$LINE"; } > "$TMP"; fi
  echo "glue check added to cron"
fi
chown root:root "$TMP"; chmod 644 "$TMP"; mv -f "$TMP" "$CRON"
