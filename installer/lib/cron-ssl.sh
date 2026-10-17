#!/usr/bin/env bash
# cron + certbot auto-renew
set -euo pipefail
apt-get install -y cron certbot
systemctl enable cron; systemctl start cron
# skypanel scheduled tasks — single source: cron/skypanel.cron. No inline
# fallback: a stale embedded copy once shipped quota-check (long since
# retired) to fresh boxes silently. If the source tree is missing here, the
# install is broken — fail loudly instead of installing yesterday's cron.
SRC_CRON="$(cd "$(dirname "$0")/../.." && pwd)/cron/skypanel.cron"
[[ -f "$SRC_CRON" ]] || { echo "FATAL: cron source missing ($SRC_CRON) — refusing to install a stale cron table"; exit 1; }
cp -f "$SRC_CRON" /etc/cron.d/skypanel
chmod 644 /etc/cron.d/skypanel
