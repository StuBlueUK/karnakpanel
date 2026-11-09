#!/usr/bin/env bash
# vsftpd with virtual-user friendly defaults (system users chrooted)
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
apt-get install -y vsftpd db-util
# preserve TLS across re-runs: the template ships plaintext defaults, and a
# blind cp -f would silently downgrade a forced-TLS box. Stash + restore.
TLS_KEEP="$(grep -E '^(ssl_enable|rsa_cert_file|rsa_private_key_file|force_local_logins_ssl|force_local_data_ssl)=' /etc/vsftpd.conf 2>/dev/null || true)"
cp -f "$(cd "$(dirname "$0")/../.." && pwd)/installer/configs/vsftpd/vsftpd.conf" /etc/vsftpd.conf
if [[ -n "$TLS_KEEP" ]]; then
  while IFS= read -r line; do
    K="${line%%=*}"
    if grep -q "^$K=" /etc/vsftpd.conf; then sed -i "s|^$K=.*|$line|" /etc/vsftpd.conf;
    else echo "$line" >> /etc/vsftpd.conf; fi
  done <<< "$TLS_KEEP"
fi
touch /etc/vsftpd.userlist  # referenced by userlist_file; missing file = daemon won't start
# panel FTP users get /bin/false shells (no SSH). PAM's pam_shells would reject them,
# so drop it from vsftpd's PAM stack only (login/sshd stacks untouched).
if grep -q "^auth.*pam_shells" /etc/pam.d/vsftpd 2>/dev/null; then
  sed -i 's/^auth.*pam_shells.*/#&  # skypanel: allow shell-less FTP users/' /etc/pam.d/vsftpd
fi
systemctl enable vsftpd; systemctl restart vsftpd
ufw allow 21,990,40000:50000/tcp || true
