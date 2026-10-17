#!/usr/bin/env bash
# db-restore.sh <dbname> <dumpfile> — restore ONE database from a full dump.
# Extracts the dump section between mysqldump's "Current Database" markers
# (plus the CREATE/USE preamble) into mysql. Refuses system schemas.
set -euo pipefail
DB="${1:?}"; F="${2:?}"
[[ "$DB" =~ ^[a-z][a-z0-9_]{2,63}$ ]] || { echo "bad db"; exit 1; }
[[ "$F" =~ ^db-[a-zA-Z0-9_.-]+\.sql\.gz$ && "$F" != *"/"* ]] || { echo "bad dump"; exit 1; }
case "$DB" in mysql|information_schema|performance_schema|sys|skypanel) echo "refusing system db"; exit 1;; esac
SRC="/backup/skypanel/$F"
[[ -f "$SRC" ]] || { echo "dump not found"; exit 1; }
S="$("$(dirname "$0")/backup-crypt.sh" stage "$SRC")"
STAGE_TMP=""
if [[ "$S" != "$SRC" ]]; then STAGE_TMP="$S"; SRC="$S"; fi
SEC="$(zcat "$SRC" 2>/dev/null | grep -c "Current Database: \`$DB\`" || true)"
[[ "$SEC" -gt 0 ]] || { echo "db $DB not in dump"; exit 1; }
TMP="$(mktemp)"
trap 'rm -f "$TMP" ${STAGE_TMP:-}' EXIT
# the section's closing lines restore @OLD_* session vars that mysqldump's
# header normally sets — without them TIME_ZONE (and friends) restore from
# NULL and the import dies. Mirror the header: save session values, then the
# section, whose own tail restores them.
{
  echo "SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT, @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS, @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION, @OLD_TIME_ZONE=@@TIME_ZONE, @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, @OLD_SQL_MODE=@@SQL_MODE, @OLD_SQL_NOTES=@@SQL_NOTES;"
  echo "SET FOREIGN_KEY_CHECKS=0;"
  zcat "$SRC" | awk -v db="$DB" '
    /Current Database: / { active = ($0 ~ "`" db "`"); }
    active { print }
  '
} > "$TMP"
mysql -uroot < "$TMP" && echo "restored database $DB from $F"
