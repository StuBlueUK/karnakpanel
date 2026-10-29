#!/usr/bin/env bash
# backup-dumps.sh [db-name [since-epoch]] — list database dump files (for
# selective DB restore). No arg: every dump (admin view). With a db name: only
# dumps containing that database's section (per-user view — a tenant's
# dropdown must not enumerate other tenants' backup history). since-epoch
# (tenant view: the db row's creation) drops older dumps: dumps outlive a
# deleted account, and an older section of the same name may be another
# tenant's (re-used username, or bob+x_wp == bob_x+wp). The time comes from
# the file NAME (box-local, as written) — offsite copies/restores move mtime.
# Newest 100 files scanned (retention is unbounded; full scan would outgrow
# the sudo timeout).
set -euo pipefail
DB="${1:-}"; SINCE="${2:-}"
[[ -z "$SINCE" || "$SINCE" =~ ^[0-9]{1,12}$ ]] || { echo "bad since"; exit 1; }
if [[ -z "$DB" ]]; then
  ls -t /backup/skypanel/db-*.sql.gz 2>/dev/null | xargs -n1 basename 2>/dev/null || true
  exit 0
fi
[[ "$DB" =~ ^[a-z][a-z0-9_]{2,63}$ ]] || { echo "bad db"; exit 1; }
for f in $(ls -t /backup/skypanel/db-*.sql.gz 2>/dev/null | head -100); do
  if [[ -n "$SINCE" ]]; then
    [[ "$(basename "$f")" =~ ^db-([0-9]{8})-([0-9]{2})([0-9]{2})([0-9]{2})\.sql\.gz$ ]] || continue
    T="$(date -d "${BASH_REMATCH[1]} ${BASH_REMATCH[2]}:${BASH_REMATCH[3]}:${BASH_REMATCH[4]}" +%s 2>/dev/null)" || continue
    (( T >= SINCE )) || continue
  fi
  # grep reads a process substitution, not a pipe: an early match must not
  # SIGPIPE zcat into a pipefail "no match" (it missed nearly every dump)
  if grep -qF "Current Database: \`$DB\`" < <(zcat "$f" 2>/dev/null); then
    basename "$f"
  fi
done || true
