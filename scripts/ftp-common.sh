#!/usr/bin/env bash
# ftp-common.sh — SOURCED, never run for effect (running it directly only
# defines functions). FTP suffix logins are "<owner>_<name>", but hosting
# usernames may contain "_" too, so the owner is NEVER parsed out of a login:
# it comes from the login's home (/home/<owner>[/<dir>]). Root only touches
# an existing system user that is provably one of that owner's FTP logins —
# never a hosting account (customer "bob" adding FTP name "x" must not reset
# the password of, or delete, the hosting account "bob_x").
ftp_is_panel_user() {  # <name> — a hosting/panel account (fails closed: DB down = no)
  [[ "$1" =~ ^[a-z][a-z0-9_]{2,63}$ ]] || return 1
  [[ -n "$(mysql -uroot -N skypanel -e "SELECT 1 FROM users WHERE username='$1' LIMIT 1" 2>/dev/null)" ]]
}
ftp_login_of() {  # <login> — print its owner if it is an existing FTP suffix login
  local l="$1" e uid home shell o
  e="$(getent passwd "$l")" || return 1
  IFS=: read -r _ _ uid _ _ home shell <<< "$e"
  [[ "$shell" == /bin/false && "$uid" =~ ^[0-9]+$ ]] && (( uid >= 1000 )) || return 1
  [[ "$home" =~ ^/home/([a-z][a-z0-9_]{2,31})(/[a-zA-Z0-9._-]{1,64})?$ && "$home" != *..* ]] || return 1
  o="${BASH_REMATCH[1]}"
  [[ "$l" == "${o}_"?* ]] || return 1
  ftp_is_panel_user "$l" && return 1
  ftp_is_panel_user "$o" || return 1
  printf '%s' "$o"
}
