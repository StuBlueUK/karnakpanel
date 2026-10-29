#!/usr/bin/env bash
# group-heal.sh — repair accounts named like a SYSTEM group (Ubuntu ships
# 'admin', GID 110, sudo via %admin). create-account gave them u_<name> as
# login group, but before 1.5.36 every chown "$U:$U" and the FPM pool's
# group = $U resolved the NAME to the system group: homes/files landed in it,
# ftp-add made it the suffix logins' PRIMARY group (sudo-capable FTP logins,
# file manager "Permission denied" on their uploads), and the site's PHP ran
# with it. Per colliding account: files/logs -> login group, pool group fixed,
# suffix logins moved off it, account + logins dropped from it.
# Called by post-update.sh. Idempotent; quiet when nothing changes.
set -uo pipefail
. "$(dirname "$(readlink -f "$0")")/ftp-common.sh"
rc=0
declare -A RELOAD=()   # php versions to reload — AFTER the group changes:
                       # FPM workers take supplementary groups at spawn
for C in /etc/php/8.*/fpm/pool.d/skypanel-*.conf; do
  [[ -f "$C" ]] || continue
  U="$(basename "$C" .conf)"; U="${U#skypanel-}"
  [[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || continue
  id "$U" >/dev/null 2>&1 || continue
  SG="$(getent group "$U" | cut -d: -f3)"
  [[ -n "$SG" && "$SG" -lt 1000 ]] || continue          # no collision
  LG="$(id -gn "$U")"; LGID="$(id -g "$U")"
  [[ "$LGID" -ge 1000 ]] || { echo "group-heal: $U login group $LG is a system group — fix by hand"; rc=1; continue; }
  CH=0
  N="$(find "/home/$U" -xdev -gid "$SG" 2>/dev/null | wc -l)"
  if [[ "$N" -gt 0 ]]; then
    # -execdir: the tree is account-writable; a parent swapped for a symlink
    # mid-walk must not steer a root chgrp outside the home
    find "/home/$U" -xdev -gid "$SG" -execdir chgrp -h "$LG" {} + || rc=1
    echo "group-heal: $U: $N items group $U($SG) -> $LG"; CH=1
  fi
  find /var/log/karnakpanel -maxdepth 1 -user "$U" -gid "$SG" -exec chgrp -h "$LG" {} + 2>/dev/null || true
  for P in /etc/php/8.*/fpm/pool.d/skypanel-"$U".conf; do
    [[ -f "$P" ]] || continue
    if grep -qx "group = $U" "$P"; then
      sed -i "s/^group = $U\$/group = $LG/" "$P"
      echo "group-heal: pool $U ($P) group -> $LG"; CH=1
    fi
  done
  for L in "$U" $(getent passwd | cut -d: -f1 | grep "^${U}_" || true); do
    # this account's own FTP logins only ("^${U}_" also matches account "${U}_x")
    [[ "$L" == "$U" || "$(ftp_login_of "$L")" == "$U" ]] || continue
    if [[ "$L" != "$U" && "$(id -g "$L")" == "$SG" ]]; then
      usermod -g "$LG" "$L" && echo "group-heal: login $L primary group -> $LG" || rc=1; CH=1
    fi
    if id -nG "$L" | tr ' ' '\n' | grep -qx "$U"; then
      gpasswd -d "$L" "$U" >/dev/null && echo "group-heal: $L removed from system group $U($SG)" || rc=1; CH=1
    fi
  done
  # running workers spawned before this run still carry the system group
  [[ "$CH" == 1 ]] && for P in /etc/php/8.*/fpm/pool.d/skypanel-"$U".conf; do
    [[ -f "$P" ]] && RELOAD["$(echo "$P" | cut -d/ -f4)"]=1
  done
done
for V in "${!RELOAD[@]}"; do
  "php-fpm$V" -t >/dev/null 2>&1 && systemctl reload "php$V-fpm" || { echo "group-heal: php$V-fpm reload failed"; rc=1; }
done
exit $rc
