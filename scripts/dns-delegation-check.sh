#!/usr/bin/env bash
# dns-delegation-check.sh [domain] — is each hosted zone really served from
# here? Caches the verdict in domains.ns_external / ns_public / ns_checked_at
# for the DNS, Email and Domains pages (never live DNS on page loads).
# Nightly via cron for every zone with a file here; with a domain, just that
# one (the panel calls it on Sign and on the DNS page's Check delegation).
#
# Asks PUBLIC resolvers, never the box's own: /etc/resolv.conf can point at
# the local BIND, which answers from our own zone file and would always say
# "delegated here" (seen live on prod). 1.1.1.1, 8.8.8.8, 9.9.9.9, then the
# upstream resolvers resolvectl reports (netcup-style egress filters).
#
# external = public NS answered, none of them is in the zone's own NS set
# (what BIND serves from the zone file, incl. a dns-secondary provider) and
# none resolves to this server. No answer = keep the old verdict (fails safe).
# Subdomains have no zone of their own: their pages read the parent's row.
set -uo pipefail
command -v dig >/dev/null 2>&1 || { echo "no dig (dnsutils missing)"; exit 1; }
PASS="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
[[ -n "$PASS" ]] || { echo "no panel db pass (/root/.skypanel-dbpass)"; exit 1; }
sql() { MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "$1"; }
ONE="${1:-}"
[[ -z "$ONE" || "$ONE" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad name: $ONE"; exit 1; }

RESOLVERS=(1.1.1.1 8.8.8.8 9.9.9.9)
while read -r r; do
  [[ "$r" =~ ^[0-9a-f.:]+$ && "$r" != 127.* && "$r" != ::1 ]] && RESOLVERS+=("$r")
done < <(resolvectl dns 2>/dev/null | sed 's/^[^:]*://' | tr ' ' '\n' | sed 's/%.*//')
# pq TYPE NAME — first public answer, lower-case, one per line ('' = none)
pq() {
  local r out
  for r in "${RESOLVERS[@]}"; do
    out="$(timeout 6 dig +short +time=3 +tries=1 "$1" "$2." "@$r" 2>/dev/null | grep -v '^;' | tr 'A-Z' 'a-z' | sed 's/\.$//' | sort -u || true)"
    [[ -n "$out" ]] && { printf '%s\n' "$out"; return 0; }
  done
  return 1
}
MINE=" $(hostname -I 2>/dev/null) "

check_one() {
  local D="$1" pub own n ip ext=1
  pub="$(pq NS "$D" | grep -E '^[a-z0-9.-]+$')" || pub=""
  if [[ -z "$pub" ]]; then
    sql "UPDATE domains SET ns_checked_at=NOW() WHERE domain='$D'"
    echo "$D: unknown (no public answer, verdict kept)"; return 1
  fi
  own="$(dig +short NS "$D." @127.0.0.1 2>/dev/null | tr 'A-Z' 'a-z' | sed 's/\.$//')"
  for n in $pub; do
    grep -qxF "$n" <<<"$own" && { ext=0; break; }
    for ip in $(pq A "$n"; pq AAAA "$n"); do
      [[ "$MINE" == *" $ip "* ]] && { ext=0; break 2; }
    done
  done
  local list; list="$(paste -sd, - <<<"$pub" | cut -c1-255)"
  sql "UPDATE domains SET ns_external=$ext, ns_public='$list', ns_checked_at=NOW() WHERE domain='$D'"
  if (( ext )); then echo "$D: external ($list)"; else echo "$D: here"; fi
}

rc=0
if [[ -n "$ONE" ]]; then
  [[ -f "/etc/bind/zones/db.$ONE" ]] || { echo "$ONE: no zone here"; exit 0; }
  check_one "$ONE" || rc=1
else
  while read -r D; do
    [[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || continue
    # no zone of its own (a subdomain): nothing to ask, but stamped so
    # host-converge's first-look step doesn't fire on every update
    if [[ ! -f "/etc/bind/zones/db.$D" ]]; then sql "UPDATE domains SET ns_checked_at=NOW() WHERE domain='$D' AND ns_checked_at IS NULL"; continue; fi
    check_one "$D" || rc=1
  done < <(sql "SELECT domain FROM domains ORDER BY id")
fi
exit $rc
