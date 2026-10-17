#!/usr/bin/env bash
# Fail2ban + OpenSSH hardening
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
apt-get install -y fail2ban openssh-server
cp -f "$(cd "$(dirname "$0")/../.." && pwd)/installer/configs/fail2ban/jail.local" /etc/fail2ban/jail.local
# custom lsws filter (no stock filter exists for OpenLiteSpeed)
mkdir -p /etc/fail2ban/filter.d
cp -f "$(cd "$(dirname "$0")/../.." && pwd)/installer/configs/fail2ban/filter.d/lsws.conf" /etc/fail2ban/filter.d/lsws.conf
# custom panel-login filter (no stock filter matches Helpers::log lines)
cp -f "$(cd "$(dirname "$0")/../.." && pwd)/installer/configs/fail2ban/filter.d/skypanel-panel.conf" /etc/fail2ban/filter.d/skypanel-panel.conf
cp -f "$(cd "$(dirname "$0")/../.." && pwd)/installer/configs/fail2ban/filter.d/skypanel-weblogin.conf" /etc/fail2ban/filter.d/skypanel-weblogin.conf
# Dovecot 2.4 auth-failure wording (extends the stock dovecot filter)
cp -f "$(cd "$(dirname "$0")/../.." && pwd)/installer/configs/fail2ban/filter.d/dovecot.local" /etc/fail2ban/filter.d/dovecot.local
mkdir -p /etc/fail2ban/action.d
cp -f "$(cd "$(dirname "$0")/../.." && pwd)/installer/configs/fail2ban/action.d/ufw-port.conf" /etc/fail2ban/action.d/ufw-port.conf
# weblogin jail watches per-domain access logs — a fresh box has no domains
# yet, and fail2ban REFUSES TO START when a jail's glob matches nothing
# (killing every jail, not just weblogin). Seed an empty log so the glob
# always resolves; real domains append their own files beside it.
touch /var/log/karnakpanel/placeholder.access.log 2>/dev/null || true
# Seed admin key BEFORE hardening (else password-root disable locks you out).
# Usage: SKY_SSH_KEY="ssh-ed25519 AAAA..." bash install.sh
if [[ -n "${SKY_SSH_KEY:-}" ]]; then
  mkdir -p /root/.ssh; chmod 700 /root/.ssh
  grep -qxF "$SKY_SSH_KEY" /root/.ssh/authorized_keys 2>/dev/null || echo "$SKY_SSH_KEY" >> /root/.ssh/authorized_keys
  chmod 600 /root/.ssh/authorized_keys
fi
# sshd root login follows the SAME rule as passwords below: harden only on an
# explicitly deployed key (SKY_SSH_KEY). A pre-existing authorized_keys
# (provider-injected, tester leftovers) is not proof the admin holds it —
# without this, a keyless fresh box would have NO reachable login at all
# (panel users are nologin shells). Keyless keeps root-password login with a
# loud warning (fail2ban still guards brute force); add a key via panel
# Services → SSH, verify it, then harden both settings.
if [[ -n "${SKY_SSH_KEY:-}" && -s /root/.ssh/authorized_keys ]]; then
  sed -i 's/^#*PermitRootLogin.*/PermitRootLogin prohibit-password/' /etc/ssh/sshd_config
  grep -q "^PermitRootLogin" /etc/ssh/sshd_config || echo "PermitRootLogin prohibit-password" >> /etc/ssh/sshd_config
else
  sed -i 's/^#*PermitRootLogin.*/PermitRootLogin yes/' /etc/ssh/sshd_config
  grep -q "^PermitRootLogin" /etc/ssh/sshd_config || echo "PermitRootLogin yes" >> /etc/ssh/sshd_config
  echo "WARNING: no install-time key — root password login ALLOWED so the box stays reachable; add a key, verify it, then set PermitRootLogin prohibit-password"
fi
# Password logins are safe to kill ONLY when THIS install deployed the key
# (SKY_SSH_KEY given): a pre-existing authorized_keys (provider-injected keys,
# tester leftovers) is NOT proof the admin holds the private half — disabling
# on that strands password users who never knew a key existed. Customer
# accounts are nologin shells, so they never need SSH.
if [[ -n "${SKY_SSH_KEY:-}" && -s /root/.ssh/authorized_keys ]]; then
  sed -i 's/^#*PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
  grep -q "^PasswordAuthentication" /etc/ssh/sshd_config || echo "PasswordAuthentication no" >> /etc/ssh/sshd_config
else
  if [[ -s /root/.ssh/authorized_keys ]]; then
    echo "WARNING: authorized_keys already has keys, but no SKY_SSH_KEY was given — leaving passwords ON (those keys may not be yours):"
    ssh-keygen -lf /root/.ssh/authorized_keys 2>/dev/null || true
  fi
  echo "WARNING: no install-time key — leaving PasswordAuthentication on (disable after deploying a key)"
fi
mkdir -p /run/sshd  # missing on some LXC templates; sshd -t/restart fail without it
sshd -t
# the port sshd ACTUALLY listens on: provider images with a custom Port would
# be locked out by `ufw --force enable` below if we assumed 22. First port
# wins when several are configured; 22 only as a last resort.
SSH_PORT="$(sshd -T 2>/dev/null | awk '$1=="port"{print $2; exit}' || true)"
[[ "$SSH_PORT" =~ ^[0-9]+$ ]] || SSH_PORT=22
sed -i "/^\[sshd\]/,/^\[/ s/^port = .*/port = $SSH_PORT/" /etc/fail2ban/jail.local
# polling jails need their files to EXIST or fail2ban refuses to start at all
# (fresh box, service installed but silent until first event). Touch them all.
mkdir -p /var/log/phpmyadmin /var/log/exim4 /var/log/karnakpanel 2>/dev/null || true
for f in /var/log/auth.log /usr/local/lsws/logs/error.log /var/log/karnakpanel/panel.log /var/log/dovecot.log /var/log/vsftpd.log /var/log/exim4/mainlog /var/log/phpmyadmin/access.log; do
  touch "$f" 2>/dev/null || true
done
systemctl enable fail2ban ssh; systemctl restart fail2ban ssh
# every configured sshd port, BEFORE enabling (never lock out the session
# running this install)
# 24.04 can socket-activate sshd: a port set in an ssh.socket drop-in never
# shows in `sshd -T`, so take the socket's Listen ports too.
while read -r _p; do
  [[ "$_p" =~ ^[0-9]+$ ]] && ufw allow "$_p/tcp" || true
done < <( { sshd -T 2>/dev/null | awk '$1=="port"{print $2}'; \
            systemctl show -p Listen ssh.socket 2>/dev/null | grep -oE ':[0-9]+ \(Stream\)' | tr -dc '0-9\n'; } || true)
ufw allow "$SSH_PORT/tcp" || true
ufw --force enable || true
