#!/usr/bin/env bash
# mail-sent.sh <username> — authenticated SMTP submissions TODAY across all of
# the user's mailbox addresses (what the rate limiter counts). Reads the live
# mainlog only (rotated logs excluded), so the number resets daily by nature.
# Prints an integer, always (0 on any failure — callers show —/0, never fatal).
set -uo pipefail
U="${1:?}"
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo 0; exit 0; }
PASS="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
[[ -n "$PASS" ]] || { echo 0; exit 0; }
TMP="$(mktemp)"; trap 'rm -f "$TMP"' EXIT
MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT CONCAT('A=dovecot_plain:',e.localpart,'@',d.domain) FROM email_accounts e JOIN domains d ON d.id=e.domain_id JOIN users u ON u.id=e.user_id WHERE u.username='$U';" 2>/dev/null > "$TMP" || { echo 0; exit 0; }
[[ -s "$TMP" ]] || { echo 0; exit 0; }
grep -F -f "$TMP" /var/log/exim4/mainlog 2>/dev/null | grep -c '<= ' || echo 0
