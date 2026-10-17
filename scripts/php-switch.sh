#!/usr/bin/env bash
# php-switch.sh <domain> <8.x> [max_children] — move a user's PHP-FPM pool to another version.
# Pools are per-USER, versions display per-domain: switching one domain moves
# the whole account, so this updates EVERY domain row of the owner to the new
# version (single source of truth — no more stranded mismatches where the DB
# says 8.3 and the pool serves 8.2). The OLS handler (fcgi:fpm-<user>) is
# version-independent, so no vhost edit needed.
set -euo pipefail
D="${1:?}"; V="${2:?}"; C="${3:-10}"
. "$(dirname "$(readlink -f "$0")")/php-versions.sh"
# any installed version (the panel's pickers offer PHP_SELECTABLE only; the
# sibling-converge path in domain delete may legitimately keep a legacy one)
php_known "$V" || { echo "bad version: $V (installed: $(php_installed | xargs))"; exit 1; }
VH="/usr/local/lsws/conf/vhosts/$D/vhost.conf"
[[ -f "$VH" ]] || { echo "no vhost $D" >&2; exit 1; }
U="$(grep -oE '/home/[a-z][a-z0-9_]{2,31}' "$VH" | head -1 | cut -d/ -f3)"
[[ -n "$U" ]] || { echo "cannot determine owner of $D" >&2; exit 1; }
"$(dirname "$0")/php-pool.sh" "$U" "${V/./}" "$C"
# converge the rows: every domain of this user now runs $V (the pool is shared)
PASS="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
if [[ -n "$PASS" ]]; then
  MOVED="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT GROUP_CONCAT(domain SEPARATOR ', ') FROM domains d JOIN users u ON u.id=d.user_id WHERE u.username='$U' AND d.php_version!='$V';" 2>/dev/null || true)"
  MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "UPDATE domains d JOIN users u ON u.id=d.user_id SET d.php_version='$V' WHERE u.username='$U';" 2>/dev/null || echo "WARN: domain rows not updated (set them on the PHP page)"
  echo "$D -> $V (pool $U)"
  if [[ -n "$MOVED" ]]; then echo "sibling domains moved too: $MOVED"; fi
else
  echo "$D -> $V (pool $U) — WARN: no db pass, domain rows left as-is"
fi
