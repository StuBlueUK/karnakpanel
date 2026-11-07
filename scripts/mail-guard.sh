#!/usr/bin/env bash
# mail-guard.sh [-v] [--dry-run] — cron wrapper: compromised-mailbox
# auto-hold (cli/mail-guard.php does the work; see its header). Single-flight
# via flock, idle priority, hard 4-minute ceiling (runs every 5).
set -euo pipefail
PHP="$(command -v php8.3 || command -v php)"  # php-ver-ok: panel CLI = the pinned 8.3 CLI
CLI="$(readlink -f /usr/local/skypanel/panel)/cli/mail-guard.php"
[[ -f "$CLI" ]] || { echo "missing $CLI"; exit 1; }
for a in "$@"; do [[ "$a" == -v || "$a" == --dry-run ]] || { echo "usage: mail-guard.sh [-v] [--dry-run]"; exit 1; }; done
rc=0
flock -n -o -E 75 /run/karnak-mail-guard.lock timeout 240 nice -n 19 ionice -c3 "$PHP" "$CLI" "$@" || rc=$?
[[ $rc -eq 75 ]] && { echo "already running"; exit 0; }
exit "$rc"
