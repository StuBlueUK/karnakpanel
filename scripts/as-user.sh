#!/usr/bin/env bash
# as-user.sh — sourced by root scripts that write into a customer's tree.
# SOURCED, never run for effect (running it directly only defines functions).
# RULE: root builds content in its own private dir (mktemp -d), the customer
# account puts it in place. A root write into a customer-controlled path can
# be redirected by a symlink they plant or swap in; as the customer, it can
# only reach what they could write anyway. Reads of root-only staging and
# chown -R (never follows links) stay root.
# Also brings web-acl.sh: acl_web/acl_panel/acl_for — who gets ACL entries.
. "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/web-acl.sh"

# as_user <user> <cmd...> — every customer-uid command goes through here, and
# gets nothing of root's: fds above 2 closed (log and flock fds would otherwise
# be held by a process the account can ptrace/signal), a clean environment
# (no exported secrets), and a root-side pipe in front of any stdout/stderr
# that is a file (a held log fd can be truncated or written at any offset).
as_user() {
  local u="$1"; shift
  ( cd / || exit 1
    for f in /proc/$BASHPID/fd/*; do n="${f##*/}"
      (( n > 2 )) && [[ -e "$f" ]] && eval "exec $n>&-"
    done
    set -- env -i PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin LANG=C.UTF-8 \
      runuser -u "$u" -- "$@"
    if [[ -f /dev/stdout || -f /dev/stderr ]]; then
      { "$@" 2>&1 1>&3 3>&- | cat >&2; exit "${PIPESTATUS[0]}"; } 3>&1 | cat
      exit "${PIPESTATUS[0]}"
    fi
    exec "$@" )
}

# user_unpack <user> <srcdir> <dstdir> — copy a root-built tree into dstdir
# as the user (dstdir created as the user; archived owners never kept)
user_unpack() {
  local u="$1" src="$2" dst="$3"
  as_user "$u" mkdir -p -- "$dst" || return 1
  tar -C "$src" -cf - . | as_user "$u" tar -C "$dst" -xf - --no-same-owner
}

# user_put <user> <dstfile> <mode> — write stdin to dstfile as the user
# (temp + rename in the same dir, so it never truncates through a link)
user_put() {
  as_user "$1" bash -c 'umask 077; t="$(mktemp -p "${1%/*}" .kp-put.XXXXXXXX)" || exit 1
    if cat > "$t" && chmod "$2" -- "$t" && mv -fT -- "$t" "$1"; then exit 0; fi
    rm -f -- "$t"; exit 1' _ "$2" "$3"
}

# user_perms <user> <dir> — dirs 750 / files 640 + the read ACL (web server
# inside docroots, panel elsewhere: acl_for).
# Nothing "other": the web server reads via u:nobody, and home-perms.sh would
# strip "other" anyway — one item at a time, which on a 20k-file app tree made
# the next vhost render take minutes
user_perms() {
  local u="$1" d="$2"
  as_user "$u" find "$d" -type d -exec chmod 750 {} + 2>/dev/null || true
  as_user "$u" find "$d" -type f -exec chmod 640 {} + 2>/dev/null || true
  command -v setfacl >/dev/null 2>&1 && as_user "$u" setfacl -R -P -m "$(acl_for "/home/$u" "$d" rX)" -- "$d" 2>/dev/null || true
}
