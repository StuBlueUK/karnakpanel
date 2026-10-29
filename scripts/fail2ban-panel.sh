#!/usr/bin/env bash
# fail2ban-panel.sh <filter-src> — enable the panel-login jail on an existing
# box (fresh installs get it from the installer). Idempotent: skips present
# pieces, reloads fail2ban, verifies the jail is up.
set -euo pipefail
SRC="${1:?filter source path}"
[[ -f "$SRC" ]] || { echo "no such filter: $SRC"; exit 1; }
mkdir -p /etc/fail2ban/filter.d
if ! cmp -s "$SRC" /etc/fail2ban/filter.d/skypanel-panel.conf 2>/dev/null; then
  cp -f "$SRC" /etc/fail2ban/filter.d/skypanel-panel.conf
fi
if ! grep -q '^\[panel\]' /etc/fail2ban/jail.local 2>/dev/null; then
  cat >> /etc/fail2ban/jail.local <<'EOF'

# the panel itself (:8090) — installer-managed section, do not hand-edit
[panel]
enabled = true
backend = polling
port = 8090
filter = skypanel-panel
logpath = /var/log/karnakpanel/panel.log
maxretry = 5
EOF
  echo "jail added"
else
  echo "jail present"
fi
# fail2ban 1.x defaults to the systemd journal, which silently blinds every
# file-log jail (sshd survives via journal; exim/lsws/vsftpd/dovecot/panel do
# not). Pin the file jails to polling so their logpaths are actually watched.
python3 - <<'PYEOF'
import re
p = '/etc/fail2ban/jail.local'
src = open(p).read()
for jail in ['lsws', 'panel', 'phpmyadmin', 'exim', 'dovecot', 'vsftpd']:
    m = re.search(r'(\[%s\]\n(?:.*\n)*?)(?=^\[|\Z)' % jail, src, re.M)
    if m and 'backend' not in m.group(1):
        src = src.replace(m.group(1), m.group(1).replace('enabled = true\n', 'enabled = true\nbackend = polling\n', 1), 1)
open(p, 'w').write(src)
PYEOF
systemctl reload fail2ban 2>/dev/null || systemctl restart fail2ban
sleep 2
fail2ban-client status panel 2>&1 | head -3
