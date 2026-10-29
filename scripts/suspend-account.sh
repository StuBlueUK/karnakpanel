#!/usr/bin/env bash
# suspend-account.sh <username> <suspend|unsuspend>
# Shells stay nologin either way (accounts are FTP-only; only the password lock changes).
set -euo pipefail
U="${1:?}"; A="${2:-suspend}"
. "$(dirname "$(readlink -f "$0")")/account-check.sh"
valid_account_name "$U" || exit 1
# the marker (account_suspended) is what cron-run/app-daemon-run go by
if [[ "$A" == "suspend" ]]; then
  usermod -L -s /usr/sbin/nologin "$U"
  install -d -m 700 -o root -g root "$SUSPENDED_DIR"; : > "$SUSPENDED_DIR/$U"; echo "suspended $U"
else usermod -U -s /usr/sbin/nologin "$U" 2>/dev/null || true; rm -f -- "$SUSPENDED_DIR/$U"; echo "unsuspended $U"; fi
# containment: locked users' PHP workers must die too (lock alone doesn't kill them)
# — supervised apps included: app-daemon-run.sh refuses to start a locked
# account, so they stay down (reboots too) until unsuspend resumes them
pkill -u "$U" 2>/dev/null || true
[[ "$A" == "suspend" ]] || "$(dirname "$(readlink -f "$0")")/app-daemon.sh" resume "$U" || true
