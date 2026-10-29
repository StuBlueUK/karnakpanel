#!/usr/bin/env bash
# dnssec-enable.sh <domain> — sign a zone with inline-signing under dnssec-policy
# default (BIND 9.18+: auto-dnssec is deprecated). BIND fully owns key lifecycle
# (CSK, automatic rollovers) — the panel only triggers and reports the DS.
# Key dir MUST be /var/cache/bind/keys: AppArmor denies named writes under /etc.
# Idempotent. Prints the DS record(s) for the registrar on success.
set -euo pipefail
D="${1:?}"
[[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad domain: $D"; exit 1; }
[[ -f "/etc/bind/zones/db.$D" ]] || { echo "no zone file for $D (create the zone first)"; exit 1; }
KD="/var/cache/bind/keys"
mkdir -p "$KD"; chown bind:bind "$KD" 2>/dev/null || chown named:named "$KD" 2>/dev/null || true
ESC="${D//./\\.}"
if ! grep -A6 "zone \"$ESC\"" /etc/bind/zones.skypanel 2>/dev/null | grep -q 'key-directory "/var/cache/bind/keys"'; then
  perl -0pi -e "s/zone \"$ESC\" \\{[^}]*\\};/zone \"$D\" {\\n  type master;\\n  file \"\\/etc\\/bind\\/zones\\/db.$ESC\";\\n  key-directory \"\\/var\\/cache\\/bind\\/keys\";\\n  dnssec-policy default;\\n  inline-signing yes;\\n};/" /etc/bind/zones.skypanel
fi
named-checkconf 2>&1
rndc reconfig 2>&1
# wait for policy to publish the KSK (up to ~40s), then report DS
BASE=""
for i in $(seq 1 20); do
  BASE=$(grep -l "DNSKEY 257 " "$KD"/K${D}.+*.key 2>/dev/null | xargs -n1 basename 2>/dev/null | head -1 || true)
  [[ -n "$BASE" ]] && break
  sleep 2
done
[[ -n "$BASE" ]] || { echo "KSK not published yet — check 'rndc dnssec -status $D' and retry"; exit 1; }
rndc reload "$D" 2>&1 || true
echo "--- DS (give these to the registrar; re-check after any KSK rollover) ---"
( cd "$KD" && dnssec-dsfromkey -2 "$BASE" )
