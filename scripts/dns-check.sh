#!/usr/bin/env bash
# dns-check.sh <domain> <ns1> <ns2> — on-demand delegation check for the DNS
# page (a button, never a page load). Public answers come from PUBLIC
# resolvers, never the box's own: resolv.conf can point at the local BIND,
# which answers from our own zone file and always "matches" (seen on prod).
# 1.1.1.1, 8.8.8.8, 9.9.9.9, then resolvectl's upstreams (netcup-style egress
# filters). Prints key/value lines; "unknown" = no resolver answered, which
# is reported as such, never as a pass. The panel then refreshes the cached
# verdict with dns-delegation-check.sh.
set -euo pipefail
D="${1:?}"; N1="${2:?}"; N2="${3:?}"
for H in "$D" "$N1" "$N2"; do
  [[ "$H" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad name: $H"; exit 1; }
done
command -v dig >/dev/null 2>&1 || { echo "no dig (dnsutils missing)"; exit 1; }
RESOLVERS=(1.1.1.1 8.8.8.8 9.9.9.9)
while read -r r; do
  [[ "$r" =~ ^[0-9a-f.:]+$ && "$r" != 127.* && "$r" != ::1 ]] && RESOLVERS+=("$r")
done < <(resolvectl dns 2>/dev/null | sed 's/^[^:]*://' | tr ' ' '\n' | sed 's/%.*//')
dq() { timeout 6 dig +short +time=3 +tries=1 "$@" 2>/dev/null | grep -v '^;' | tr 'A-Z' 'a-z' | sed 's/\.$//' | sort -u | paste -sd, - || true; }
# q TYPE NAME — first public answer; ql — the local BIND
q() { local r out; for r in "${RESOLVERS[@]}"; do out="$(dq "$@" "@$r")"; [[ -n "$out" ]] && { echo "$out"; return; }; done; }
ql() { dq "$@" @127.0.0.1; }
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
echo "local_a $(ql A "$D." | cut -d, -f1)"
echo "public_mx $(q MX "$D.")"
