#!/usr/bin/env bash
# pma-gate.sh <username> <hash|--revoke> — manage the phpMyAdmin Basic-Auth gate.
# Only a HASH ever crosses here (generated panel-side); plaintext never hits argv/ps.
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/secret-args.sh"; secret_argv "$@"; set -- "${SECRET_ARGV[@]}"  # @stdin secrets
U="${1:?}"; H="${2:?}"
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad user"; exit 1; }
F=/etc/lsws/phpmyadmin.htpasswd
touch "$F"; chown root:nogroup "$F" 2>/dev/null || true; chmod 640 "$F"
if [[ "$H" == "--revoke" ]]; then
  sed -i "/^$U:/d" "$F"
  echo "gate revoked for $U"
  exit 0
fi
[[ "$H" =~ ^\$5\$rounds=5000\$[a-zA-Z0-9./]{1,16}\$[a-zA-Z0-9./]{43}$ ]] || { echo "bad hash format"; exit 1; }
sed -i "/^$U:/d" "$F"
echo "$U:$H" >> "$F"
chmod 640 "$F"
echo "gate set for $U"
