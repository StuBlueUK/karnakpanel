#!/usr/bin/env bash
# ftp-tls.sh [domain] — pin vsftpd to the LE cert and force TLS-only logins.
# Idempotent set-or-replace (never duplicates lines). Without this, FTP
# silently stays plaintext (ssl_enable=NO) even after LE is issued for the
# site — clients fall back to insecure without warning.
set -euo pipefail
D="${1:-karnakpanel.com}"
[[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad domain"; exit 1; }
CRT="/etc/letsencrypt/live/$D/fullchain.pem"
KEY="/etc/letsencrypt/live/$D/privkey.pem"
[[ -f "$CRT" && -f "$KEY" ]] || { echo "no LE cert for $D (issue site SSL first)"; exit 1; }
CONF="/etc/vsftpd.conf"
[[ -f "$CONF" ]] || { echo "no $CONF"; exit 1; }
cp -a "$CONF" "/root/vsftpd.conf-pre-tls-$(date +%Y%m%d-%H%M%S)"
set_kv() { # set-or-replace one KEY=VALUE line
  if grep -q "^$1=" "$CONF" 2>/dev/null; then sed -i "s|^$1=.*|$1=$2|" "$CONF";
  else echo "$1=$2" >> "$CONF"; fi
}
set_kv ssl_enable YES
set_kv rsa_cert_file "$CRT"
set_kv rsa_private_key_file "$KEY"
set_kv force_local_logins_ssl YES
set_kv force_local_data_ssl YES
set_kv ssl_tlsv1 YES
set_kv ssl_sslv2 NO
set_kv ssl_sslv3 NO
set_kv require_ssl_reuse NO
systemctl restart vsftpd 2>/dev/null || service vsftpd restart
echo "vsftpd TLS-only on $CRT"
