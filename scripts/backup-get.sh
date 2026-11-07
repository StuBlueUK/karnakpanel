#!/usr/bin/env bash
# backup-get.sh <filename> — stream a panel backup to stdout for download.
# Runs as root (backups are root-owned). Fails loudly on anything unexpected.
set -euo pipefail
F="${1:-}"
[[ "$F" =~ ^[a-zA-Z0-9_.-]+\.(tar\.gz|sql\.gz)$ ]] || { echo "bad filename"; exit 1; }
[[ "$F" != *".."* && "$F" != *"/"* ]] || { echo "bad filename"; exit 1; }
P="/backup/skypanel/$F"
[[ -f "$P" ]] || { echo "not found"; exit 1; }
# encrypted backups stream decrypted (same filename — magic bytes decide)
S="$("$(dirname "$0")/backup-crypt.sh" stage "$P")"
if [[ "$S" != "$P" ]]; then trap 'rm -f "$S"' EXIT; fi
cat -- "$S"
