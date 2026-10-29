#!/usr/bin/env bash
# mail-routing.sh <domain> <local|remote> — flip one domain's mail handling.
# Remote = listed in /etc/exim4/virtual/remote, so the remote_route router
# accepts the domain and delivers by public MX before any local router runs
# (mailboxes/forwards/lists/autoreplies go dormant, DB rows untouched).
# Local = line removed, everything live again. lsearch maps are read per
# lookup: no Exim reload needed. IDEMPOTENT, fails loudly.
set -euo pipefail
D="${1:?}"; MODE="${2:?}"
[[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad domain"; exit 1; }
[[ "$MODE" == local || "$MODE" == remote ]] || { echo "mode must be local|remote"; exit 1; }
MAP=/etc/exim4/virtual/remote
mkdir -p /etc/exim4/virtual
touch "$MAP"
ESC="${D//./\\.}"
sed -i "/^$ESC: /d" "$MAP" 2>/dev/null || true
if [[ "$MODE" == remote ]]; then
  echo "$D: remote" >> "$MAP"
  echo "mail for $D now routes by public MX (local delivery dormant)"
else
  echo "mail for $D now delivers locally"
fi
