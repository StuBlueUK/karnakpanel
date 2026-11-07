#!/usr/bin/env bash
# ssl-remove.sh <domain> — drop the Let's Encrypt cert on domain delete so
# renewals stop failing against dead DNS. Refuses while any panel domain row
# still references the name (shared/sibling cases); mail-cert marker cleared
# when it points at the gone domain, mail SNI rebuilt. Best-effort: never fails the delete.
set -euo pipefail
D="${1:?}"
[[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad domain"; exit 1; }
PASS="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
if [[ -n "$PASS" ]]; then
  N="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT COUNT(*) FROM domains WHERE domain='$D';" 2>/dev/null || echo 1)"
  [[ "$N" == "0" ]] || { echo "domain still present — keeping cert"; exit 0; }
fi
if [[ -d "/etc/letsencrypt/live/$D" ]]; then
  certbot delete --cert-name "$D" --non-interactive 2>&1 | tail -1 || true
  if [[ ! -e "/etc/letsencrypt/live/$D" && ! -e "/etc/letsencrypt/renewal/$D.conf" ]]; then
    echo "cert removed for $D"
  else
    echo "certbot could not remove $D (see its log) — renewal may keep failing; remove by hand"
  fi
else
  echo "no cert for $D"
fi
# mail SNI maps still name the gone cert's copies — rebuild (prunes them)
"$(dirname "$(readlink -f "$0")")/mail-sni.sh" rebuild 2>&1 | grep -E 'updated|pruned' || true
if [[ -f /etc/ssl/skypanel/DOMAIN ]] && [[ "$(cat /etc/ssl/skypanel/DOMAIN)" == "$D" ]]; then
  rm -f /etc/ssl/skypanel/DOMAIN
  echo "mail-cert marker cleared (was $D)"
fi
