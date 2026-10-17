#!/usr/bin/env bash
# offsite-disable.sh — stop syncing and forget the remote (config stanza +
# panel settings rows). Offsite DATA is left alone (delete it at the provider
# if you mean it). The crypt password dies with the config — fetching afterwards
# needs the password shown at setup.
set -euo pipefail
CONF="/root/.config/rclone/rclone.conf"
python3 - "$CONF" <<'PYEOF' 2>/dev/null || true
import re, sys, os
p = sys.argv[1]
if os.path.exists(p):
    src = open(p).read()
    src = re.sub(r'(?ms)^\[offsite\].*?(?=^\[|\Z)', '', src)
    src = re.sub(r'(?ms)^\[offsite-crypt\].*?(?=^\[|\Z)', '', src)
    open(p, 'w').write(src.strip() + '\n')
PYEOF
if [[ -f /root/.skypanel-dbpass ]]; then
  P="$(cat /root/.skypanel-dbpass)"
  MYSQL_PWD="$P" mysql -uskypanel skypanel -e "DELETE FROM settings WHERE name IN ('offsite_provider','offsite_target');" 2>/dev/null || true
fi
echo "offsite disabled (remote data untouched)"
