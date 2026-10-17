#!/usr/bin/env bash
# dns-add-zone.sh <domain> [ns1] [ns2] — default zone (reseller NS supported)
set -euo pipefail
D="${1:?}"; IP="$(hostname -I | awk '{print $1}')"
[[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad domain: $D"; exit 1; }
NS1="${2:-ns1.$D}"; NS2="${3:-ns2.$D}"
[[ "$NS1" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ && "$NS2" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad nameservers"; exit 1; }
Z="/etc/bind/zones/db.$D"
# glue A rows only for nameservers INSIDE this zone (the panel apex, or the
# no-panel-host fallback). Server/reseller NS live elsewhere — an ns1/ns2 A
# here would be a dead record that only looks like the NS setup.
GLUE=(); GLUE_LINES=""
for N in "$NS1" "$NS2"; do
  L="${N%.$D}"
  if [[ "$N" == *".$D" && "$L" =~ ^[a-z0-9-]+$ ]]; then GLUE+=("$L"); GLUE_LINES+="$L IN A $IP"$'\n'; fi
done
if [[ ! -f "$Z" ]]; then
cat > "$Z" <<EOF
\$TTL 3600
@ IN SOA $NS1. admin.$D. ( $(date +%Y%m%d)01 7200 3600 1209600 3600 )
@ IN NS $NS1.
@ IN NS $NS2.
${GLUE_LINES}@ IN A $IP
www IN A $IP
mail IN A $IP
@ IN MX 10 mail.$D.
@ IN TXT "v=spf1 a mx ~all"
EOF
fi
grep -q "zone \"$D\"" /etc/bind/zones.skypanel 2>/dev/null || cat >> /etc/bind/zones.skypanel <<EOF
zone "$D" { type master; file "/etc/bind/zones/db.$D"; };
EOF
# panel renders zones from dns_records — mirror the template rows there, or
# the DNS page shows an empty zone and the first edit re-renders WITHOUT NS
# glue (validation refuses). COUNT-guarded: safe to re-run, repairs old zones.
# NS rows are NOT stored: the panel writes the NS pair into every render.
DID="$(mysql -uroot -N skypanel -e "SELECT id FROM domains WHERE domain='$D' LIMIT 1;" 2>/dev/null || true)"
if [[ -n "${DID:-}" && "$DID" != "NULL" ]]; then
  addrow() { # $1=type $2=host $3=value [$4=prio]
    local c; c="$(mysql -uroot -N skypanel -e "SELECT COUNT(*) FROM dns_records WHERE domain_id=$DID AND type='$1' AND host='$2';" 2>/dev/null || echo 1)"
    [[ "$c" == "0" ]] && mysql -uroot skypanel -e "INSERT INTO dns_records(domain_id,type,host,value,priority,ttl) VALUES($DID,'$1','$2','$3',${4:-10},3600);" 2>/dev/null || true
  }
  for L in "${GLUE[@]}"; do addrow A "$L" "$IP"; done
  addrow A @ "$IP"
  addrow A www "$IP"
  addrow A mail "$IP"
  addrow MX @ "mail.$D." 10
  addrow TXT @ "v=spf1 a mx ~all"
fi
named-checkzone "$D" "$Z"
# reconfig picks up brand-new zones; reload refreshes existing ones
rndc reconfig 2>/dev/null || systemctl reload bind9
rndc reload "$D" 2>/dev/null || true
echo "zone $D @ $IP"
