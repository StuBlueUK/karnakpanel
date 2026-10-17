#!/usr/bin/env bash
# ssh-keygen.sh — create a root ed25519 keypair for panel admins (no passphrase:
# it's for automated/owner use; passphrase-protected keys belong on YOUR machine —
# see the SSH page how-to). Refuses if a keypair already exists (never silently
# replace). Prints the PUBLIC key for the UI and the PRIVATE key once — the panel
# shows it a single time; after that it lives only in /root/.ssh.
set -euo pipefail
mkdir -p /root/.ssh; chmod 700 /root/.ssh
[[ -f /root/.ssh/id_ed25519 ]] && { echo "a keypair already exists — download/use that one (never replaced blindly)"; exit 1; }
ssh-keygen -t ed25519 -N '' -C "karnakpanel-$(hostname)-$(date +%Y%m%d)" -f /root/.ssh/id_ed25519 -q
chmod 600 /root/.ssh/id_ed25519; chmod 644 /root/.ssh/id_ed25519.pub
echo "=== PUBLIC (safe to share) ==="
cat /root/.ssh/id_ed25519.pub
echo "=== PRIVATE (shown ONCE — save it now) ==="
cat /root/.ssh/id_ed25519
