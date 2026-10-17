#!/usr/bin/env bash
# ssl-selfsigned.sh [cn] — TLS for panel (:8090) + webmail (:8091) + phpmyadmin
# (:8092) with a self-signed cert. For LAN-only servers where Let's Encrypt
# can't reach. Idempotent. When going public, run certbot + scripts/ssl-issue.sh,
# which replaces these blocks (looks for the skypanel-selfsigned markers).
set -euo pipefail
CN="${1:-$(hostname -I | awk '{print $1}')}"
SSLDIR=/etc/ssl/skypanel
mkdir -p "$SSLDIR"
if [[ ! -f "$SSLDIR/cert.pem" ]]; then
  openssl req -x509 -newkey rsa:2048 -nodes -days 825 \
    -keyout "$SSLDIR/key.pem" -out "$SSLDIR/cert.pem" \
    -subj "/CN=$CN" -addext "subjectAltName=IP:$CN,DNS:localhost" 2>/dev/null
  chmod 600 "$SSLDIR/key.pem"
  echo "generated self-signed cert for $CN"
fi
# Exim reads the key as Debian-exim: Debian ssl-cert group convention (idempotent)
chgrp ssl-cert "$SSLDIR/key.pem" 2>/dev/null || true
chmod 640 "$SSLDIR/key.pem"
# a running Exim keeps the groups it started with: restart it when the
# membership is new, or STARTTLS/465 fail "Error while reading file".
if ! id -nG Debian-exim 2>/dev/null | grep -qw ssl-cert; then
  adduser Debian-exim ssl-cert 2>/dev/null && systemctl restart exim4 2>/dev/null || true
fi
VHSSL_BLOCK="# skypanel-selfsigned BEGIN
vhssl {
  keyFile $SSLDIR/key.pem
  certFile $SSLDIR/cert.pem
  certChain 1
}
# skypanel-selfsigned END"
for VH in SkyPanel Webmail PhpMyAdmin; do
  VF="/usr/local/lsws/conf/vhosts/$VH/vhost.conf"
  [[ -f "$VF" ]] || { echo "no vhost $VH — skipping"; continue; }
  python3 - "$VF" <<PYEOF
import re, sys
p = sys.argv[1]
s = open(p).read()
s = re.sub(r"# skypanel-selfsigned BEGIN.*?# skypanel-selfsigned END\n?", "", s, flags=re.S)
if "vhssl {" not in s:
    s = s.rstrip() + "\n\n" + """$VHSSL_BLOCK""" + "\n"
    open(p, "w").write(s)
    print("vhssl added to $VH")
else:
    print("$VH already has vhssl — left alone (use ssl-issue.sh for LE)")
PYEOF
done
HTTPD=/usr/local/lsws/conf/httpd_config.conf
# secure listeners need key/cert at LISTENER level too (vhssl alone is not enough).
# Rebuilds all three listener blocks line-wise (idempotent).
declare -A PORTS=([panel8090]=8090 [webmail8091]=8091 [pma8092]=8092)
for LIS in panel8090 webmail8091 pma8092; do
  python3 - "$HTTPD" "$LIS" "$SSLDIR" "${PORTS[$LIS]}" <<'PYEOF'
import re, sys
p, lis, ssldir, port = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4]
lines = open(p).read().splitlines()
out, inl = [], False
for l in lines:
    if re.match(rf"^listener {lis} \{{", l):
        inl = True
        out.append(l)
        out.append(f"  address *:{port}")
        out.append("  secure 1")
        out.append(f"  keyFile {ssldir}/key.pem")
        out.append(f"  certFile {ssldir}/cert.pem")
        out.append("  certChain 1")
        continue
    if inl:
        s = l.strip()
        if s.startswith(("address ", "secure ", "keyFile ", "certFile ", "certChain ")):
            continue
        if s == "}":
            inl = False
    out.append(l)
open(p, "w").write("\n".join(out) + "\n")
print(f"listener {lis} secured")
PYEOF
done
# --- mail transport TLS on the same cert (idempotent appends) ---
# vsftpd: explicit FTPS, plaintext refused by design from here on.
# NOTE: the stock template ships ssl_enable=NO — keys must be REPLACED when
# present, not appended (append-if-missing left ssl_enable=NO live = plaintext).
set_kv() {
  local K="${1%%=*}"
  if grep -q "^$K=" /etc/vsftpd.conf 2>/dev/null; then
    sed -i "s|^$K=.*|$1|" /etc/vsftpd.conf
  else
    echo "$1" >> /etc/vsftpd.conf
  fi
}
for L in "ssl_enable=YES" "rsa_cert_file=$SSLDIR/cert.pem" "rsa_private_key_file=$SSLDIR/key.pem" "ssl_tlsv1=YES" "ssl_sslv2=NO" "ssl_sslv3=NO" "require_ssl_reuse=NO" "force_local_logins_ssl=YES" "force_local_data_ssl=YES"; do
  set_kv "$L"
done
grep -q "^check_shell=NO" /etc/vsftpd.conf 2>/dev/null || echo "check_shell=NO" >> /etc/vsftpd.conf
systemctl restart vsftpd 2>/dev/null || true
# dovecot: IMAPS/POP3S (replaces the template's plaintext default)
# (2.4 templates already carry ssl_server_cert_file — never append 2.3 syntax there)
if ! grep -qE "^ssl_(cert|server_cert_file)" /etc/dovecot/dovecot.conf 2>/dev/null; then
  printf 'ssl = yes\nssl_cert = </etc/ssl/skypanel/cert.pem\nssl_key = </etc/ssl/skypanel/key.pem\n' >> /etc/dovecot/dovecot.conf
  sed -i 's/^ssl = no$/# ssl = no (superseded by TLS block below)/' /etc/dovecot/dovecot.conf
fi
systemctl restart dovecot 2>/dev/null || true
# listener secure/cert changes need a FULL restart (graceful won't rebind)
/usr/local/lsws/bin/lswsctrl stop >/dev/null 2>&1 || true
sleep 2
# start via systemd when the unit exists; never pipe a cold lswsctrl start —
# the daemon inherits the pipe and the reader waits forever
if systemctl cat lshttpd >/dev/null 2>&1; then systemctl start lshttpd
else /usr/local/lsws/bin/lswsctrl start >/dev/null 2>&1; fi
echo "panel + webmail now TLS-only (self-signed — browsers will warn until LE)"

# known-legit change source: refresh the integrity baseline (feature: integrity-check)
"$(cd "$(dirname "$0")" && pwd)"/integrity-check.sh --baseline >/dev/null 2>&1 || true
