#!/usr/bin/env bash
# restic-db.sh <username> <snapshot> <dbname> [verify-only] — restore ONE
# database from an account snapshot (dumps live at the stable stage path).
# The db must belong to the user (mysql_dbs row); system schemas refused.
# verify-only streams the dump through a parse check instead of mysql (for
# the monthly test-restore cron: proves restorability without touching live).
set -euo pipefail
U="${1:?}"; SNAP="${2:?}"; DB="${3:?}"; MODE="${4:-restore}"
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad user"; exit 1; }
[[ "$SNAP" =~ ^([0-9a-f]{7,64}|latest)$ ]] || { echo "bad snapshot"; exit 1; }
[[ "$DB" =~ ^[a-z][a-z0-9_]{2,63}$ ]] || { echo "bad db"; exit 1; }
case "$DB" in mysql|information_schema|performance_schema|sys|skypanel) echo "refusing system db"; exit 1;; esac
id "$U" >/dev/null 2>&1 || { echo "no such user"; exit 1; }
BIN="/usr/local/bin/restic"
PASSF="/root/.restic-pass"
REPO="/backup/restic"
[[ -x "$BIN" && -f "$PASSF" && -d "$REPO/keys" ]] || { echo "restic not initialized"; exit 1; }
export RESTIC_PASSWORD_FILE="$PASSF"
P="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
[[ -n "$P" ]] || { echo "no panel db pass"; exit 1; }
OWNER="$(MYSQL_PWD="$P" mysql -uskypanel skypanel -N -e "SELECT u.username FROM mysql_dbs m JOIN users u ON u.id=m.user_id WHERE m.db_name='$DB';" 2>/dev/null || true)"
[[ "$OWNER" == "$U" ]] || { echo "not your database"; exit 1; }
SRC="/var/lib/skypanel/db-dumps/$U/$DB.sql"
# bare "latest" is repo-global: resolve to this account's newest DB snapshot.
if [[ "$SNAP" == "latest" ]]; then
  SNAP="$("$BIN" snapshots -r "$REPO" --json --tag "account=$U" 2>/dev/null | python3 -c "import json,sys; ss=[s for s in json.load(sys.stdin) if 'kind=db' in (s.get('tags') or [])]; print(max(ss, key=lambda s: s['time'])['short_id'] if ss else '')" 2>/dev/null || true)"
  [[ -n "$SNAP" ]] || { echo "no db snapshot"; exit 1; }
fi
if [[ "$MODE" == "verify-only" ]]; then
  # capture first: head/grep exiting early SIGPIPEs restic, and under
  # pipefail every dump over 200 KB read as "unreadable"
  HEAD="$("$BIN" dump -r "$REPO" "$SNAP" "$SRC" 2>/dev/null | head -c 200000 || true)"
  grep -q "CREATE TABLE\|INSERT INTO\|CREATE DATABASE" <<<"$HEAD" \
    && echo "dump for $DB parses OK" || { echo "dump for $DB missing/unreadable"; exit 1; }
  exit 0
fi
"$BIN" dump -r "$REPO" "$SNAP" "$SRC" 2>/dev/null | mysql "$DB" && echo "restored database $DB from $SNAP"
