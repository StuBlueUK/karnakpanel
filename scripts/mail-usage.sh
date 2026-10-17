#!/usr/bin/env bash
# mail-usage.sh <address> — print mailbox usage in MB (one number, for the panel).
# Reads Dovecot's quota accounting (same numbers IMAP REPORTs), not du.
set -euo pipefail
A="${1:?}"
[[ "$A" =~ ^[a-z0-9._-]+@[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad address"; exit 1; }
# doveadm exits nonzero for unknown mailboxes — don't let pipefail kill us before the friendly error
KB=$(doveadm quota get -u "$A" 2>/dev/null | awk '$1=="User" && $2=="quota" && $3=="STORAGE" {print $4}' || true)
[[ -n "${KB:-}" ]] || { echo "unknown mailbox"; exit 1; }
awk "BEGIN {printf \"%.1f\", $KB/1024}"
