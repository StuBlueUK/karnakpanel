#!/usr/bin/env bash
# opcache-set.sh <81|82|83> <on|off> — toggle Zend OPcache for one PHP-FPM version.
set -euo pipefail
V="${1:?}"; A="${2:?}"
. "$(dirname "$(readlink -f "$0")")/php-versions.sh"
php_known_short "$V" || { echo "bad version"; exit 1; }
[[ "$A" == on || "$A" == off ]] || { echo "want on|off"; exit 1; }
F="/etc/php/8.${V:1}/fpm/conf.d/10-opcache.ini"
[[ -f "$F" ]] || { echo "no opcache ini for 8.${V:1}"; exit 1; }
VAL=1; [[ "$A" == off ]] && VAL=0
cp "$F" "$F.skypanel-bak"
if grep -qE "^;?\s*opcache\.enable\s*=" "$F"; then
  sed -i -E "s|^;?\s*opcache\.enable\s*=.*|opcache.enable=$VAL|" "$F"
else
  echo "opcache.enable=$VAL" >> "$F"
fi
if "php-fpm8.${V:1}" -t 2>&1 | grep -qiE "successful|ok"; then
  systemctl reload "php8.${V:1}-fpm" 2>/dev/null || systemctl restart "php8.${V:1}-fpm"
  rm -f "$F.skypanel-bak"
  echo "opcache $A for PHP 8.${V:1} (FPM reloaded)"
else
  cp "$F.skypanel-bak" "$F"; rm -f "$F.skypanel-bak"
  echo "FPM test failed — rolled back"
  exit 1
fi
