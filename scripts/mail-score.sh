#!/usr/bin/env bash
# mail-score.sh <domain> — 0-100 deliverability score from fast local checks:
# SPF 25 / DKIM 25 / DMARC 20 / MX 15 / STARTTLS 15 (+ 0-pt MTA-STS/TLS-RPT
# info lines). Prints score=N plus one
# "<key>=<pts> <text>" line per check. Tight timeouts: must finish well inside
# the panel's 15s sudo window.
# DMARC walks up to the org domain (RFC 7489: subdomains inherit the apex
# policy unless it sets sp=), so compliant subdomains aren't scored 0.
set -uo pipefail
D="${1:?}"
[[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad domain: $D"; exit 1; }
Q() { dig +short +time=1 +tries=1 "$2" "$1" @127.0.0.1 2>/dev/null; }
IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
SCORE=0
add() { SCORE=$((SCORE + $2)); echo "$1=$2 $3"; }
SPF="$(Q TXT "$D" | grep -F 'v=spf1' | head -1 || true)"
if [[ -n "$SPF" ]]; then
  if [[ "$SPF" == *"-all"* ]]; then add spf 25 "SPF hard-fail (-all)";
  else add spf 20 "SPF present but soft (~all — switch to -all once all mail leaves this box)"; fi
else add spf 0 "no SPF TXT (add v=spf1)"; fi
DK="$(Q TXT "default._domainkey.$D" | tr -d '\n' || true)"
if [[ "$DK" == *"p="* && "$DK" != *"p=;"* && ${#DK} -gt 40 ]]; then add dkim 25 "DKIM key published"; else add dkim 0 "no DKIM key (Email page Sign button)"; fi
DM=""; DDR="$D"
while true; do
  DM="$(Q TXT "_dmarc.$DDR" | head -1 || true)"
  [[ -n "$DM" ]] && break
  # no record here — walk up (sub.example.com -> example.com), stopping at
  # two labels so we never query a public suffix itself.
  [[ "$DDR" == *.*.* ]] || break
  DDR="${DDR#*.}"
done
# effective policy for $D: the record's sp= tag governs subdomains, p=
# governs the name itself. Parse tags exactly (sp=quarantine must not read
# as p=quarantine for the parent).
_ptag(){ echo "$2" | grep -oE "(^|[; ])${1}=[a-z]+" | tail -1 | grep -oE "=[a-z]+$" | cut -c2- || true; }
PV="$(_ptag p "$DM")"; SRC=""
if [[ "$DDR" != "$D" && -n "$DM" ]]; then
  SP="$(_ptag sp "$DM")"
  [[ -n "$SP" ]] && PV="$SP"
  SRC=" (inherited from $DDR)"
fi
if [[ "$PV" == "reject" || "$PV" == "quarantine" ]]; then add dmarc 20 "DMARC enforcing$SRC";
elif [[ "$PV" == "none" ]]; then add dmarc 10 "DMARC p=none$SRC, needs quarantine/reject for full marks";
else add dmarc 0 "no DMARC record"; fi
MX="$(Q MX "$D" | sort -n | head -3 || true)"
if [[ -z "$MX" ]]; then
  add mx 0 "no MX records"
  MH=""
else
  MH="$(echo "$MX" | head -1 | awk '{print $2}' | sed 's/\.$//')"
  MIP="$(Q A "$MH" | head -1 || true)"
  if [[ -n "$MIP" && "$MIP" == "$IP" ]]; then add mx 15 "MX delivers here"; else add mx 8 "MX exists (points elsewhere)"; fi
fi
if [[ -n "${MH:-}" ]] && timeout 5 openssl s_client -connect "$MH:25" -starttls smtp </dev/null >/dev/null 2>&1; then
  add tls 15 "STARTTLS offered"
else
  add tls 0 "no STARTTLS on port 25"
fi
# informational (0 pts — keeps every existing score comparable): MTA-STS +
# TLS-RPT, published from the Email page
ST="$(Q TXT "_mta-sts.$D" | grep -F 'v=STSv1' | head -1 || true)"
if [[ -n "$ST" ]]; then echo "mtasts=0 MTA-STS policy published"; else echo "mtasts=0 no MTA-STS (optional: Email page MTA-STS)"; fi
TR="$(Q TXT "_smtp._tls.$D" | grep -F 'v=TLSRPTv1' | head -1 || true)"
if [[ -n "$TR" ]]; then echo "tlsrpt=0 TLS reporting on"; else echo "tlsrpt=0 no TLS-RPT record (optional)"; fi
echo "score=$SCORE"
