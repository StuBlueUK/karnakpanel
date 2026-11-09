#!/usr/bin/env bash
# app-destroy.sh <clone-id> — tear down a staging clone (runs as root).
# System teardown only: vhost, staging files, staging DB/user, snapshot
# files. Panel rows (domains, dns_records, mysql_dbs, staging_clones,
# staging_snapshots) are deleted by the CONTROLLER after exit 0, which also
# re-renders the parent zone. Synchronous: teardown is fast (no bulk copy).
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/as-user.sh"   # customer-tree writes run as the customer
CLID="${1:?}"
LOG="/var/log/karnakpanel/apptoolkit.log"
[[ "$CLID" =~ ^[0-9]{1,10}$ ]] || { echo "bad clone id"; exit 1; }
Q() { mysql -uroot -N skypanel -e "$1" 2>/dev/null; }
ROW="$(Q "SELECT CONCAT_WS('|', c.staging_domain_id, c.staging_db, c.staging_db_user, d.domain, u.username) FROM staging_clones c JOIN domains d ON d.id=c.staging_domain_id JOIN users u ON u.id=c.user_id WHERE c.id=$CLID;")"
[[ -n "$ROW" ]] || { echo "REFUSED: no clone $CLID"; exit 1; }
IFS='|' read -r SDID SDB SDBU SDOM UNAME <<< "$ROW"
[[ "$SDOM" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ && "$UNAME" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "REFUSED: bad clone row"; exit 1; }
[[ "$SDB" =~ ^[a-z][a-z0-9_]{2,63}$ && "$SDBU" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "REFUSED: bad staging db"; exit 1; }
# dropped below as root: refuse when the panel records either for another account
[[ -z "$(Q "SELECT 1 FROM mysql_dbs m JOIN staging_clones c ON c.id=$CLID WHERE m.user_id<>c.user_id AND (m.db_name='$SDB' OR m.db_user='$SDBU') LIMIT 1;")" ]] || { echo "REFUSED: staging db belongs to another account"; exit 1; }
SDOC="/home/$UNAME/$SDOM/public_html"
case "$SDOC" in /home/*/*/public_html) ;; *) echo "REFUSED: docroot escapes home"; exit 1;; esac
{
  echo "=== $(date -Is) app-destroy clone $CLID ($SDOM) ==="
  "$(dirname "$0")/vhost-remove.sh" "$SDOM" "$UNAME" 2>&1 || echo "WARN: vhost-remove had trouble (continuing)"
  as_user "$UNAME" rm -rf --one-file-system -- "$SDOC"; as_user "$UNAME" rmdir -- "/home/$UNAME/$SDOM" 2>/dev/null || true
  mysql -uroot -e "DROP DATABASE IF EXISTS \`$SDB\`; DROP USER IF EXISTS '$SDBU'@'localhost';" 2>&1 || echo "WARN: db drop had trouble (continuing)"
  while IFS=$'\t' read -r OF ODB; do rm -f "$OF" "$ODB" 2>/dev/null; done < <(mysql -uroot -N skypanel -e "SELECT files_path, db_path FROM staging_snapshots WHERE clone_id=$CLID;" 2>/dev/null)
  echo "destroy COMPLETE"
} >>"$LOG" 2>&1
