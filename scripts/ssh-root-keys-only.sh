#!/usr/bin/env bash
# ssh-root-keys-only.sh — PermitRootLogin yes -> prohibit-password on boxes
# that are ALREADY keys-only (password + keyboard-interactive logins off, at
# least one root key). A keyless install leaves root-password login on so the
# box stays reachable; the SSH page's "Keys only" button used to switch
# passwords off but never touched PermitRootLogin, leaving `yes` behind
# (harmless while passwords are off, but re-enabling them would reopen root
# passwords, and the Security page flagged it). Changes nothing when passwords
# are still allowed, no key is present, or root login is already restricted.
# sshd -t before reload; reverts on failure. Quiet when nothing to do.
# IDEMPOTENT. Run by host-converge (38).
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
command -v sshd >/dev/null || exit 0
mkdir -p /run/sshd
T="$(timeout 5 sshd -T 2>/dev/null)" || { echo "ssh-root-keys-only: sshd -T failed"; exit 1; }
pw="$(awk '$1=="passwordauthentication"{print $2}' <<<"$T")"
kb="$(awk '$1=="kbdinteractiveauthentication"{print $2}' <<<"$T")"
rl="$(awk '$1=="permitrootlogin"{print $2}' <<<"$T")"
[[ "$rl" == yes && "$pw" == no && "$kb" != yes ]] || exit 0
n="$(grep -cE '^(ssh-ed25519|ssh-rsa|ecdsa-sha2|sk-)' /root/.ssh/authorized_keys 2>/dev/null || true)"
(( ${n:-0} >= 1 )) || exit 0
"$HERE/sshd-dropin.sh" PermitRootLogin prohibit-password >/dev/null
if ! sshd -t; then
  "$HERE/sshd-dropin.sh" PermitRootLogin yes >/dev/null
  echo "ssh-root-keys-only: sshd -t failed, reverted to PermitRootLogin yes"; exit 1
fi
systemctl reload-or-restart ssh
"$HERE/sshd-dropin.sh" --check PermitRootLogin prohibit-password
echo "ssh-root-keys-only: PermitRootLogin yes -> prohibit-password (passwords already off, $n root key(s))"
