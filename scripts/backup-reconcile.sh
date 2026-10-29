#!/usr/bin/env bash
# backup-reconcile.sh — register on-disk tarballs missing panel rows (pre-fix
# nightly backups, fetched offsite copies, hand-placed files). Owner parsed
# from the panel filename scheme (usernames can't contain dashes); unknown
# owners are reported, not guessed. Idempotent: existing rows untouched.
set -euo pipefail
P="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
[[ -n "$P" ]] || { echo "no db pass"; exit 1; }
ADDED=0; SKIP=0; UNKNOWN=0
for f in /backup/skypanel/*.tar.gz; do
  [[ -f "$f" ]] || continue
  FN="$(basename "$f")"
  U="${FN%%-*}"
  [[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { UNKNOWN=$((UNKNOWN+1)); echo "unknown owner: $FN"; continue; }
  SZ="$(stat -c%s "$f" 2>/dev/null || echo 0)"
  if MYSQL_PWD="$P" mysql -uskypanel skypanel -N -e "SELECT COUNT(*) FROM backups b JOIN users u ON u.id=b.user_id WHERE u.username='$U' AND b.filename='$FN';" 2>/dev/null | grep -q '^0$'; then
    if MYSQL_PWD="$P" mysql -uskypanel skypanel -e "INSERT INTO backups(user_id,filename,size_bytes) SELECT id,'$FN',$SZ FROM users WHERE username='$U';" 2>/dev/null; then
      ADDED=$((ADDED+1)); echo "registered: $FN"
    fi
  else
    SKIP=$((SKIP+1))
  fi
done
echo "reconciled: $ADDED added, $SKIP already listed, $UNKNOWN unknown-owner"
