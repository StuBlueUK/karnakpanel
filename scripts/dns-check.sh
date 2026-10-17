#!/usr/bin/env bash
# dns-check.sh <domain> <ns1> <ns2> — on-demand delegation check for the DNS
# page (a button, never a page load). Resolves through the box's normal
# resolver like dns-glue-check.sh (some providers filter outbound 53 to
# arbitrary servers). Prints key/value lines; "unknown" = resolver gave
# nothing, which is reported as such, never as a pass.
set -euo pipefail
D="${1:?}"; N1="${2:?}"; N2="${3:?}"
for H in "$D" "$N1" "$N2"; do
  [[ "$H" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad name: $H"; exit 1; }
done
command -v dig >/dev/null 2>&1 || { echo "no dig (dnsutils missing)"; exit 1; }
q() { timeout 6 dig +short +time=3 +tries=1 "$@" 2>/dev/null | tr 'A-Z' 'a-z' | sed 's/\.$//' | sort -u | paste -sd, - || true; }
IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
PUB_NS="$(q NS "$D.")"
echo "server_ip ${IP:-unknown}"
echo "public_ns ${PUB_NS:-unknown}"
WANT="$(printf '%s\n%s\n' "$N1" "$N2" | sort -u | paste -sd, -)"
if [[ -z "$PUB_NS" ]]; then echo "ns_match unknown"
elif [[ "$PUB_NS" == "$WANT" ]]; then echo "ns_match yes"
else echo "ns_match no"; fi
echo "ns1_a $(q A "$N1." | cut -d, -f1)"
echo "ns2_a $(q A "$N2." | cut -d, -f1)"
echo "public_a $(q A "$D." | cut -d, -f1)"
echo "local_a $(q A "$D." @127.0.0.1 | cut -d, -f1)"
echo "public_mx $(q MX "$D.")"
