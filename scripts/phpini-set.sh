#!/usr/bin/env bash
# phpini-set.sh <81|82|83> <key> <value> — change one allowlisted php.ini setting.
# Backs up first; validates with the FPM config test; rolls back on failure.
set -euo pipefail
V="${1:?}"; K="${2:?}"; VAL="${3:?}"
. "$(dirname "$(readlink -f "$0")")/php-versions.sh"
php_known_short "$V" || { echo "bad version"; exit 1; }
F="/etc/php/8.${V:1}/fpm/php.ini"
[[ -f "$F" ]] || { echo "no ini for 8.${V:1}"; exit 1; }
case "$K" in
  memory_limit) [[ "$VAL" =~ ^[0-9]{2,4}M$ ]] || { echo "want like 256M"; exit 1; } ;;
  upload_max_filesize|post_max_size) [[ "$VAL" =~ ^[0-9]{1,4}M$ ]] || { echo "want like 64M"; exit 1; } ;;
  max_execution_time) [[ "$VAL" =~ ^[0-9]{1,4}$ && "$VAL" -ge 10 && "$VAL" -le 900 ]] || { echo "want 10-900"; exit 1; } ;;
  display_errors) [[ "$VAL" == On || "$VAL" == Off ]] || { echo "want On|Off"; exit 1; } ;;
  *) echo "key not editable via panel: $K"; exit 1 ;;
esac
cp "$F" "$F.skypanel-bak"
if grep -qE "^;?\s*$K\s*=" "$F"; then
  sed -i -E "s|^;?\s*$K\s*=.*|$K = $VAL|" "$F"
else
  echo "$K = $VAL" >> "$F"
fi
if "php-fpm8.${V:1}" -t 2>&1 | grep -qiE "successful|ok"; then
  systemctl reload "php8.${V:1}-fpm" 2>/dev/null || systemctl restart "php8.${V:1}-fpm"
  rm -f "$F.skypanel-bak"
  echo "set $K = $VAL for PHP 8.${V:1} (FPM reloaded)"
else
  cp "$F.skypanel-bak" "$F"; rm -f "$F.skypanel-bak"
  echo "FPM test failed — rolled back, value NOT applied"
  exit 1
fi
