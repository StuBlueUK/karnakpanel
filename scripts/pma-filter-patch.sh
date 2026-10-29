#!/usr/bin/env bash
# pma-filter-patch.sh — install the fail2ban filter for the phpMyAdmin
# basic-auth gate (:8092) and reload the jail when it changed. Called by the
# installer (installer/lib/phpmyadmin.sh) and host-converge.
# Only 401s that CARRY a username count. A browser's first request has no
# credentials and always gets a 401 challenge (plus one for favicon.ico), so
# the old "every 401" filter counted 2-3 hits per visit before a password
# was even typed: two page loads banned the admin's own IP on every port
# (stock ufw banaction) — seen on a live box 2026-10-28. Logged lines:
#   '1.2.3.4 - - [..] "GET / HTTP/2" 401 487'        challenge  -> ignored
#   '1.2.3.4 - someone [..] "GET / HTTP/2" 401 487'  bad login  -> counted
# QUIRKS (verified): a literal quote directly before <HOST> never matches, and
# \] inside [^...] breaks matching — so the timestamp is matched with \S+.
# Idempotent, quiet when nothing changes.
set -euo pipefail
F=/etc/fail2ban/filter.d/phpmyadmin.conf
[[ -d /etc/fail2ban/filter.d ]] || exit 0
WANT='# installed by KarnakPanel (pma-filter-patch.sh) — phpMyAdmin gate failed logins
[Definition]
failregex = <HOST> \S+ (?!-\s)\S+ \S+ "[^"]*" 401 \d+
ignoreregex ='
[[ -f "$F" && "$(cat "$F")" == "$WANT" ]] && exit 0
printf '%s\n' "$WANT" > "$F.tmp"; chmod 644 "$F.tmp"; chown root:root "$F.tmp"; mv -f "$F.tmp" "$F"
echo "pma-filter-patch: phpMyAdmin jail counts real failed logins only"
if systemctl is-active --quiet fail2ban && fail2ban-client status phpmyadmin >/dev/null 2>&1; then
  # stderr: the stock "allowipv6 not defined" warning on every reload
  fail2ban-client reload phpmyadmin >/dev/null 2>&1 || { echo "pma-filter-patch: jail reload FAILED"; exit 1; }
fi
