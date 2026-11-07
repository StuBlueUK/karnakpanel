#!/usr/bin/env bash
# secret-args.sh — SOURCED. Secrets never travel in argv: sudo logs the full
# command line (auth.log/journal), panel.log records it, and any local user
# can read /proc/<pid>/cmdline. The panel (ServiceManager + Secret) passes
# each secret as the literal arg "@stdin" and writes the values to stdin,
# NUL-terminated, in argument order. Usage, before reading positional args:
#   . "$(dirname "$(readlink -f "$0")")/secret-args.sh"; secret_argv "$@"; set -- "${SECRET_ARGV[@]}"
secret_argv() {
  SECRET_ARGV=()
  local a v
  for a in "$@"; do
    if [[ "$a" == "@stdin" ]]; then
      IFS= read -r -d '' v || { echo "secret missing on stdin" >&2; exit 1; }
      SECRET_ARGV+=("$v")
    else
      SECRET_ARGV+=("$a")
    fi
  done
}
