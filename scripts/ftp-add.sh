#!/usr/bin/env bash
# ftp-add.sh <login> <password> <homedir>
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/secret-args.sh"; secret_argv "$@"; set -- "${SECRET_ARGV[@]}"  # @stdin secrets
L="${1:?}"; P="${2:?}"; H="${3:-/home/${L%%_*}}"
# FTP uses system auth (vsftpd local_enable): login must be a system user.
# Convention: panel already created owner; suffix accounts are symlinked dirs.
if ! id "$L" >/dev/null 2>&1; then
  OWNER="$(echo "$L" | cut -d_ -f1)"
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
  # domain add — chowning it to the suffix login gets stomped anyway, and the
  # login works via the shared group, not ownership). Only take ownership of
  # dedicated subdir homes.
  mkdir -p "$H"
  if [[ "$H" != "/home/$OWNER" ]]; then chown "$L:$HG" "$H" 2>/dev/null || true; fi
else
  H2="$(printf '%s' "$P" | openssl passwd -6 -stdin)" || { echo "hash failed"; exit 1; }
  echo "$L:$H2" | chpasswd -e
  # re-runs (password resets) also converge group membership for pre-fix logins
  OWNER="$(echo "$L" | cut -d_ -f1)"
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
