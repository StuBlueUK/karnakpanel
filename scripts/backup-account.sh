#!/usr/bin/env bash
# backup-account.sh <username> — full account backup (all domain dirs + home
# Maildir + virtual maildirs for the user's domains + full DB dump alongside).
# Layout: /home/<user>/<domain>/public_html, mail in /var/mail/virtual/<domain>.
set -euo pipefail
U="${1:?}"; TS=$(date +%Y%m%d-%H%M%S)
OUT="/backup/skypanel/${U}-${TS}.tar.gz"
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad user"; exit 1; }
# virtual maildirs belonging to this user's domains (for mailbox-level restore)
MAILDIRS=()
PASS="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
if [[ -n "$PASS" ]]; then
  while IFS= read -r d; do
    [[ -n "$d" && -d "/var/mail/virtual/$d" ]] && MAILDIRS+=("/var/mail/virtual/$d")
  done < <(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT d.domain FROM domains d JOIN users u ON u.id=d.user_id WHERE u.username='$U';" 2>/dev/null)
fi
tar -czf "$OUT" --exclude="/home/$U/.tmp" "/home/$U" "${MAILDIRS[@]}" 2>/dev/null || true
chmod 600 "$OUT" 2>/dev/null || true
"$(dirname "$0")/backup-crypt.sh" encrypt-file "$U" "$OUT" 2>/dev/null || true
mysqldump --all-databases 2>/dev/null | gzip > "/backup/skypanel/db-${TS}.sql.gz" || true
chmod 600 "/backup/skypanel/db-${TS}.sql.gz" 2>/dev/null || true
AU="$("$(dirname "$0")/backup-crypt.sh" admin-user 2>/dev/null || true)"
[[ -n "$AU" ]] && "$(dirname "$0")/backup-crypt.sh" encrypt-file "$AU" "/backup/skypanel/db-${TS}.sql.gz" 2>/dev/null || true
P="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
if [[ -n "$P" ]]; then
  SZ="$(stat -c%s "$OUT" 2>/dev/null || echo 0)"
  MYSQL_PWD="$P" mysql -uskypanel skypanel -e "INSERT INTO backups(user_id,filename,size_bytes) SELECT id,'$(basename "$OUT")',$SZ FROM users WHERE username='$U';" 2>/dev/null || true
  MYSQL_PWD="$P" mysql -uskypanel skypanel -e "INSERT INTO notifications(user_id,type,title) SELECT (SELECT id FROM users WHERE username='$U'),'backup','Scheduled backup completed' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM notifications n JOIN users u ON u.id=n.user_id WHERE u.username='$U' AND n.type='backup' AND n.created_at > (NOW() - INTERVAL 20 HOUR));" >>/var/log/karnakpanel/panel.log 2>&1 || echo "$(date -Is) backup-account: notify insert failed" >>/var/log/karnakpanel/panel.log
fi
echo "$OUT"
