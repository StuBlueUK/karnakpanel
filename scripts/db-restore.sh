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
trap 'rm -f ${STAGE_TMP:-}' EXIT   # every refusal below drops the decrypted stage too
SEC="$(zcat "$SRC" 2>/dev/null | grep -c "^-- Current Database: \`$DB\`\$" || true)"
[[ "$SEC" -gt 0 ]] || { echo "db $DB not in dump"; exit 1; }
# The section runs as MariaDB root, so its START must be mysqldump's own
# marker. Trigger bodies are dumped verbatim: a tenant can plant a line that
# looks exactly like "-- Current Database: `<db>`" (followed by SQL of their
# choosing) inside their own database's trigger. Three gates, together:
#  1. exactly one marker for this db: a forged copy of a db that was in the
#     dump makes two, refused;
#  2. the dump is newer than the db row: a db created after the dump has no
#     genuine section, so a lone marker for it can only be forged;
#  3. the dump is complete (mysqldump's trailer): a truncated dump could have
#     lost the genuine section after the forged one.
[[ "$SEC" -eq 1 ]] || { echo "refusing: dump has $SEC sections named $DB (one is forged)"; exit 1; }
[[ "$F" =~ ^db-([0-9]{8})-([0-9]{2})([0-9]{2})([0-9]{2})\.sql\.gz$ ]] || { echo "refusing: dump name has no timestamp"; exit 1; }
DT="$(date -d "${BASH_REMATCH[1]} ${BASH_REMATCH[2]}:${BASH_REMATCH[3]}:${BASH_REMATCH[4]}" +%s 2>/dev/null)" || { echo "refusing: bad dump timestamp"; exit 1; }
PP="$(cat /root/.skypanel-dbpass 2>/dev/null || true)"
[[ -n "$PP" ]] || { echo "no panel db pass"; exit 1; }
CT="$(MYSQL_PWD="$PP" mysql -uskypanel skypanel -N -e "SELECT UNIX_TIMESTAMP(created_at) FROM mysql_dbs WHERE db_name='$DB';" 2>/dev/null || true)"
[[ "$CT" =~ ^[0-9]+$ ]] || { echo "refusing: $DB is not a panel database"; exit 1; }
(( DT > CT )) || { echo "refusing: dump is not newer than database $DB (made before or as it was created)"; exit 1; }   # TS is taken before mysqldump lists dbs
zcat "$SRC" 2>/dev/null | tail -n 1 | grep -q '^-- Dump completed' || { echo "refusing: dump is incomplete"; exit 1; }
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
    # the mysqldump marker line only: text inside a row never switches sections
    /^-- Current Database: `[^`]+`$/ { active = ($0 == "-- Current Database: `" db "`"); }
    active { print }
  '
} > "$TMP"
mysql -uroot < "$TMP" && echo "restored database $DB from $F"
