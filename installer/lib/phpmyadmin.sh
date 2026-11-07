#!/usr/bin/env bash
# phpMyAdmin on :8092 (OpenLiteSpeed + LSPHP83 + local MariaDB)
# Lessons baked in (mirrors installer/lib/roundcube.sh):
# - skip dbconfig (no app DB needed); blowfish secret must exist
# - LSPHP include_path is bare '.' — Debian php libs need :/usr/share/php
# - kill stale lsphp workers after php.ini changes (graceful restart keeps them)
# - tmp dir must be writable by the OLS worker (nobody)
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

echo "phpmyadmin phpmyadmin/dbconfig-install boolean false" | debconf-set-selections
apt-get install -y phpmyadmin php-symfony-expression-language php-symfony-cache php-psr-container
# php deps drag apache2 in and apt STARTS it (squats on :80 ahead of OLS).
# openlitespeed.sh masks too early (runs before this) — neutralize AFTER install.
systemctl stop apache2 2>/dev/null || true
systemctl disable apache2 2>/dev/null || true
systemctl mask apache2 2>/dev/null || true

if [[ ! -f /var/lib/phpmyadmin/blowfish_secret.inc.php ]]; then
  BF="$(openssl rand -base64 24 | head -c 32)"
  echo "<?php \$cfg['blowfish_secret'] = '$BF';" > /var/lib/phpmyadmin/blowfish_secret.inc.php
fi
mkdir -p /var/lib/phpmyadmin/tmp
chown -R nobody:nogroup /var/lib/phpmyadmin/tmp /var/lib/phpmyadmin/blowfish_secret.inc.php
chmod 640 /var/lib/phpmyadmin/blowfish_secret.inc.php

# Debian php libs for ALL LSPHP versions (roundcube/phpmyadmin need them)
for INI in /usr/local/lsws/lsphp[0-9][0-9]/etc/php/*/litespeed/php.ini; do
  [[ -f "$INI" ]] || continue
  grep -q '^\s*include_path' "$INI" || echo 'include_path = ".:/usr/share/php"' >> "$INI"
done
pkill -x lsphp 2>/dev/null || true

# PhpMyAdmin vhost :8092
mkdir -p /usr/local/lsws/conf/vhosts/PhpMyAdmin /var/log/phpmyadmin
chown nobody:nogroup /var/log/phpmyadmin
# the panel template may sit on any lsphp8N; webmail/PMA stay on 8.3
WEB_LSPHP=lsphp83  # php-ver-ok: webmail/PMA tier stays on 8.3
sed -e "s#/usr/local/skypanel/panel/public/#/usr/share/phpmyadmin/#" \
    -e "s/vhDomain.*/vhDomain                  */" -e "/vhAliases/d" \
    -e "s#/var/log/karnakpanel/error.log#/var/log/phpmyadmin/error.log#" \
    -e "s#/var/log/karnakpanel/access.log#/var/log/phpmyadmin/access.log#" \
    -e "s/lsphp8[0-9]/$WEB_LSPHP/g" \
    "$(cd "$(dirname "$0")/../.." && pwd)/installer/configs/openlitespeed/vhost.conf" \
    > /usr/local/lsws/conf/vhosts/PhpMyAdmin/vhost.conf
HTTPD=/usr/local/lsws/conf/httpd_config.conf
grep -q "virtualhost PhpMyAdmin {" "$HTTPD" 2>/dev/null || cat >> "$HTTPD" <<'EOF'

virtualhost PhpMyAdmin {
  vhRoot /usr/local/lsws/conf/vhosts/PhpMyAdmin
  configFile $VH_ROOT/vhost.conf
  allowSymbolLink 1
  enableScript 1
  restrained 0
}
EOF
grep -q "listener pma8092" "$HTTPD" 2>/dev/null || cat >> "$HTTPD" <<'EOF'

listener pma8092 {
  address *:8092
  secure 0
  map PhpMyAdmin *
}
EOF
ufw allow 8092/tcp || true
# Basic-Auth gate in front of phpMyAdmin (empty htpasswd = NOBODY passes until
# each panel user sets their gate password; see DatabaseController::gate).
# Realm reference goes in context / (Require left BLANK = any listed user).
mkdir -p /etc/lsws
touch /etc/lsws/phpmyadmin.htpasswd
chown root:nogroup /etc/lsws/phpmyadmin.htpasswd 2>/dev/null || true
chmod 640 /etc/lsws/phpmyadmin.htpasswd
python3 - <<'PYEOF'
p = "/usr/local/lsws/conf/vhosts/PhpMyAdmin/vhost.conf"
s = open(p).read()
if "realm PmaGate" not in s:
    s = s.replace("context / {",
        "realm PmaGate {\n"
        "  userDB {\n"
        "    cacheTimeout          60\n"
        "    maxCacheSize          200\n"
        "    location              /etc/lsws/phpmyadmin.htpasswd\n"
        "  }\n"
        "}\n"
        "context / {", 1)
old = "context / {\n  location"
new = "context / {\n  realm                   PmaGate\n  authName                phpMyAdmin\n  location"
assert old in s, "context header not found"
s = s.replace(old, new, 1)
open(p, "w").write(s)
print("PmaGate realm wired")
PYEOF
# panel "Open phpMyAdmin" button
if [[ -f /usr/local/skypanel/panel/config/config.php ]]; then
  IP="$(hostname -I | awk '{print $1}')"
  if grep -q "phpmyadmin_url" /usr/local/skypanel/panel/config/config.php; then
    sed -i "s#'phpmyadmin_url' => '[^']*'#'phpmyadmin_url' => 'https://$IP:8092'#" /usr/local/skypanel/panel/config/config.php
  else
    sed -i "s#\('webmail_url' => '[^']*'\),#\1,\n              'phpmyadmin_url' => 'https://$IP:8092',#" /usr/local/skypanel/panel/config/config.php
  fi
fi
/usr/local/lsws/bin/lswsctrl restart || true
# our gate-brute-force filter (jail.local already references it)
"$(cd "$(dirname "$0")/../.." && pwd)/scripts/pma-filter-patch.sh"
systemctl restart fail2ban 2>/dev/null || true
echo "phpMyAdmin ready"
