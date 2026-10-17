#!/usr/bin/env bash
# mail-reports-ingest.sh [-v] — cron wrapper: parse spooled DMARC/TLS-RPT
# aggregate reports into the panel DB (cli/mail-reports-ingest.php does the
# work; see its header for the hostile-input rules). Single-flight via flock,
# idle priority, hard 10-minute ceiling.
set -euo pipefail
PHP="$(command -v php8.3 || command -v php)"  # php-ver-ok: panel CLI = the pinned 8.3 CLI
CLI="$(readlink -f /usr/local/skypanel/panel)/cli/mail-reports-ingest.php"
[[ -f "$CLI" ]] || { echo "missing $CLI"; exit 1; }
# flock -o: flock itself holds the lock and the run does not inherit it. An
# inherited lock fd outlived the run in the alert mails' background exim
# delivery, so the next run (seconds later) skipped as "already running".
rc=0
flock -n -o -E 75 /run/karnak-mail-reports.lock timeout 600 nice -n 19 ionice -c3 "$PHP" "$CLI" "$@" || rc=$?
[[ $rc -eq 75 ]] && { echo "already running"; exit 0; }
exit "$rc"
