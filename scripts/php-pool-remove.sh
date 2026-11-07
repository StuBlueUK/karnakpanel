#!/usr/bin/env bash
# php-pool-remove.sh <username> — drop all FPM pools for a user. Idempotent.
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/php-versions.sh"
. "$(dirname "$(readlink -f "$0")")/fpm-account.sh"
U="${1:?}"
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad user"; exit 1; }
# every pool dir that exists (not a version list: a pool on a retired
# version must still go)
while IFS= read -r PD; do
  OV="${PD#/etc/php/}"; OV="${OV%%/*}"
  if [[ -f "$PD/skypanel-$U.conf" ]]; then
    rm -f "$PD/skypanel-$U.conf"
    [[ "$PD" == */pool.d ]] && { systemctl reload "php$OV-fpm" 2>/dev/null || true; }
  fi
done < <(php_pool_dirs)
# the account's own master (no pool left = unit, slice and master go)
fpm_sync "$U" || true
echo "pools for $U removed"
