#!/usr/bin/env bash
# dns-glue.sh <domain> — mirror in-bailiwick NS glue (bare ns1/ns2 A lines)
# from the zone file into dns_records, so panel re-renders
# (DnsController::apply, which builds zones from DB rows only) keep a valid
# zone. Idempotent: only adds rows that are missing. Without this, the first
# panel render of a template-built zone drops its NS addresses and the
# validation refuses (named-checkzone: NS has no address records).
set -euo pipefail
D="${1:?}"
[[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad domain"; exit 1; }
DID="$(mysql -uroot -N skypanel -e "SELECT id FROM domains WHERE domain='$D';")"
[[ -n "$DID" && "$DID" != "NULL" ]] || { echo "REFUSED: no such domain"; exit 1; }
Z="/etc/bind/zones/db.$D"
[[ -f "$Z" ]] || { echo "REFUSED: no zone file"; exit 1; }
n=0
while read -r host ip; do
  [[ "$host" =~ ^ns[12]$ && "$ip" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] || continue
  has="$(mysql -uroot -N skypanel -e "SELECT COUNT(*) FROM dns_records WHERE domain_id=$DID AND host='$host' AND type='A';")"
  if [[ "$has" == "0" ]]; then
    mysql -uroot skypanel -e "INSERT INTO dns_records(domain_id,type,host,value,priority,ttl) VALUES($DID,'A','$host','$ip',10,3600);" && n=$((n+1)) || true
  fi
done < <(grep -oE "^ns[12] +IN +A +[0-9.]+" "$Z" 2>/dev/null | awk '{print $1, $NF}')
echo "glue ensured for $D ($n row(s) added)"
