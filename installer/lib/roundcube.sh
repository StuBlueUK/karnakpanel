#!/usr/bin/env bash
# Roundcube webmail on :8091 (OpenLiteSpeed -> karnak-webmail php8.3-fpm pool
# + local Dovecot/Exim). Code is the pinned UPSTREAM release installed by
# scripts/roundcube-upstream.sh — NOT the Ubuntu package: universe Roundcube
# gets no free security fixes (jammy 1.5.0 has the CVE-2025-49113 RCE open).
# This module only creates the DB/user, the vhost and the listener.
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
REPO="$(cd "$(dirname "$0")/../.." && pwd)"

if [[ -s /root/.roundcube-dbpass ]]; then RC_PASS="$(cat /root/.roundcube-dbpass)"; else RC_PASS="$(openssl rand -base64 12 | tr -dc 'a-zA-Z0-9' | head -c 18)"; fi
mysql -uroot <<SQL
CREATE DATABASE IF NOT EXISTS roundcube CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'roundcube'@'localhost' IDENTIFIED BY '${RC_PASS}';
ALTER USER 'roundcube'@'localhost' IDENTIFIED BY '${RC_PASS}';
GRANT ALL PRIVILEGES ON roundcube.* TO 'roundcube'@'localhost';
FLUSH PRIVILEGES;
SQL
echo "$RC_PASS" > /root/.roundcube-dbpass; chmod 600 /root/.roundcube-dbpass
mkdir -p /var/log/roundcube

# Webmail vhost :8091 (native OLS style — see installer/configs/openlitespeed/vhost.conf)
mkdir -p /usr/local/lsws/conf/vhosts/Webmail
# the panel template may sit on any lsphp8N; webmail/PMA stay on 8.3
WEB_LSPHP=lsphp83  # php-ver-ok: webmail/PMA tier stays on 8.3
sed -e "s#/usr/local/skypanel/panel/public/#/usr/local/skypanel/roundcube/current/public_html/#" \
    -e "s/vhDomain.*/vhDomain                  */" -e "/vhAliases/d" \
    -e "s#/var/log/karnakpanel/error.log#/var/log/roundcube/error.log#" \
    -e "s#/var/log/karnakpanel/access.log#/var/log/roundcube/access.log#" \
    -e "s/lsphp8[0-9]/$WEB_LSPHP/g" \
    "$REPO/installer/configs/openlitespeed/vhost.conf" \
    > /usr/local/lsws/conf/vhosts/Webmail/vhost.conf
HTTPD=/usr/local/lsws/conf/httpd_config.conf
grep -q "virtualhost Webmail {" "$HTTPD" 2>/dev/null || cat >> "$HTTPD" <<'EOF'

virtualhost Webmail {
  vhRoot /usr/local/lsws/conf/vhosts/Webmail
  configFile $VH_ROOT/vhost.conf
  allowSymbolLink 1
  enableScript 1
  restrained 0
}
EOF
grep -q "listener webmail8091" "$HTTPD" 2>/dev/null || cat >> "$HTTPD" <<'EOF'

listener webmail8091 {
  address *:8091
  secure 0
  map Webmail *
}
EOF
ufw allow 8091/tcp || true
# panel "Open Webmail" button
if [[ -f /usr/local/skypanel/panel/config/config.php ]]; then
  IP="$(hostname -I | awk '{print $1}')"
  sed -i "s#'webmail_url' => '[^']*'#'webmail_url' => 'http://$IP:8091'#" /usr/local/skypanel/panel/config/config.php
fi
/usr/local/lsws/bin/lswsctrl restart || true
# fetch + verify (sha256 + gpg) + install the pinned release, init the DB,
# put it on its own FPM pool, verify the login page — fails loudly
bash "$REPO/scripts/roundcube-upstream.sh"
echo "Roundcube ready"
