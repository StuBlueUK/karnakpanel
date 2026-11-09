#!/usr/bin/env bash
# dns-save-zone.sh <domain> @stdin — validate the zone text, install, reload.
# Fails loudly on bad zones. The zone arrives on stdin (ServiceManager +
# Secret, secret-args.sh) — never staged in the shared /tmp, where any local
# account could pre-plant or race the staging file. Built in root's own
# private temp dir, checked there, then installed.
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/secret-args.sh"; secret_argv "$@"; set -- "${SECRET_ARGV[@]}"
D="${1:?}"; Z="${2:-}"
[[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad domain: $D"; exit 1; }
[[ -n "$Z" ]] || { echo "no zone text for $D (panel passes it on stdin)"; exit 1; }
(( ${#Z} <= 1048576 )) || { echo "zone for $D too large"; exit 1; }
DST="/etc/bind/zones/db.$D"
W="$(mktemp -d)"; trap 'rm -rf "$W"' EXIT
printf '%s' "$Z" > "$W/zone"; chmod 644 "$W/zone"
named-checkzone "$D" "$W/zone"
cp "$W/zone" "$DST"
rndc reload "$D" 2>/dev/null || rndc reconfig 2>/dev/null || systemctl reload bind9
echo "saved & reloaded $D"
