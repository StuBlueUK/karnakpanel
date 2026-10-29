#!/usr/bin/env bash
# mail-dns-converge.sh [--dry-run] — host-converge step 16: subdomain
# mail-policy rows into the parent zone + DKIM TXT backfill
# (cli/mail-dns-converge.php does the work; see its header).
set -euo pipefail
PHP="$(command -v php8.3 || command -v php)"  # php-ver-ok: panel CLI = the pinned 8.3 CLI
CLI="$(readlink -f /usr/local/skypanel/panel)/cli/mail-dns-converge.php"
[[ -f "$CLI" ]] || { echo "missing $CLI"; exit 1; }
for a in "$@"; do [[ "$a" == --dry-run ]] || { echo "usage: mail-dns-converge.sh [--dry-run]"; exit 1; }; done
exec "$PHP" "$CLI" "$@"
