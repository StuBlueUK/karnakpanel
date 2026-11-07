#!/usr/bin/env bash
# certbot-hook.sh — install the renewal post-hook so EVERY `certbot renew`
# reloads OLS/vsftpd and re-syncs the mail cert (ssl-renew-hook.sh), not just
# our cron line (which passes --post-hook itself). The distro certbot.timer
# runs `certbot renew` twice a day with no hook: whichever run renews first
# wins, and a timer renewal left OLS/mail serving the old cert until the
# next restart. Called by the installer and host-converge. Idempotent, quiet
# when nothing changes. No args.
set -euo pipefail
D=/etc/letsencrypt/renewal-hooks/post
F="$D/karnakpanel.sh"
WANT='#!/bin/sh
# installed by KarnakPanel (certbot-hook.sh) — runs after any certbot renew
exec /usr/local/skypanel/scripts/ssl-renew-hook.sh'
command -v certbot >/dev/null 2>&1 || exit 0
if [[ -f "$F" && "$(cat "$F")" == "$WANT" && -x "$F" ]]; then exit 0; fi
install -d -m 755 "$D"
printf '%s\n' "$WANT" > "$F.tmp"; chmod 755 "$F.tmp"; chown root:root "$F.tmp"; mv -f "$F.tmp" "$F"
echo "certbot-hook: renewal post-hook installed ($F)"
