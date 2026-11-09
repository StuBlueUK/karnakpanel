#!/usr/bin/env bash
# cron-guard.sh — keep customer cron inside the panel's limits (converge step
# 29; IDEMPOTENT, no args):
#  1. every account with panel cron jobs has its limits slice (cron-caps.sh) —
#     boxes whose jobs were synced before the limits existed
#  2. /etc/cron.allow: customers have no shell, but their PHP can exec()
#     `crontab -` and install jobs the panel never sees (no limits, no
#     denylist, no log). Written once when missing: root + the box's login
#     users (login shell) + any system user (uid < 1000) that already has a
#     crontab. An existing cron.allow is the admin's and is never touched.
#  3. reports (never removes) crontabs of users not in cron.allow: cron.allow
#     only gates the crontab COMMAND (even root's `crontab -u <user>`), the
#     daemon still runs a spool crontab already there (TEST 2026-10-31) — an
#     admin-made one keeps working; editing it means adding that user first.
set -euo pipefail
HERE="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
. "$HERE/cron-caps.sh"
for f in /etc/cron.d/skypanel-user-*; do
  [[ -f "$f" ]] || continue
  U="${f#/etc/cron.d/skypanel-user-}"
  [[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || continue
  kc_slice_put "$U"
done
SP=/var/spool/cron/crontabs
login() { [[ "$1" != */nologin && "$1" != */false && "$1" != */sync && -n "$1" ]]; }
if [[ ! -e /etc/cron.allow ]]; then
  {
    echo root
    getent passwd | while IFS=: read -r n _ uid _ _ _ sh; do
      [[ "$n" == root ]] && continue
      if login "$sh" || { (( uid < 1000 )) && [[ -f "$SP/$n" ]]; }; then echo "$n"; fi
    done
  } | sort -u > /etc/cron.allow.tmp
  chmod 644 /etc/cron.allow.tmp; mv -f /etc/cron.allow.tmp /etc/cron.allow
  echo "cron-guard: /etc/cron.allow written ($(paste -sd' ' /etc/cron.allow))"
fi
for f in "$SP"/*; do
  [[ -f "$f" ]] || continue
  n="${f##*/}"
  grep -qx -- "$n" /etc/cron.allow 2>/dev/null && continue
  echo "cron-guard: WARNING $n has a crontab outside the panel ($f) — review it; crontab -r -u $n removes it"
done
exit 0
