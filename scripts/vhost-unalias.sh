#!/usr/bin/env bash
# vhost-unalias.sh <domain> <alias> — detach a parked domain (vhost + its zone).
# Idempotent. The main domain and its files are untouched.
set -euo pipefail
D="${1:?}"; A="${2:?}"
[[ "$A" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad alias: $A"; exit 1; }
VH="/usr/local/lsws/conf/vhosts/$D/vhost.conf"
EA="${A//./\\\.}"; ED="${D//./\\.}"
if [[ -f "$VH" ]]; then
  sed -i "s/,$EA//g; s/$EA,//g; s/ $EA//g; s/^vhAliases\\s*$/vhAliases/" "$VH"
  # drop the line entirely if no aliases remain (keep config tidy)
  sed -i "/^vhAliases\s*$/d" "$VH"
fi
sed -i "/^  map $ED $EA\$/d" /usr/local/lsws/conf/httpd_config.conf
sed -i "/^  map $EA $ED\$/d" /usr/local/lsws/conf/httpd_config.conf
"$(dirname "$0")/dns-remove-zone.sh" "$A" >/dev/null 2>&1 || true
sed -i "/^$EA: /d" /etc/exim4/virtual/moved 2>/dev/null || true   # Account Transfer hold/relay
/usr/local/lsws/bin/lswsctrl restart 2>&1 | tail -1 || true
# DKIM-by-From owner map follows the DB (the row change precedes this script)
"$(dirname "$(readlink -f "$0")")/mail-owners-sync.sh" >/dev/null 2>&1 || echo "WARN: mail owner map not refreshed (cron retries within 5 min)" >&2
echo "unparked $A from $D"
