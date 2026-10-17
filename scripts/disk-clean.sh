#!/usr/bin/env bash
# disk-clean.sh <staging|sessions|releases> — safe purges only.
# staging: orphaned upload fragments (>1 day). sessions: dead PHP sessions
# (>7 days; live ones are touched constantly). releases: keep newest TWO
# version dirs (current + rollback target) — never fewer.
set -euo pipefail
A="${1:?}"
case "$A" in
  staging)
    ST="/usr/local/skypanel/panel/storage/tmp-uploads"
    N=$(find "$ST" -type f -mtime +1 2>/dev/null | wc -l)
    find "$ST" -type f -mtime +1 -delete 2>/dev/null || true
    echo "staging: removed $N orphaned file(s)"
    ;;
  sessions)
    N=$(find /var/lib/php/sessions -name 'sess_*' -type f -mtime +7 2>/dev/null | wc -l)
    find /var/lib/php/sessions -name 'sess_*' -type f -mtime +7 -delete 2>/dev/null || true
    echo "sessions: removed $N dead file(s)"
    ;;
  releases)
    KEEP=$(ls -dt /usr/local/skypanel/releases/*/ 2>/dev/null | head -2)
    LIVE="$(readlink -f /usr/local/skypanel/panel 2>/dev/null | xargs dirname 2>/dev/null)/"
    N=0
    for d in /usr/local/skypanel/releases/*/; do
      [[ -d "$d" ]] || continue
      # never the live release, never the two newest (rollback targets)
      [[ "$d" != "$LIVE" ]] || continue
      echo "$KEEP" | grep -qxF "$d" || { rm -rf "$d"; N=$((N+1)); }
    done
    echo "releases: pruned $N old version(s), live + newest kept"
    ;;
  *) echo "usage: staging|sessions|releases"; exit 1 ;;
esac
