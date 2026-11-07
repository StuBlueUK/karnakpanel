#!/usr/bin/env bash
# spf-default.sh — print the panel's default SPF TXT for new zones/domains:
#   v=spf1 a mx ip6:<addr>... ~all   when this box has a global IPv6 address
#   v=spf1 a mx ~all                 otherwise
# Why: Exim delivers over IPv6 whenever the receiving MX has an AAAA (Gmail,
# Outlook do), but zones get no AAAA records by default, so "a mx" covered
# only the IPv4 address and every IPv6 delivery soft-failed SPF. The stable
# global addresses are listed (not temporary/deprecated privacy addresses,
# not ULA fc00::/7), at most 4. The kernel picks one of them as the source.
# Used by dns-add-zone.sh, panel-domain.sh, the panel (MailDns::spfDefault)
# and mail-dns-converge (moves rows still holding a panel default shape).
set -euo pipefail
V6=""
while read -r A; do
  [[ "$A" =~ ^[0-9a-f:]+$ ]] || continue
  [[ "$A" =~ ^f[cd] || "$A" =~ ^fe80 ]] && continue
  [[ " $V6 " == *" ip6:$A "* ]] && continue
  V6+=" ip6:$A"
done < <(ip -6 -o addr show scope global -temporary -deprecated 2>/dev/null | awk '{split($4,a,"/"); print tolower(a[1])}' | head -4)
echo "v=spf1 a mx${V6} ~all"
