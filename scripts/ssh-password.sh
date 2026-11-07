#!/usr/bin/env bash
# ssh-password.sh <on|off> — allow or forbid SSH password logins (keys-only mode).
# SAFETY (never regress): 'off' REFUSES unless /root/.ssh/authorized_keys holds
# at least one valid public key — disabling passwords with no key = locked out.
# Writes via sshd-dropin.sh (00-karnakpanel.conf wins over provider drop-ins
# like 50-cloud-init.conf — editing only sshd_config silently did nothing),
# restarts sshd, verifies it stays up AND that `sshd -T` shows the new value.
set -euo pipefail
MODE="${1:?on|off}"
[[ "$MODE" == on || "$MODE" == off ]] || { echo "want on|off"; exit 1; }
HERE="$(cd "$(dirname "$0")" && pwd)"
CONF="/etc/ssh/sshd_config"
[[ -f "$CONF" ]] || { echo "no sshd_config"; exit 1; }
if [[ "$MODE" == "off" ]]; then
  n="$(grep -cE '^(ssh-ed25519|ssh-rsa|ecdsa-sha2)' /root/.ssh/authorized_keys 2>/dev/null || true)"
  (( n >= 1 )) || { echo "REFUSED: no public keys in /root/.ssh/authorized_keys — add one first (SSH page), verify a key login works, then retry"; exit 1; }
fi
cp -a "$CONF" "$CONF.bak-$(date +%Y%m%d)"
[[ -f /etc/ssh/sshd_config.d/00-karnakpanel.conf ]] && cp -a /etc/ssh/sshd_config.d/00-karnakpanel.conf "/root/00-karnakpanel.conf.bak-$(date +%Y%m%d)"
if [[ "$MODE" == off ]]; then
  "$HERE/sshd-dropin.sh" PasswordAuthentication no >/dev/null
  # keyboard-interactive is PAM password prompting by another name
  "$HERE/sshd-dropin.sh" KbdInteractiveAuthentication no >/dev/null
  want=no
else
  "$HERE/sshd-dropin.sh" PasswordAuthentication yes >/dev/null
  want=yes
fi
mkdir -p /run/sshd
sshd -t || { echo "sshd config INVALID — backup at $CONF.bak-$(date +%Y%m%d)"; exit 1; }
systemctl restart ssh
sleep 2
systemctl is-active ssh >/dev/null || { echo "sshd FAILED to restart — fix via console"; exit 1; }
"$HERE/sshd-dropin.sh" --check PasswordAuthentication "$want"
if [[ "$MODE" == off ]]; then
  echo "password logins OFF (keys only, $n key(s) deployed, sshd verified up)"
else
  echo "password logins ON (fail2ban still guards brute force)"
fi
