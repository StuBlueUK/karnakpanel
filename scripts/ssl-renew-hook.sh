#!/usr/bin/env bash
# ssl-renew-hook.sh — certbot --post-hook: web + ftp restarts (as before) plus
# re-sync of the mail cert (LE renews ~60d; without this mail silently falls
# back to... nothing breaks, but the synced files would go stale at expiry).
# Reads the domain recorded by mail-cert-sync.sh; absent = nothing to do.
# Also the renewal-hooks/post hook (certbot-hook.sh), so the systemd timer's
# renewals reload too; our cron run fires both — the second within 2 min is
# skipped. OLS: graceful restart when running; if it's down, start it via its
# unit (a start from here would live in certbot.service's cgroup and die
# with it).
set -euo pipefail
HERE="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
STAMP=/run/skypanel/ssl-renew-hook.stamp
mkdir -p /run/skypanel
if [[ -f "$STAMP" ]] && (( $(date +%s) - $(stat -c %Y "$STAMP") < 120 )); then echo "renew hook: ran under 2 min ago — skipped"; exit 0; fi
touch "$STAMP"
if systemctl is-active --quiet lshttpd 2>/dev/null; then
  /usr/local/lsws/bin/lswsctrl restart 2>&1 | tail -1 || true
else
  systemctl start lshttpd 2>/dev/null || /usr/local/lsws/bin/lswsctrl start 2>&1 | tail -1 || true
fi
systemctl restart vsftpd 2>/dev/null || true
if [[ -f /etc/ssl/skypanel/DOMAIN ]]; then
  D="$(cat /etc/ssl/skypanel/DOMAIN)"
  if [[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]]; then
    "$HERE/mail-cert-sync.sh" "$D" 2>&1 | tail -2 || true
  fi
fi
echo "renew hook done"
