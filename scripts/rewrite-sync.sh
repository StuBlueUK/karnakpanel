#!/usr/bin/env bash
# rewrite-sync.sh <domain> — re-render one vhost with its stored custom
# rewrite rules (domains.rewrite_rules) and gracefully reload OLS.
# SAFE APPLY: the CUSTOM block is whitelist-verified (RewriteCond/
# RewriteRule/comments/blank only — a heredoc breakout is structurally
# impossible), then a loopback smoke check must show a serving status;
# anything else restores the backup and reloads again. Never leaves a
# broken vhost behind.
set -euo pipefail
D="${1:?}"
[[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad domain"; exit 1; }
P="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
[[ -n "$P" ]] || { echo "no panel db pass"; exit 1; }
ROW="$(MYSQL_PWD="$P" mysql -uskypanel skypanel -N -e "SELECT d.user_id, u.username, d.php_version, d.docroot FROM domains d JOIN users u ON u.id=d.user_id WHERE d.domain='$D';" 2>/dev/null || true)"
[[ -n "$ROW" ]] || { echo "domain not in panel"; exit 1; }
UIDN="$(echo "$ROW" | cut -f1)"; U="$(echo "$ROW" | cut -f2)"; PHPV="$(echo "$ROW" | cut -f3)"; DOC="$(echo "$ROW" | cut -f4)"
. "$(dirname "$(readlink -f "$0")")/php-versions.sh"
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] && php_known "$PHPV" || { echo "bad owner/php"; exit 1; }
RULES="$(MYSQL_PWD="$P" mysql --raw -uskypanel skypanel -N -e "SELECT rewrite_rules FROM domains WHERE domain='$D';" 2>/dev/null || true)"
# NOTE: --raw is load-bearing: without it the mysql client escapes newlines
# as literal \n (and doubles backslashes), which would land verbatim in the
# vhost as one dead line (lived: every imported rule silently inert).
# whitelist-verify BEFORE render: only Rewrite directives/comments/blank.
# (Panel validates too; this is the load-bearing gate — root writes the file.)
if [[ -n "$RULES" ]]; then
  BAD="$(printf '%s' "$RULES" | grep -vE '^[[:space:]]*(RewriteCond[[:space:]]|RewriteRule[[:space:]]|#|$)' || true)"
  [[ -z "$BAD" ]] || { echo "REFUSED: non-rewrite lines in custom rules:"; printf '%s\n' "$BAD" | head -5; exit 1; }
  # T=/H= (type/handler) flags can serve PHP source as plain text (another
  # account's wp-config.php through a symlinked dir) — never allowed
  FLAG="$(printf '%s' "$RULES" | grep -iE '\[([^]]*[,[:space:]])?[[:space:]]*(t|type|h|handler)[[:space:]]*=' || true)"
  [[ -z "$FLAG" ]] || { echo "REFUSED: T=/H= (type/handler) rewrite flags:"; printf '%s\n' "$FLAG" | head -5; exit 1; }
  printf '%s' "$RULES" | grep -q "END_rules" && { echo "REFUSED: heredoc terminator in rules"; exit 1; }
fi
VH="/usr/local/lsws/conf/vhosts/$D"
[[ -f "$VH/vhost.conf" ]] || { echo "no vhost for $D"; exit 1; }
cp -a "$VH/vhost.conf" "$VH/vhost.conf.pre-rewrite"
STAGE="$(mktemp)"; trap 'rm -f "$STAGE"' EXIT
printf '%s' "$RULES" > "$STAGE"
chmod 600 "$STAGE"
if ! REWRITE_FILE="$STAGE" "$(dirname "$0")/vhost-add.sh" "$D" "$U" "$PHPV" 2>&1 | tail -2; then
  cp -a "$VH/vhost.conf.pre-rewrite" "$VH/vhost.conf"
  echo "render failed — previous config kept"; exit 1
fi
/usr/local/lsws/bin/lswsctrl reload 2>&1 | tail -1 || true
sleep 2
CODE="$(curl -sk --max-time 10 -o /dev/null -w '%{http_code}' https://127.0.0.1/ -H "Host: $D" 2>/dev/null || echo 000)"
case "$CODE" in
  200|301|302|303|307|308|404)
    rm -f "$VH/vhost.conf.pre-rewrite"
    echo "rewrite rules live for $D (smoke $CODE)" ;;
  *)
    cp -a "$VH/vhost.conf.pre-rewrite" "$VH/vhost.conf"
    /usr/local/lsws/bin/lswsctrl reload 2>&1 | tail -1 || true
    echo "REFUSED: site not serving after reload (smoke $CODE) — previous config restored"; exit 1 ;;
esac
