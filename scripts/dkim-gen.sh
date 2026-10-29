#!/usr/bin/env bash
# dkim-gen.sh <domain> [selector] — create a 2048-bit DKIM key for outbound signing.
# Private key stays root/Debian-exim-only; prints the DNS TXT to publish.
# Idempotent: existing keys are reused (rotating breaks published signatures).
set -euo pipefail
D="${1:?}"; S="${2:-default}"
[[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad domain"; exit 1; }
[[ "$S" =~ ^[a-z0-9_-]{1,32}$ ]] || { echo "bad selector"; exit 1; }
mkdir -p /etc/exim4/dkim
KEY="/etc/exim4/dkim/$D.$S.pem"
if [[ ! -f "$KEY" ]]; then
  openssl genrsa -out "$KEY" 2048 2>/dev/null
  echo "key generated for $D"
else
  echo "reusing existing key for $D"
fi
chown root:Debian-exim "$KEY"; chmod 640 "$KEY"
# signing map for the Exim transport (lookup indirection defeats taint checks)
mkdir -p /etc/exim4/virtual; touch /etc/exim4/virtual/dkim
grep -qxF "$D: $KEY" /etc/exim4/virtual/dkim 2>/dev/null || echo "$D: $KEY" >> /etc/exim4/virtual/dkim
PUB="$(openssl rsa -in "$KEY" -pubout -outform DER 2>/dev/null | openssl base64 -A)"
echo "--- publish this TXT at $S._domainkey.$D ---"
echo "$S._domainkey.$D. IN TXT \"v=DKIM1; k=rsa; p=$PUB\""
