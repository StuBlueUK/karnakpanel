#!/usr/bin/env bash
# ssh-addkey.sh <"type base64 [comment]"> — append a root public key (panel SSH
# page). Strict shape check, duplicates skipped, perms fixed. Private keys and
# options (command=, ...) are refused — this file is root login, keep it plain.
set -euo pipefail
KEY="${1:?}"
[[ "$KEY" =~ ^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp[0-9]+)\ [A-Za-z0-9+/=]+\ ?[a-zA-Z0-9@._-]*$ ]] || { echo "not a plain public key line"; exit 1; }
mkdir -p /root/.ssh; chmod 700 /root/.ssh
touch /root/.ssh/authorized_keys; chmod 600 /root/.ssh/authorized_keys
grep -qxF "$KEY" /root/.ssh/authorized_keys 2>/dev/null && { echo "key already present"; exit 0; }
printf '%s\n' "$KEY" >> /root/.ssh/authorized_keys
echo "key added ($(wc -l < /root/.ssh/authorized_keys) total)"
