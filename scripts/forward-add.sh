#!/usr/bin/env bash
# forward-add.sh <from> <targets> — register/refresh a forwarder line.
# <targets> is comma-separated; every target must be a plain address
# (no pipes/files — Exim would never run them, but refuse loudly anyway).
# The source itself may appear among OTHER targets ("keep a copy": Exim skips
# this redirect for that child and delivers it locally); a forwarder whose
# only target is itself (a loop) and a self-listing catch-all are refused.
set -euo pipefail
FROM="${1:?}"; TARGETS="${2:?}"
RX='^[a-z0-9._-]+@[a-z0-9.-]+\.[a-z]{2,}$'
RXC='^(\*|[a-z0-9._-]+)@[a-z0-9.-]+\.[a-z]{2,}$'
[[ "$FROM" =~ $RXC ]] || { echo "bad source: $FROM"; exit 1; }
[[ "$TARGETS" != *"|"* && "$TARGETS" != *"/"* && "$TARGETS" != *":"* ]] || { echo "bad targets: pipes/paths refused"; exit 1; }
CLEAN=""; OTHERS=0
IFS=',' read -ra TS <<< "$TARGETS"
for t in "${TS[@]}"; do
  t="$(echo "$t" | tr -d '[:space:]')"
  [[ "$t" =~ $RX ]] || { echo "bad target: $t"; exit 1; }
  if [[ "$t" == "$FROM" ]]; then
    [[ "$FROM" != \** ]] || { echo "a catch-all cannot keep a copy: $t"; exit 1; }
  else OTHERS=$((OTHERS+1)); fi
  CLEAN="${CLEAN:+$CLEAN, }$t"
done
[[ -n "$CLEAN" ]] || { echo "no targets"; exit 1; }
[[ "$OTHERS" -gt 0 ]] || { echo "self-forward refused (loop): $FROM"; exit 1; }
mkdir -p /etc/exim4/virtual; touch /etc/exim4/virtual/forwarders
ESC="${FROM//./\\.}"; ESC="${ESC//\*/\\*}"
sed -i "/^$ESC: /d" /etc/exim4/virtual/forwarders 2>/dev/null || true
echo "$FROM: $CLEAN" >> /etc/exim4/virtual/forwarders
echo "forwarder $FROM -> $CLEAN"
