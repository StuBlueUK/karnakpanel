#!/usr/bin/env bash
# daemon-common.sh — SOURCED by app-daemon.sh and app-daemon-run.sh. One set of
# rules for a supervised app's command and workdir, checked when it is stored
# AND again every time it starts.
# Why so strict: the unit file used to carry the command (ExecStart=$CMD), and
# a newline in it wrote extra unit lines — `ExecStartPre=+/bin/sh` ran as root.
# The command now lives root-only in DAEMON_STORE and never enters the unit.
# shellcheck disable=SC2034  # read by the scripts that source this
DAEMON_STORE=/var/lib/karnakpanel/daemons
DAEMON_WHY=""
# daemon_cmd_ok <command> — absolute executable + plain tokens, no control
# characters. Sets DAEMON_ARGV (split on blanks, never globbed).
daemon_cmd_ok() {
  local c="$1" t
  DAEMON_ARGV=()
  if [[ -z "$c" || ${#c} -gt 500 || "$c" == *[[:cntrl:]]* ]]; then
    DAEMON_WHY="bad command (empty, too long or control characters)"; return 1
  fi
  read -ra DAEMON_ARGV <<<"$c"
  (( ${#DAEMON_ARGV[@]} > 0 )) || { DAEMON_WHY="bad command"; return 1; }
  for t in "${DAEMON_ARGV[@]}"; do
    [[ "$t" =~ ^[a-zA-Z0-9_./:@=+,~-]{1,160}$ ]] || { DAEMON_WHY="bad argument: $t"; return 1; }
  done
  t="${DAEMON_ARGV[0]}"
  [[ "$t" == /* && -f "$t" && -x "$t" ]] || { DAEMON_WHY="binary not executable (absolute path needed): $t"; return 1; }
  # known-harmful binaries never become supervised services (miners, shells,
  # tor relays, packet tools). Name match on the basename, case-insensitive.
  t="$(basename "$t" | tr 'A-Z' 'a-z')"
  case "$t" in
    xmrig|minerd|cpuminer*|cgminer*|bfgminer*|ethminer*|*miner*|nc|ncat|nmap|masscan|tor|torsocks|proxychains*|hydra|medusa|john|hashcat|aircrack*|reaver*|sqlmap|metasploit*|msf*|beef*|iodine|dnscat*|ptunnel*|chisel*|frp*|ngrok*)
      DAEMON_WHY="REFUSED: $t is not allowed as a supervised app"; return 1;;
  esac
  return 0
}
# daemon_dir_ok <user> <dir> — an existing real directory inside the home,
# no control characters, no . or .. components (callers pass realpath output).
daemon_dir_ok() {
  local u="$1" d="$2"
  if [[ "$d" == *[[:cntrl:]]* || ! "$d" =~ ^/home/$u(/[A-Za-z0-9\ ._@+,~-]+)*$ || "$d/" == */./* || "$d/" == */../* ]]; then
    DAEMON_WHY="workdir outside home or bad characters"; return 1
  fi
  [[ -d "$d" ]] || { DAEMON_WHY="no such workdir"; return 1; }
  return 0
}
