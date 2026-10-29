#!/usr/bin/env bash
# ssl-services-state.sh — report what the box listeners (:8090) serve: "self"
# (self-signed: issuer == subject) or "issued:<cn>". Lets the panel notice the
# forgotten step (domain SSL live, services still self-signed) and offer it.
set -euo pipefail
CERT="$(timeout 8 openssl s_client -connect 127.0.0.1:8090 </dev/null 2>/dev/null | openssl x509 2>/dev/null || true)"
[[ -n "$CERT" ]] || { echo "unknown"; exit 0; }
ISS="$(echo "$CERT" | openssl x509 -noout -issuer 2>/dev/null | sed 's/^issuer= *//')"
SUB="$(echo "$CERT" | openssl x509 -noout -subject 2>/dev/null | sed 's/^subject= *//')"
CN="$(echo "$CERT" | openssl x509 -noout -subject 2>/dev/null | grep -oE 'CN *= *[^,/,=]+' | head -1 | sed 's/CN *= *//')"
if [[ -n "$ISS" && "$ISS" == "$SUB" ]]; then echo "self"; else echo "issued:${CN:-?}"; fi
