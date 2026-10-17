#!/usr/bin/env bash
# backup-dumps.sh [db-name] — list database dump files (for selective DB
# restore). No arg: every dump (admin view). With a db name: only dumps
# containing that database's section (per-user view — a tenant's dropdown
# must not enumerate other tenants' backup history). Newest 100 files scanned
# (retention is unbounded; full scan would outgrow the sudo timeout).
set -euo pipefail
DB="${1:-}"
if [[ -z "$DB" ]]; then
  ls -t /backup/skypanel/db-*.sql.gz 2>/dev/null | xargs -n1 basename 2>/dev/null || true
  exit 0
fi
[[ "$DB" =~ ^[a-z][a-z0-9_]{2,63}$ ]] || { echo "bad db"; exit 1; }
for f in $(ls -t /backup/skypanel/db-*.sql.gz 2>/dev/null | head -100); do
  # grep reads a process substitution, not a pipe: an early match must not
  # SIGPIPE zcat into a pipefail "no match" (it missed nearly every dump)
  if grep -qF "Current Database: \`$DB\`" < <(zcat "$f" 2>/dev/null); then
    basename "$f"
  fi
done || true
