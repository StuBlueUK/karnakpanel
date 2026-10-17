#!/usr/bin/env bash
# dnssec-status.sh <domain> — "signed yes|no" + DS lines (for the panel UI).
set -euo pipefail
D="${1:?}"
ESC="${D//./\\.}"
if grep -A5 "zone \"$ESC\"" /etc/bind/zones.skypanel 2>/dev/null | grep -q "inline-signing"; then
  echo "signed yes"
  # dsfromkey resolves siblings by CWD: run from the key dir with the basename
  ( cd /var/cache/bind/keys 2>/dev/null && KSK=$(grep -l "DNSKEY 257 " K${D}.+*.key 2>/dev/null | head -1 || true) && [[ -n "$KSK" ]] && dnssec-dsfromkey -2 "$KSK" 2>/dev/null ) || true
else
  echo "signed no"
fi
