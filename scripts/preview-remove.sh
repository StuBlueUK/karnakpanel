#!/usr/bin/env bash
# preview-remove.sh <domain> <token> — drop the preview alias from the vhost.
# DNS wildcard stays (shared, harmless); token row dies panel-side.
set -euo pipefail
D="${1:?}"; T="${2:?}"
[[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad domain: $D"; exit 1; }
[[ "$T" =~ ^[a-z0-9]{16,64}$ ]] || { echo "bad token"; exit 1; }
H="preview-$T.$D"
VH="/usr/local/lsws/conf/vhosts/$D/vhost.conf"
[[ -f "$VH" ]] || { echo "no vhost $D (already gone?)"; exit 0; }
ESC="${H//./\\.}"
sed -i "s/,$ESC//g; s/$ESC,//g; s/ $ESC//g" "$VH"
# drop the vhAliases line if it ended up bare (vhost still valid either way)
sed -i "/^vhAliases\s*$/d" "$VH"
ED="${D//./\\.}"
sed -i "/^  map $ED $ESC\$/d" /usr/local/lsws/conf/httpd_config.conf
/usr/local/lsws/bin/lswsctrl restart 2>&1 | tail -1
echo "preview off: $H"
