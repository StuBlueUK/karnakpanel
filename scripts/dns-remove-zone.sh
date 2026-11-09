#!/usr/bin/env bash
# dns-remove-zone.sh <domain> — remove BIND zone file + registration, reconfig.
# Missing pieces are skipped (idempotent).
set -euo pipefail
D="${1:?}"
[[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad domain: $D"; exit 1; }
ESC="${D//./\\.}"
rm -f "/etc/bind/zones/db.$D"
if [[ -f /etc/bind/zones.skypanel ]]; then
  # stanza may be multi-line when signed: delete from 'zone "D" {' through the closing '};'
  perl -0pi -e "s/zone \"$ESC\" \\{[^}]*\\};\n?//" /etc/bind/zones.skypanel
fi
# signing keys are KEPT: re-adding + re-signing reuses them, registrar DS stays valid
rm -f "/tmp/skypanel-$D.zone"   # legacy staging leftover (zones now go on stdin)
rndc reconfig 2>/dev/null || systemctl reload named 2>/dev/null || systemctl reload bind9 2>/dev/null || true
echo "zone $D removed"
