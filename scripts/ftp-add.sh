#!/usr/bin/env bash
# ftp-add.sh <login> <password> <homedir>
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/secret-args.sh"; secret_argv "$@"; set -- "${SECRET_ARGV[@]}"  # @stdin secrets
L="${1:?}"; P="${2:?}"; H="${3:-/home/${L%%_*}}"
. "$(dirname "$(readlink -f "$0")")/ftp-common.sh"
. "$(dirname "$(readlink -f "$0")")/as-user.sh"
[[ "$L" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad login"; exit 1; }
# home = the owner's home or one plain folder in it (the panel passes the
# owner's home). The OWNER comes from the home, never from the login (see
# ftp-common.sh); the login must be <owner>_<name>.
[[ "$H" =~ ^/home/([a-z][a-z0-9_]{2,31})(/[a-zA-Z0-9._-]{1,64})?$ && "$H" != *..* ]] || { echo "bad home dir"; exit 1; }
OWNER="${BASH_REMATCH[1]}"
[[ "$L" =~ ^${OWNER}_[a-z0-9_]{1,32}$ ]] || { echo "login must be ${OWNER}_<name>"; exit 1; }
[[ ! -L "$H" ]] || { echo "home dir is a symlink"; exit 1; }
ftp_is_panel_user "$OWNER" || { echo "no such hosting account $OWNER"; exit 1; }
ftp_is_panel_user "$L" && { echo "REFUSED: $L is a hosting account, not an FTP login"; exit 1; }
# FTP uses system auth (vsftpd local_enable): login must be a system user.
# Convention: panel already created owner; suffix accounts are symlinked dirs.
if ! id "$L" >/dev/null 2>&1; then
  # shared home group: uploads must land group-readable by the owner and
  # sibling logins (vsftpd local_umask=007), so the suffix login's primary
  # group is the OWNER's login group, not a fresh private one. Never by name
  # or from the home's group: a collided name (owner 'admin', Ubuntu's sudo
  # group 'admin' GID 110) would hand the FTP login sudo. Refuse system GIDs.
  HG="$(id -gn "$OWNER" 2>/dev/null)" || { echo "no such owner $OWNER"; exit 1; }
  [[ "$(getent group "$HG" | cut -d: -f3)" -ge 1000 ]] 2>/dev/null || { echo "refusing system group $HG"; exit 1; }
  useradd -d "$H" -s /bin/false -g "$HG" "$L" 2>/dev/null || useradd -d "$H" -s /bin/false "$L" 2>/dev/null || true
  H2="$(printf '%s' "$P" | openssl passwd -6 -stdin)" || { echo "hash failed"; exit 1; }
  echo "$L:$H2" | chpasswd -e
  # shared home root stays the OWNER's (create-account asserts $U: on every
  # domain add, and the login works via the shared group, not ownership). A
  # dedicated subdir home is created by the owner and handed to the login;
  # an existing folder is never taken from the owner.
  if [[ "$H" != "/home/$OWNER" && ! -e "$H" ]]; then
    as_user "$OWNER" mkdir -- "$H" && chown -h "$L:$HG" -- "$H" 2>/dev/null || true
  fi
else
  # an existing user: only ever one of THIS owner's FTP logins
  [[ "$(ftp_login_of "$L")" == "$OWNER" ]] || { echo "REFUSED: $L exists and is not one of $OWNER's FTP logins"; exit 1; }
  H2="$(printf '%s' "$P" | openssl passwd -6 -stdin)" || { echo "hash failed"; exit 1; }
  echo "$L:$H2" | chpasswd -e
  # re-runs (password resets) also converge group membership for pre-fix logins
  # (login group, GID >= 1000 only — see above; also moves a primary group
  # that a pre-fix run left on a system group)
  HG="$(id -gn "$OWNER" 2>/dev/null || echo "")"
  if [[ -n "$HG" && "$(getent group "$HG" | cut -d: -f3)" -ge 1000 ]] 2>/dev/null; then
    [[ "$(id -g "$L")" -ge 1000 ]] || usermod -g "$HG" "$L" 2>/dev/null || true
    usermod -aG "$HG" "$L" 2>/dev/null || true
  fi
fi
grep -qx "$L" /etc/vsftpd.userlist 2>/dev/null || echo "$L" >> /etc/vsftpd.userlist
echo "ftp $L homedir=$H"
