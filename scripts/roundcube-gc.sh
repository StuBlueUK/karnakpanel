#!/usr/bin/env bash
# roundcube-gc.sh — upstream Roundcube housekeeping, hourly from cron (replaces
# the distro roundcube-gc / roundcube-cleandb timers, which go with the
# package): gc.sh every run, cleandb.sh (purge deleted-flagged rows) once a day
# in the 05:xx run. One script, one cron line — cron-table-sync keys lines by
# script path, so two lines for the same script would overwrite each other.
# Cron runs it as root (only root lines ship); the work runs as karnak-webmail,
# the webmail pool user. No-op until roundcube-upstream.sh has installed
# upstream Roundcube. No args.
set -euo pipefail
RC=/usr/local/skypanel/roundcube/current
PHP=/usr/bin/php8.3  # php-ver-ok: webmail tier stays on 8.3
[[ -f "$RC/bin/gc.sh" && -x "$PHP" ]] || exit 0
id karnak-webmail >/dev/null 2>&1 || exit 0
cd /
runuser -u karnak-webmail -- "$PHP" "$RC/bin/gc.sh"
if [[ "$(date +%H)" == "05" ]]; then
  runuser -u karnak-webmail -- "$PHP" "$RC/bin/cleandb.sh"
fi
