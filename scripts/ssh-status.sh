#!/usr/bin/env bash
# ssh-status.sh — TSV for Admin > SSH: password-auth, root-login, key count +
# fingerprints, panel-generated keypair present. Panel runs as nobody and
# can't read sshd_config/authorized_keys; this runs privileged.
set -euo pipefail
CONF="/etc/ssh/sshd_config"
pw="$(grep -Ei '^[[:space:]]*PasswordAuthentication' "$CONF" 2>/dev/null | tail -1 | awk '{print $2}' || true)"
root="$(grep -Ei '^[[:space:]]*PermitRootLogin' "$CONF" 2>/dev/null | tail -1 | awk '{print $2}' || true)"
[[ -z "$pw" ]] && pw="(default yes)"
[[ -z "$root" ]] && root="(default prohibit-password)"
n=0; fps=""
if [[ -f /root/.ssh/authorized_keys ]]; then
  n="$(grep -cE '^(ssh-ed25519|ssh-rsa|ecdsa-sha2)' /root/.ssh/authorized_keys 2>/dev/null || true)"
  fps="$(ssh-keygen -lf /root/.ssh/authorized_keys 2>/dev/null | awk '{print $2}' | head -5 | tr '\n' ',' || true)"
fi
kp="no"
[[ -f /root/.ssh/id_ed25519 ]] && kp="yes"
printf 'pw\t%s\nroot\t%s\nkeys\t%s\nfps\t%s\nkeypair\t%s\n' "$pw" "$root" "$n" "$fps" "$kp"
