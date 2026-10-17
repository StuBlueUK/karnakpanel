#!/usr/bin/env bash
# mail-kick.sh <address> — disconnect a mailbox's live IMAP sessions (after
# a password reset: a thief's open session would otherwise outlive the old
# password). Best-effort: no sessions / dovecot down is not an error.
set -euo pipefail
A="${1:?}"
[[ "$A" =~ ^[a-z0-9._%+-]{1,64}@[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad address"; exit 1; }
doveadm kick "$A" >/dev/null 2>&1 || true
echo "kicked $A"
