#!/usr/bin/env bash
# mail-usage.sh <address> — print mailbox usage in MB (one number, for the panel).
# mail-usage.sh --batch <addr>... — one "addr<TAB>MB" line per readable
# mailbox (unreadable ones omitted). Each lookup is bounded at 3s; the batch
# stops at the first hung lookup or after a 10s budget, so a stuck Dovecot can't hold the Email page.
# Reads Dovecot's quota accounting (same numbers IMAP REPORTs), not du.
set -euo pipefail
BATCH=0
[[ "${1:-}" == "--batch" ]] && { BATCH=1; shift; }
(( $# >= 1 )) || { echo "usage: $0 [--batch] <address>..."; exit 1; }
(( BATCH || $# == 1 )) || { echo "several addresses need --batch"; exit 1; }
for A in "$@"; do
  [[ "$A" =~ ^[a-z0-9._-]+@[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad address"; exit 1; }
done
# doveadm exits nonzero for unknown mailboxes — don't let pipefail kill us before the friendly error
# kb sets KB; returns 124 when doveadm hung (a stuck Dovecot stops the batch)
kb(){ local o rc; o="$(timeout 3 doveadm quota get -u "$1" 2>/dev/null)" && rc=0 || rc=$?
  KB="$(awk '$1=="User" && $2=="quota" && $3=="STORAGE" {print $4}' <<<"$o")"; return $(( rc == 124 ? 124 : 0 )); }
if (( ! BATCH )); then
  kb "$1" || true
  [[ "$KB" =~ ^[0-9]+$ ]] || { echo "unknown mailbox"; exit 1; }
  awk "BEGIN {printf \"%.1f\", $KB/1024}"
  exit 0
fi
END=$((SECONDS + 10))
for A in "$@"; do
  (( SECONDS < END )) || break
  kb "$A" || break
  [[ "$KB" =~ ^[0-9]+$ ]] && awk -v a="$A" -v k="$KB" 'BEGIN {printf "%s\t%.1f\n", a, k/1024}'
done
exit 0
