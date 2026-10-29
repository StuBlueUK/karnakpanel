#!/usr/bin/env bash
# OpenLiteSpeed from official repo + default vhost for panel on :8090
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
SKY_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
if ! dpkg -l 2>/dev/null | grep -q openlitespeed; then
  # current upstream method (old rp.openlitespeed.org/debian keys are retired)
  wget -qO - https://repo.litespeed.sh | bash
  apt-get update
fi
apt-get install -y openlitespeed
# Apache is often dragged in by roundcube/php deps and squats on port 80 with
# its default page — the stack is OLS-only, so evict it (keep the packages,
# they satisfy dependencies; just never run the daemon).
if dpkg -l 2>/dev/null | grep -q '^ii  apache2 '; then
  systemctl stop apache2 2>/dev/null || true
  systemctl disable apache2 2>/dev/null || true
  systemctl mask apache2 2>/dev/null || true
fi
# stock Default listener ships on :8088 — customer sites live on :80
sed -i 's/address\(\s*\)\*:\s*8088/address\1*:80/' /usr/local/lsws/conf/httpd_config.conf 2>/dev/null || true
# default landing page for direct-IP hits (else stock OLS "congratulations" page)
mkdir -p /usr/local/lsws/conf/vhosts/KarnakDefault/html
cp -f "$SKY_DIR/installer/configs/openlitespeed/default-index.html" /usr/local/lsws/conf/vhosts/KarnakDefault/html/index.html 2>/dev/null || true
if ! grep -q "virtualhost KarnakDefault {" /usr/local/lsws/conf/httpd_config.conf 2>/dev/null; then
cat >> /usr/local/lsws/conf/httpd_config.conf <<'EOF'

virtualhost KarnakDefault {
  vhRoot /usr/local/lsws/conf/vhosts/KarnakDefault
  docRoot $VH_ROOT/html/
  allowSymbolLink 1
  enableScript 0
  restrained 0
}
EOF
fi
sed -i 's/^\(\s*\)map\(\s*\)Example \*/\1map\2KarnakDefault */' /usr/local/lsws/conf/httpd_config.conf 2>/dev/null || true
# :443 listener for customer HTTPS (SNI; per-vhost certs from vhssl blocks).
# Self-signed fallback cert; vhost-add maps each domain here on creation.
if ! grep -q "^listener SSL443" /usr/local/lsws/conf/httpd_config.conf 2>/dev/null; then
cat >> /usr/local/lsws/conf/httpd_config.conf <<'EOF'

listener SSL443 {
  address *:443
  secure 1
  keyFile /etc/ssl/skypanel/key.pem
  certFile /etc/ssl/skypanel/cert.pem
  certChain 1
}
EOF
fi
ufw allow 443/tcp 2>/dev/null || true
# panel vhost: copy template + register virtualhost (listener alone is not enough)
mkdir -p /usr/local/lsws/conf/vhosts/SkyPanel
cp -f "$SKY_DIR/installer/configs/openlitespeed/vhost.conf" /usr/local/lsws/conf/vhosts/SkyPanel/vhost.conf || true
if ! grep -q "virtualhost SkyPanel {" /usr/local/lsws/conf/httpd_config.conf 2>/dev/null; then
cat >> /usr/local/lsws/conf/httpd_config.conf <<'EOF'

virtualhost SkyPanel {
  vhRoot /usr/local/lsws/conf/vhosts/SkyPanel
  configFile $VH_ROOT/vhost.conf
  allowSymbolLink 1
  enableScript 1
  restrained 0
}
EOF
fi
# ensure listener 8090 exists
grep -q "8090" /usr/local/lsws/conf/httpd_config.conf 2>/dev/null || cat >> /usr/local/lsws/conf/httpd_config.conf <<'EOF'

listener panel8090 {
  address *:8090
  secure 0
  map SkyPanel *
}
EOF
# The noble deb installs lshttpd.service; on Debian, OLS's rc-inst.sh takes
# its init.d branch, leaving only the LSB /etc/init.d/lsws: OLS then runs
# unsupervised and a cold `lswsctrl start` hands the daemon the caller's
# stdout (any `| tail` hangs forever). Install the unit OLS ships, laid out as
# on Ubuntu (unit + lsws.service alias, which shadows the LSB-generated one),
# and stop the unsupervised copy first so systemd owns the running process.
if ! [[ -f /etc/systemd/system/lshttpd.service ]] && [[ -f /usr/local/lsws/admin/misc/lshttpd.service ]]; then
  /usr/local/lsws/bin/lswsctrl stop >/dev/null 2>&1 || true
  install -m644 -o root -g root /usr/local/lsws/admin/misc/lshttpd.service /etc/systemd/system/lshttpd.service
  ln -sf /etc/systemd/system/lshttpd.service /etc/systemd/system/lsws.service
  systemctl daemon-reload
fi
systemctl enable lshttpd 2>/dev/null || systemctl enable lsws 2>/dev/null || true
systemctl restart lshttpd || systemctl restart lsws || /usr/local/lsws/bin/lswsctrl restart || true
ufw allow 8090/tcp || true
# OLS WebAdmin (:7080) stays firewalled: the panel drives OLS itself, and a
# world-open admin login is pure attack surface. Reach it by SSH tunnel
# (ssh -L 7080:127.0.0.1:7080 root@server), or set KARNAK_ADMIN_IP=<your IP>
# to allow just that address.
if [[ "${KARNAK_ADMIN_IP:-}" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]]; then
  ufw allow from "$KARNAK_ADMIN_IP" to any port 7080 proto tcp || true
fi
ufw allow 80,443/tcp || true
# per-IP request-rate caps (connection count is capped by stock default, but
# request rate is unlimited without this — an RPS flood stays under the radar)
"$SKY_DIR/scripts/ols-throttle.sh" 2>&1 || true
