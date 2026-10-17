#!/usr/bin/env bash
# app-daemon-run.sh <user> <name> — ExecStart of a supervised app unit
# (karnak-<user>-<name>.service, written by app-daemon.sh). Runs as root,
# reads the stored command from the root-only store, installs the customer
# link filter (seccomp-common.sh, same as cron-run.sh), then drops to the
# user by NUMERIC uid/gid and execs the command — no shell, no globbing.
# Suspended accounts (account_suspended) exit 0, so Restart=on-failure
# stays down.
set -uo pipefail
U="${1:-}"; N="${2:-}"
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ && "$N" =~ ^[a-z0-9_-]{1,32}$ ]] || { echo "usage: $0 <user> <name>"; exit 1; }
HERE="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
. "$HERE/account-check.sh"
. "$HERE/daemon-common.sh"
. "$HERE/seccomp-common.sh"
panel_account "$U" || { echo "app-daemon-run: refused: $ACCOUNT_CHECK_WHY"; exit 1; }
F="$DAEMON_STORE/$U/$N"
[[ -f "$F" && ! -L "$F" && "$(stat -c %u "$F")" == 0 ]] || { echo "app-daemon-run: no stored daemon $U/$N"; exit 1; }
IFS=: read -r _ _ UIDN GIDN _ HOMED _ <<<"$(getent passwd "$U")"
(( UIDN >= 1000 && GIDN >= 1000 )) || { echo "app-daemon-run: refused system user/group $U"; exit 1; }
[[ "$HOMED" == "/home/$U" ]] || { echo "app-daemon-run: bad home for $U"; exit 1; }
account_suspended "$U" && { echo "app-daemon-run: $U is suspended"; exit 0; }
{ IFS= read -r DIR; IFS= read -r CMD; } < "$F"
daemon_dir_ok "$U" "${DIR:-}" && daemon_cmd_ok "${CMD:-}" || { echo "app-daemon-run: $DAEMON_WHY"; exit 1; }
# resolve links now (the user may have swapped a path component since create)
cd -P -- "$DIR" || exit 1
case "$(pwd -P)/" in "/home/$U/"*) ;; *) echo "app-daemon-run: workdir resolves outside home"; exit 1;; esac
exec perl -e "$SECCOMP_PL" -- setpriv --reuid="$UIDN" --regid="$GIDN" --init-groups --reset-env -- "${DAEMON_ARGV[@]}"
