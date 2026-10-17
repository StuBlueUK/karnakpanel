#!/usr/bin/env bash
# vhost-alias.sh <domain> <alias> — park an extra domain on an existing vhost.
# Web-only (no mailboxes for aliases): creates the BIND zone for the alias and
# adds it to the vhost's vhAliases. Idempotent.
set -euo pipefail
D="${1:?}"; A="${2:?}"
[[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad domain: $D"; exit 1; }
[[ "$A" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad alias: $A"; exit 1; }
[[ "$A" != "$D" ]] || { echo "alias equals domain"; exit 1; }
VH="/usr/local/lsws/conf/vhosts/$D/vhost.conf"
[[ -f "$VH" ]] || { echo "no vhost $D"; exit 1; }
"$(dirname "$0")/dns-add-zone.sh" "$A" >/dev/null
if grep -q "^vhAliases" "$VH"; then
  grep -qw "$A" "$VH" || sed -i "s/^vhAliases\(.*\)/vhAliases\1,$A/" "$VH"
else
  sed -i "s/^vhDomain\(.*\)/vhDomain\1\nvhAliases                 $A/" "$VH"
fi
# vhAliases is COMMA-separated and the host must ALSO be listener-mapped as
# `map <vhost> <host>` (vhost FIRST — symmetric `map D D` lines work either
# way, which is why bare domains always worked and aliases didn't).
HTTPD="/usr/local/lsws/conf/httpd_config.conf"
grep -qF "map $D $A" "$HTTPD" 2>/dev/null || sed -i "/^listener Default/a\\  map $D $A" "$HTTPD"
/usr/local/lsws/bin/lswsctrl restart 2>&1 | tail -1
echo "parked $A on $D"
