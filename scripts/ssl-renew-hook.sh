#!/usr/bin/env bash
# ssl-renew-hook.sh — certbot --post-hook: web + ftp restarts (as before) plus
# re-sync of the mail cert (LE renews ~60d; without this mail silently falls
# back to... nothing breaks, but the synced files would go stale at expiry).
# Reads the domain recorded by mail-cert-sync.sh; absent = nothing to do.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
/usr/local/lsws/bin/lswsctrl restart 2>&1 | tail -1 || true
systemctl restart vsftpd 2>/dev/null || true
if [[ -f /etc/ssl/skypanel/DOMAIN ]]; then
  D="$(cat /etc/ssl/skypanel/DOMAIN)"
  if [[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]]; then
    "$HERE/mail-cert-sync.sh" "$D" 2>&1 | tail -2 || true
  fi
fi
echo "renew hook done"
