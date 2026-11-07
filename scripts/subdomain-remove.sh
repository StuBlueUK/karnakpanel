#!/usr/bin/env bash
# subdomain-remove.sh <fqdn> <owner> — tear down a subdomain's site side.
# REFUSES while the docroot holds anything beyond the stock placeholder, so a
# click can never wipe a live site. Empty/placeholder-only -> vhost-remove.sh.
set -euo pipefail
D="${1:?}"; U="${2:?}"
[[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad domain"; exit 1; }
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad username"; exit 1; }
DOC="/home/$U/$D/public_html"
if [[ -d "$DOC" ]]; then
  # system-created entries (ACME challenge dir, mail autodiscover) don't count
  LEFT="$(find "$DOC" -mindepth 1 -maxdepth 1 ! -name index.html ! -name .well-known ! -name autodiscover -print -quit 2>/dev/null || true)"
  if [[ -n "$LEFT" ]]; then
    echo "REFUSED: $DOC has content — clear the files first (File Manager), then delete"
    exit 1
  fi
fi
"$(dirname "$0")/vhost-remove.sh" "$D" "$U"
echo "subdomain $D removed"
