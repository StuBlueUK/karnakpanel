#!/usr/bin/env bash
# transfer-dns.sh <item> — Account Transfer, TARGET side: after a cutover,
# where does the world send the account's domains right now? (Migrate page
# "DNS check": a button, then a refresh while it is open, never a page load.)
# Asks PUBLIC resolvers, never the box's own: the local BIND answers from our
# own zone and always "matches" (as dns-check.sh). Per name and resolver:
#   a <name> <resolver> <state> <ips> <ttl>
#     state: here (an address of this server) | old (the old server's IP)
#            | other | none (no A record) | unknown (no answer: never a pass)
#     ttl: the resolver's remaining cache time for that answer (seconds)
#   aaaa <name> <state> <ips>       IPv6 is never repointed: shown, so a
#                                   stale one can't hide (here | other)
#   ns <zone> <state> <nameservers> where the zone's nameservers live:
#                                   here | old | elsewhere | mixed | unknown
# plus "me <ips>", "old <ip>", "resolvers <list>" header lines.
set -euo pipefail
IT="${1:-}"
[[ "$IT" =~ ^[0-9]{1,9}$ ]] || { echo "bad item"; exit 1; }
HERE="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
. "$HERE/transfer-common.sh"
command -v dig >/dev/null 2>&1 || { echo "no dig (dnsutils missing)"; exit 1; }
PASS="$(cat /root/.skypanel-dbpass 2>/dev/null || true)"
[[ -n "$PASS" ]] || { echo "no panel db pass"; exit 1; }
sql() { MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -B -e "$1"; }

IFS=$'\t' read -r STG OLD UID_ <<<"$(sql "SELECT i.stage, j.source_ip, u.id FROM transfer_items i JOIN transfer_jobs j ON j.id=i.job_id
  JOIN users u ON u.id=i.new_user_id AND u.username=i.username WHERE i.id=$IT")" || true
[[ "${STG:-}" == live && "${UID_:-}" =~ ^[0-9]+$ ]] || { echo "refused: that account isn't live on this server"; exit 3; }
kt_ip_ok "${OLD:-}" || OLD=""

RESOLVERS=(1.1.1.1 8.8.8.8 9.9.9.9)
# netcup-style egress filters drop the big three: the box's own upstream as a 4th
while read -r r; do
  [[ "$r" =~ ^[0-9.]+$ && "$r" != 127.* ]] || continue
  [[ " ${RESOLVERS[*]} " == *" $r "* ]] || { RESOLVERS+=("$r"); break; }
done < <(resolvectl dns 2>/dev/null | sed 's/^[^:]*://' | tr ' ' '\n' | sed 's/%.*//')

ME="$(kt_own_ips | paste -sd, -)"
ME6="$(hostname -I 2>/dev/null | tr ' ' '\n' | grep ':' | paste -sd, - || true)"
echo "me ${ME:-unknown}"
echo "old ${OLD:-unknown}"
# nosemgrep: bash.lang.security.ifs-tampering.ifs-tampering -- IFS set inside a subshell to join with commas
echo "resolvers $(IFS=,; echo "${RESOLVERS[*]}")"

state() {  # <comma ips> → here|old|other (here wins only if EVERY address is ours)
  local ip h=0 o=0 x=0
  for ip in ${1//,/ }; do
    if [[ ",$ME,$ME6," == *",$ip,"* ]]; then h=1; elif [[ -n "$OLD" && "$ip" == "$OLD" ]]; then o=1; else x=1; fi
  done
  if (( o )); then echo old; elif (( x )); then echo other; else echo here; fi
}
# dg TYPE NAME RESOLVER → "<status> <ips,> <minttl>" (status "-" = no answer)
dg() {
  local out st
  out="$(timeout 5 dig +tries=1 +time=3 +noall +comments +answer "$1" "$2." "@$3" 2>/dev/null || true)"
  st="$(grep -oE 'status: [A-Z]+' <<<"$out" | head -1 | cut -d' ' -f2)"
  [[ -n "$st" ]] || { echo "- - -"; return; }
  awk -v t="$1" -v st="$st" '$1 !~ /^;/ && $4 == t { ips = ips (ips ? "," : "") tolower($5); if (ttl == "" || $2 + 0 < ttl) ttl = $2 + 0 }
    END { print st, (ips ? ips : "-"), (ttl == "" ? "-" : ttl) }' <<<"$out"
}
one() {  # <name>: every resolver's A answer, then AAAA from the first that answers
  local n="$1" r st ips ttl s a6=""
  for r in "${RESOLVERS[@]}"; do
    read -r st ips ttl <<<"$(dg A "$n" "$r")"
    if [[ "$st" == - || ( "$st" != NOERROR && "$st" != NXDOMAIN ) ]]; then s=unknown
    elif [[ "$ips" == - ]]; then s=none
    else s="$(state "$ips")"; fi
    printf 'a\t%s\t%s\t%s\t%s\t%s\n' "$n" "$r" "$s" "$ips" "$ttl"
    if [[ -z "$a6" && "$s" != unknown ]]; then
      read -r st ips ttl <<<"$(dg AAAA "$n" "$r")"
      a6=1; [[ "$ips" != - ]] && printf 'aaaa\t%s\t%s\t%s\n' "$n" "$([[ ",$ME6," == *",${ips%%,*},"* ]] && echo here || echo other)" "$ips"
    fi
  done
}
zone() {  # <zone>: its public nameservers and where their addresses are
  local z="$1" r ns="" n ips s all=""
  for r in "${RESOLVERS[@]}"; do
    ns="$(timeout 5 dig +short +tries=1 +time=3 NS "$z." "@$r" 2>/dev/null | grep -E '^[A-Za-z0-9.-]+\.$' | tr 'A-Z' 'a-z' | sed 's/\.$//' | sort -u | head -6 || true)"
    [[ -n "$ns" ]] && break
  done
  [[ -n "$ns" ]] || { printf 'ns\t%s\tunknown\t-\n' "$z"; return; }
  local h=0 o=0 e=0
  for n in $ns; do
    ips="$(timeout 5 dig +short +tries=1 +time=3 A "$n." "@$r" 2>/dev/null | grep -E '^[0-9.]+$' | paste -sd, - || true)"
    [[ -n "$ips" ]] || { e=1; continue; }
    s="$(state "$ips")"; case "$s" in here) h=1 ;; old) o=1 ;; *) e=1 ;; esac
  done
  if (( h + o + e > 1 )); then s=mixed; elif (( h )); then s=here; elif (( o )); then s=old; else s=elsewhere; fi
  printf 'ns\t%s\t%s\t%s\n' "$z" "$s" "$(paste -sd, - <<<"$ns")"
}

W="$(mktemp -d)"; trap 'rm -rf "$W"' EXIT
N=0
# the account's sites + aliases (max 20). A name under another of them (blog.
# of example.com) shares its zone: nameservers + mail. only for the top ones
mapfile -t ALL < <(sql "SELECT domain FROM domains WHERE user_id=$UID_ UNION SELECT alias FROM domain_aliases WHERE user_id=$UID_ ORDER BY 1" | tr 'A-Z' 'a-z' \
  | grep -E '^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$' | awk 'length($0) <= 200' || true)
for D in "${ALL[@]}"; do
  N=$((N+1)); (( N <= 20 )) || break
  NAMES=("$D" "www.$D") top=1
  for P in "${ALL[@]}"; do [[ "$D" == *".$P" ]] && { top=0; break; }; done
  if (( top )); then NAMES+=("mail.$D"); zone "$D" > "$W/$N.0" & fi
  k=0; for n in "${NAMES[@]}"; do k=$((k+1)); one "$n" > "$W/$N.$k" & done
done
wait
(( N )) || { echo "refused: the account has no domains here"; exit 3; }
(( N > 20 )) && echo "note only the first 20 domains are checked"
for f in $(ls "$W" | sort -t. -k1,1n -k2,2n); do cat "$W/$f"; done
