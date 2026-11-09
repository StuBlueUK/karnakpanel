#!/usr/bin/env bash
# forward-remove.sh <from> — drop a forwarder line. Idempotent.
set -euo pipefail
FROM="${1:?}"
[[ "$FROM" =~ ^(\*|[a-z0-9._-]+)@[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad source: $FROM"; exit 1; }
ESC="${FROM//./\\.}"; ESC="${ESC//\*/\\*}"
sed -i "/^$ESC: /d" /etc/exim4/virtual/forwarders 2>/dev/null || true
echo "forwarder $FROM removed"
