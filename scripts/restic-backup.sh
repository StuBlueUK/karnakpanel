#!/usr/bin/env bash
# restic-backup.sh <username> — incremental account snapshot (restic engine).
# Two snapshots per run: kind=files (/home + owned maildirs) and kind=db
# (per-account mysqldumps staged under a STABLE path, so restores address
# them deterministically). Tags: account=<user>. Runs from cron (no sudo
# timeout there); never from a panel click (big accounts exceed 15s).
# Lock: shared with forget/offsite so the repo is never read mid-write.
set -euo pipefail
U="${1:?}"
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad user"; exit 1; }
id "$U" >/dev/null 2>&1 || { echo "no such user"; exit 1; }
BIN="/usr/local/bin/restic"
[[ -x "$BIN" ]] || "$(dirname "$0")/restic-install.sh" >/dev/null 2>&1 || { echo "restic unavailable"; exit 1; }
PASSF="/root/.restic-pass"
REPO="/backup/restic"
[[ -f "$PASSF" && -d "$REPO/keys" ]] || { echo "restic not initialized (run restic-install.sh)"; exit 1; }
export RESTIC_PASSWORD_FILE="$PASSF"
mkdir -p /run/skypanel
exec 9>/run/skypanel/restic.lock
flock -w 900 9 || { echo "restic lock busy"; exit 1; }
P="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
DOMAINS=()
if [[ -n "$P" ]]; then
  while IFS= read -r d; do
    [[ -n "$d" && -d "/var/mail/virtual/$d" ]] && DOMAINS+=("/var/mail/virtual/$d")
  done < <(MYSQL_PWD="$P" mysql -uskypanel skypanel -N -e "SELECT d.domain FROM domains d JOIN users u ON u.id=d.user_id WHERE u.username='$U';" 2>/dev/null || true)
fi
# ionice/nice: backups must never starve serving traffic (quota-enforce doctrine)
ionice -c3 -p $$ 2>/dev/null || true
renice -n 10 -p $$ >/dev/null 2>&1 || true
"$BIN" backup -r "$REPO" "/home/$U" "${DOMAINS[@]}" --tag "account=$U" --tag kind=files 2>&1 | tail -2
# per-account DB dumps (never --all-databases here): stable stage path so
# restic-db.sh can address <dbname>.sql inside any snapshot deterministically.
STAGE="/var/lib/skypanel/db-dumps/$U"
DBS=()
if [[ -n "$P" ]]; then
  while IFS= read -r d; do [[ -n "$d" ]] && DBS+=("$d"); done < <(MYSQL_PWD="$P" mysql -uskypanel skypanel -N -e "SELECT db_name FROM mysql_dbs WHERE user_id=(SELECT id FROM users WHERE username='$U');" 2>/dev/null || true)
fi
if [[ "${#DBS[@]}" -gt 0 ]]; then
  rm -rf "$STAGE"; mkdir -p "$STAGE"; chmod 700 "$STAGE"
  for db in "${DBS[@]}"; do
    [[ "$db" =~ ^[a-z][a-z0-9_]{2,63}$ ]] || continue
    # --databases: self-contained (CREATE+USE) so restores and scratch
    # test-restores work without pre-creating the schema.
    mysqldump --databases "$db" 2>/dev/null > "$STAGE/$db.sql" || echo "WARN: dump of $db failed (skipped)"
  done
  "$BIN" backup -r "$REPO" "$STAGE" --tag "account=$U" --tag kind=db 2>&1 | tail -2
  rm -rf "$STAGE"
else
  echo "(no databases for $U)"
fi
if [[ -n "$P" ]]; then
  MYSQL_PWD="$P" mysql -uskypanel skypanel -e "INSERT INTO notifications(user_id,type,title) SELECT id,'backup','Scheduled backup completed' FROM users WHERE username='$U' AND NOT EXISTS (SELECT 1 FROM notifications n JOIN users u ON u.id=n.user_id WHERE u.username='$U' AND n.type='backup' AND n.created_at > (NOW() - INTERVAL 20 HOUR));" >>/var/log/karnakpanel/panel.log 2>&1 || echo "$(date -Is) restic-backup: notify insert failed" >>/var/log/karnakpanel/panel.log
fi
echo "restic backup done for $U"
