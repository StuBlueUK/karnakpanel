#!/usr/bin/env bash
# dns-save-zone.sh <domain> — validate staged zone, install, reload. Fails loudly on bad zones.
set -euo pipefail
D="${1:?}"
[[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad domain: $D"; exit 1; }
SRC="/tmp/skypanel-$D.zone"; DST="/etc/bind/zones/db.$D"
[[ -f "$SRC" ]] || { echo "no staged zone $SRC (panel must write it first)"; exit 1; }
# staged file goes on EVERY exit: a refused zone left behind blocked the
# panel's next save (O_EXCL staging) for 10 minutes; and it's shared /tmp
trap 'rm -f "$SRC"' EXIT
named-checkzone "$D" "$SRC"
cp "$SRC" "$DST"
rndc reload "$D" 2>/dev/null || rndc reconfig 2>/dev/null || systemctl reload bind9
echo "saved & reloaded $D"
