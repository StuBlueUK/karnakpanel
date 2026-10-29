#!/usr/bin/env bash
# ssh-password.sh <on|off> — allow or forbid SSH password logins (keys-only mode).
# SAFETY (never regress): 'off' REFUSES unless /root/.ssh/authorized_keys holds
# at least one valid public key — disabling passwords with no key = locked out.
# Restarts sshd and verifies it stays up; a failed restart aborts loudly.
set -euo pipefail
MODE="${1:?on|off}"
CONF="/etc/ssh/sshd_config"
[[ -f "$CONF" ]] || { echo "no sshd_config"; exit 1; }
if [[ "$MODE" == "off" ]]; then
  n="$(grep -cE '^(ssh-ed25519|ssh-rsa|ecdsa-sha2)' /root/.ssh/authorized_keys 2>/dev/null || true)"
  (( n >= 1 )) || { echo "REFUSED: no public keys in /root/.ssh/authorized_keys — add one first (SSH page), verify a key login works, then retry"; exit 1; }
  cp -a "$CONF" "$CONF.bak-$(date +%Y%m%d)"
  if grep -qi '^[[:space:]]*PasswordAuthentication' "$CONF"; then
    sed -i 's/^[[:space:]]*PasswordAuthentication.*/PasswordAuthentication no/i' "$CONF"
  else
    printf '\nPasswordAuthentication no\n' >> "$CONF"
  fi
  sshd -t || { echo "sshd config INVALID — restored? check $CONF"; exit 1; }
  systemctl restart ssh
  sleep 2
  systemctl is-active ssh >/dev/null || { echo "sshd FAILED to restart — fix via console"; exit 1; }
  echo "password logins OFF (keys only, $n key(s) deployed, sshd verified up)"
else
  cp -a "$CONF" "$CONF.bak-$(date +%Y%m%d)"
  if grep -qi '^[[:space:]]*PasswordAuthentication' "$CONF"; then
    sed -i 's/^[[:space:]]*PasswordAuthentication.*/PasswordAuthentication yes/i' "$CONF"
  else
    printf '\nPasswordAuthentication yes\n' >> "$CONF"
  fi
  sshd -t || { echo "sshd config INVALID"; exit 1; }
  systemctl restart ssh
  sleep 2
  systemctl is-active ssh >/dev/null || { echo "sshd FAILED to restart — fix via console"; exit 1; }
  echo "password logins ON (fail2ban still guards brute force)"
fi
