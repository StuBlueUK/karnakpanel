#!/usr/bin/env bash
# account-check.sh — SOURCED. Tells a panel-made hosting account apart from a
# system or distro user. create-account.sh used to adopt any existing user of
# the requested name ("ubuntu", "debian", "admin" on Debian cloud images,
# lsadm, syslog ...): the user kept its login shell and sudo group, got the
# panel/billing password via chpasswd and was put on the vsftpd allow-list.
# Same class of bug as the 'admin' group in 1.5.36 (docs/gotchas.md).
# Usage:
#   . "$(dirname "$(readlink -f "$0")")/account-check.sh"
#   valid_account_name "$U" || exit 1
#   panel_account "$U" || { echo "refused: $ACCOUNT_CHECK_WHY"; exit 3; }
# shellcheck disable=SC2034  # read by the scripts that source this
ACCOUNT_CHECK_WHY=""
SUSPENDED_DIR=/var/lib/karnakpanel/suspended
# true while the account is suspended (cron-run, app-daemon-run refuse it).
# The marker from suspend-account.sh is the truth: usermod -L leaves a
# password-less account's bare "!" unchanged, so shadow alone can't tell.
# A locked hash ("!$...") still counts (suspends from before the marker).
account_suspended() {
  local sh
  [[ -e "$SUSPENDED_DIR/$1" ]] && return 0
  sh="$(getent shadow "$1" | cut -d: -f2)"
  [[ "$sh" =~ ^!+\$ ]]
}
valid_account_name() {
  [[ "${1:-}" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad user"; return 1; }
}
# true when user $1 exists and looks like one the panel made: uid >= 1000,
# nologin/false shell, no membership in a group that can use sudo or read logs.
panel_account() {
  local u="$1" ent uid shell g
  ent="$(getent passwd "$u")" || { ACCOUNT_CHECK_WHY="no such user $u"; return 1; }
  uid="$(cut -d: -f3 <<<"$ent")"; shell="$(cut -d: -f7 <<<"$ent")"
  if [[ ! "$uid" =~ ^[0-9]+$ ]] || (( uid < 1000 || uid == 65534 )); then
    ACCOUNT_CHECK_WHY="$u is a system user (uid $uid), not a hosting account"; return 1
  fi
  case "$shell" in
    /usr/sbin/nologin|/sbin/nologin|/bin/false|/usr/bin/false) ;;
    *) ACCOUNT_CHECK_WHY="$u has a login shell ($shell), not a hosting account"; return 1 ;;
  esac
  for g in $(id -Gn "$u" 2>/dev/null); do
    case "$g" in
      root|sudo|admin|adm|wheel|lxd|docker|disk|shadow|staff|systemd-journal)
        ACCOUNT_CHECK_WHY="$u is in the system group $g, not a hosting account"; return 1 ;;
    esac
  done
  return 0
}
