#!/usr/bin/env bash
# dkim-status.sh <domain> [selector] — "signed yes|no" + TXT record (panel UI).
set -euo pipefail
D="${1:?}"; S="${2:-default}"
[[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ && "$S" =~ ^[a-z0-9_-]{1,32}$ ]] || { echo "bad domain or selector"; exit 1; }
KEY="/etc/exim4/dkim/$D.$S.pem"
if [[ -f "$KEY" ]]; then
  echo "signed yes"
  PUB="$(openssl rsa -in "$KEY" -pubout -outform DER 2>/dev/null | openssl base64 -A)"
  echo "$S._domainkey.$D. IN TXT \"v=DKIM1; k=rsa; p=$PUB\""
else
  echo "signed no"
fi
