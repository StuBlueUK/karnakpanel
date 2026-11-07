#!/usr/bin/env bash
# dnssec-disable.sh <domain> — stop signing (stanza back to plain master).
# Keys are KEPT so re-enabling reuses them and the registrar DS stays valid.
set -euo pipefail
D="${1:?}"
[[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad domain: $D"; exit 1; }
ESC="${D//./\\.}"
perl -0pi -e "s/zone \"$ESC\" \\{\\n  type master;\\n  file \"\\/etc\\/bind\\/zones\\/db.$ESC\";\\n  key-directory \"[^\\\"]*\";\\n  dnssec-policy default;\\n  inline-signing yes;\\n\\};/zone \"$D\" { type master; file \"\\/etc\\/bind\\/zones\\/db.$ESC\"; };/" /etc/bind/zones.skypanel
named-checkconf 2>&1
rndc reconfig 2>&1
rndc reload "$D" 2>&1 || true
echo "signing disabled for $D (keys kept)"
