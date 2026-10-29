#!/usr/bin/env bash
# post-update.sh — converge a box onto the installed release's system-level
# expectations: file ownership/modes (perms-fix), the shipped cron table
# (cron-table-sync), webmail/phpMyAdmin isolation (app-isolate), upstream
# Roundcube (roundcube-upstream), installer
# fixes for existing boxes (host-converge). Idempotent.
# Called by panel-update.sh after the swap, AND by update-check.sh whenever
# the installed VERSION differs from the last converged one — older
# updaters run the NEW release's update-check.sh right after swapping, so
# this reaches boxes updating from versions that predate these steps.
# State: /var/lib/karnakpanel/converged (version string). No args.
set -uo pipefail
HERE="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
STATE=/var/lib/karnakpanel/converged
VER="$(tr -cd 'a-zA-Z0-9._-' < /usr/local/skypanel/panel/VERSION 2>/dev/null)"
mkdir -p /var/lib/karnakpanel
rc=0
# FIRST: this release's scripts write u:karnak-panel ACL twins, and setfacl
# rejects a whole spec (the u:nobody part too) while the user is missing
"$HERE/panel-user.sh" ensure-user || rc=1
"$HERE/perms-fix.sh" || rc=1
# accounts named like a system group ('admin'): files/pool/FTP logins off it
"$HERE/group-heal.sh" || rc=1
if [[ -f "$HERE/../cron/skypanel.cron" ]]; then
  "$HERE/cron-table-sync.sh" "$HERE/../cron/skypanel.cron" || rc=1
fi
# root-script allowlist follows the panel code of THIS release
"$HERE/panel-sudoers.sh" || rc=1
# 3 = isolation skipped (alerted by app-isolate, retried by its own cron) —
# not a failed convergence, or every later step would rerun daily
"$HERE/app-isolate.sh" || [[ $? -eq 3 ]] || rc=1
# webmail on pinned upstream Roundcube (distro universe package is unpatched)
"$HERE/roundcube-upstream.sh" || rc=1
"$HERE/host-converge.sh" || rc=1
# legit writer of a watched file (/etc/cron.d/skypanel): re-baseline the
# tripwire, or the next nightly check pages the admin about our own change
"$HERE/integrity-check.sh" --baseline >/dev/null 2>&1 || true
# only record success: a failed step is retried by the next daily check
if (( rc == 0 )) && [[ -n "$VER" ]]; then echo "$VER" > "$STATE"; fi
echo "post-update: converged=${VER:-?} rc=$rc"
exit $rc
