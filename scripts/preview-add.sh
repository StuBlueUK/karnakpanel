#!/usr/bin/env bash
# preview-add.sh <domain> <token> — serve preview-<token>.<domain> from the
# domain's vhost (vhAliases, exact host). DNS wildcard (*.preview) is managed
# panel-side (DB row + zone re-render); this script only touches OLS.
# Idempotent. HTTP only (no cert for random hosts); robots headers unset.
set -euo pipefail
D="${1:?}"; T="${2:?}"
[[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad domain: $D"; exit 1; }
[[ "$T" =~ ^[a-z0-9]{16,64}$ ]] || { echo "bad token"; exit 1; }
H="preview-$T.$D"
VH="/usr/local/lsws/conf/vhosts/$D/vhost.conf"
[[ -f "$VH" ]] || { echo "no vhost $D"; exit 1; }
# vhAliases is COMMA-separated (docs); the host must ALSO be mapped at the
# listener as `map <vhost> <host>` (vhost FIRST — symmetric `map D D` lines
# work either way, which is why bare domains always worked and aliases didn't).
if grep -q "^vhAliases" "$VH"; then
  grep -qw "$H" "$VH" || sed -i "s/^vhAliases\(.*\)/vhAliases\1,$H/" "$VH"
else
  sed -i "s/^vhDomain\(.*\)/vhDomain\1\nvhAliases                 $H/" "$VH"
fi
HTTPD="/usr/local/lsws/conf/httpd_config.conf"
grep -qF "map $D $H" "$HTTPD" 2>/dev/null || sed -i "/^listener Default/a\\  map $D $H" "$HTTPD"
/usr/local/lsws/bin/lswsctrl restart 2>&1 | tail -1
echo "preview on: http://$H/"
