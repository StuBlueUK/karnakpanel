#!/usr/bin/env bash
# sw-state.sh — progress of one-click installs (Software page "Installing…").
# SOURCED by the *-install.sh scripts, which wrap their background body:
#   sw_begin <app> "$D" "$SUB" || exit 1     # refuses a 2nd install on the same target
#   ( ( exec >>"$SW_OUT" 2>&1               # body unchanged (own traps, set -e)
#       ...
#     ) & sw_wait $! ) >/dev/null 2>&1 &
# The supervisor only WAITS on the body, so every exit (early validation,
# set -e, the body's own EXIT traps) ends in ok/fail — never stuck running.
# Body output goes to a private file and is appended to software.log in one
# piece when it ends (no interleaving with other installs in the shared log).
# State per target in /run/skypanel/sw-bg/<domain>@<sub> (root-only, tmpfs):
#   running|ok|fail \t app \t started-epoch \t message \t supervisor-pid
# CLI: `sw-state.sh pending` lists every entry as TSV
#   domain \t sub \t app \t state \t started \t message
# (the panel keeps only the caller's own domains). Finished entries age out
# after an hour; a running entry whose supervisor is gone is reported failed.
SW_STD=/run/skypanel/sw-bg
SW_LOG=/var/log/karnakpanel/software.log

sw_begin() {
  SW_APP="$1"; SW_F=""; SW_OUT="$SW_LOG"
  [[ "$SW_APP" =~ ^[a-z]{2,20}$ && "$2" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ && "${3:-}" =~ ^[a-zA-Z0-9_-]{0,64}$ ]] || return 0  # body re-validates + refuses
  install -d -m 700 "$SW_STD"
  SW_F="$SW_STD/$2@${3:-}"
  if [[ -f "$SW_F" ]] && [[ "$(cut -f1 "$SW_F" 2>/dev/null)" == running ]]; then
    local pid; pid="$(cut -f5 "$SW_F")"
    if [[ -z "$pid" || -d "/proc/$pid" ]]; then echo "an install is already running on $2${3:+/$3} — wait for it to finish"; return 1; fi
  fi
  SW_OUT="$SW_F.out"; : > "$SW_OUT"
  printf 'running\t%s\t%s\t\t\n' "$SW_APP" "$(date +%s)" > "$SW_F"
}

sw_msg() { # last meaningful line of the body's output (tabs/CR stripped)
  local m=""
  [[ "$1" == ok ]] && m="$(grep -E '^installed ' "$SW_OUT" 2>/dev/null | tail -1 || true)"
  [[ -n "$m" ]] || m="$(grep -vE '^(===|\s*$)' "$SW_OUT" 2>/dev/null | tail -1 || true)"
  printf '%s' "$m" | tr -d '\t\r' | cut -c1-240
}

sw_wait() {
  local rc=0 st started m
  [[ -n "$SW_F" ]] || { wait "$1" || true; return 0; }
  started="$(cut -f3 "$SW_F" 2>/dev/null || date +%s)"
  printf 'running\t%s\t%s\t\t%s\n' "$SW_APP" "$started" "$BASHPID" > "$SW_F"
  wait "$1" || rc=$?
  st=ok; [[ "$rc" == 0 ]] || st=fail
  m="$(sw_msg "$st")"; [[ -n "$m" || "$st" == ok ]] || m="a step failed without a message (exit code $rc)"
  printf '%s\t%s\t%s\t%s\t\n' "$st" "$SW_APP" "$started" "$m" > "$SW_F"
  [[ "$rc" == 0 ]] || echo "install exited with code $rc" >> "$SW_OUT"
  cat "$SW_OUT" >> "$SW_LOG" 2>/dev/null || true
  rm -f "$SW_OUT"
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  set -euo pipefail
  [[ "${1:-}" == pending ]] || { echo "usage: sw-state.sh pending"; exit 1; }
  [[ -d "$SW_STD" ]] || exit 0
  find "$SW_STD" -maxdepth 1 -type f ! -name '*.out' -mmin +60 -exec sh -c 'grep -q "^running" "$1" || rm -f "$1"' _ {} \; 2>/dev/null || true
  for f in "$SW_STD"/*; do
    [[ -f "$f" && "$f" != *.out ]] || continue
    n="$(basename "$f")"; d="${n%%@*}"; s="${n#*@}"
    [[ "$d" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ && "$s" =~ ^[a-zA-Z0-9_-]{0,64}$ ]] || continue
    # cut, not `read`: tab is IFS whitespace, so empty fields would collapse
    L="$(head -1 "$f")"; st="$(cut -f1 <<<"$L")"; app="$(cut -f2 <<<"$L")"
    started="$(cut -f3 <<<"$L")"; msg="$(cut -f4 <<<"$L")"; pid="$(cut -f5 <<<"$L")"
    [[ "$started" =~ ^[0-9]+$ ]] || started=0; [[ "$pid" =~ ^[0-9]*$ ]] || pid=""
    # supervisor gone without a result (killed, reboot of the panel host):
    # report it once, keep whatever the body printed
    if [[ "$st" == running ]] && { [[ -n "${pid:-}" && ! -d "/proc/$pid" ]] || { [[ -z "${pid:-}" ]] && (( $(date +%s) - ${started:-0} > 120 )); }; }; then
      st=fail; msg="install stopped before finishing"
      printf 'fail\t%s\t%s\t%s\t\n' "$app" "$started" "$msg" > "$f"
      [[ -f "$f.out" ]] && { cat "$f.out" >> "$SW_LOG" 2>/dev/null || true; rm -f "$f.out"; }
    fi
    printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$d" "$s" "$app" "$st" "$started" "${msg:-}"
  done
fi
