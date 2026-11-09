#!/usr/bin/env bash
# mail-name.sh — print Exim's primary_hostname (this box's mail identity).
# Used so system-mail forwarding (root@) targets the right domain.
set -euo pipefail
HN="$(grep -m1 '^primary_hostname' /etc/exim4/exim4.conf 2>/dev/null | awk -F'[ =]+' '{print $2}' || true)"
[[ "$HN" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || HN="$(hostname -f 2>/dev/null || hostname)"
echo "$HN"
