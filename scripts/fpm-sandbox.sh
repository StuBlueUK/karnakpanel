#!/usr/bin/env bash
# fpm-sandbox.sh [off] — systemd sandbox for every php*-fpm unit (the
# customer PHP workers and anything they spawn), on top of the distro unit
# (ProtectSystem=full, PrivateDevices, RestrictNamespaces, ...) and
# symlink-guard's no-symlink filter:
#   - ProtectSystem=strict: the whole fs read-only except what pools write
#     (homes, logs, sockets, sessions, exim's spool for mail()) — no stash
#     in world-writable /var dirs (/var/crash, /var/lib/php/sessions, ...)
#   - ProtectProc=invisible: a worker sees only its own account's processes
#     (no other pools' cmdlines/environ); the root master still sees all
#   - own /dev/shm (tmpfs): pools no longer share it with cron jobs, daemons
#     or the host (pools of one PHP version still share it with each other)
#   - no setuid/setgid file creation, no personality, clock, kernel log,
#     hostname, mount, ptrace (@debug), raw I/O or module syscalls
# NOT used, on purpose (break mail(): sendmail = setuid-root exim):
# NoNewPrivileges, CapabilityBoundingSet (dropping CAP_SYS_ADMIN makes systemd
# imply NoNewPrivileges for the seccomp options), PrivateTmp (an account on
# the shared-/tmp opt-out must see the same /tmp in web and cron).
# @debug also blocks FPM's slowlog (ptrace) — no pool sets it.
# Idempotent; quiet when nothing changes. A unit that fails to restart with
# the sandbox is put back without it (rc 1). Called by symlink-guard.sh (all
# + fpm: installer, host-converge, php-install).
# Pin: /var/lib/karnakpanel/fpm-sandbox-off keeps it off (`off` sets the pin
# and removes the drop-ins).
set -uo pipefail
ST=/var/lib/karnakpanel
PIN="$ST/fpm-sandbox-off"
NAME=karnak-sandbox.conf
MODE="${1:-}"
[[ -z "$MODE" || "$MODE" == off ]] || { echo "usage: $0 [off]"; exit 1; }
[[ "$MODE" == off ]] && { mkdir -p "$ST"; : > "$PIN"; }

DROP='# managed by fpm-sandbox.sh — customer PHP sandbox
[Service]
ProtectSystem=strict
ReadWritePaths=/home /run /tmp /var/tmp /var/log -/var/spool/exim4 -/var/mail -/var/lib/php -/var/lib/karnak-roundcube -/var/lib/phpmyadmin
ProtectProc=invisible
TemporaryFileSystem=/dev/shm:mode=1777,nosuid,nodev,noexec
ProtectKernelLogs=yes
ProtectClock=yes
ProtectHostname=yes
LockPersonality=yes
RestrictSUIDSGID=yes
SystemCallFilter=~@mount @swap @reboot @raw-io @module @obsolete @cpu-emulation @debug
SystemCallErrorNumber=EPERM'

rc=0
CHANGED=()
while read -r UNIT _; do
  [[ "$UNIT" =~ ^php8\.[0-9]+-fpm\.service$ ]] || continue
  F="/etc/systemd/system/$UNIT.d/$NAME"
  if [[ -e "$PIN" ]]; then
    [[ -e "$F" ]] && { rm -f "$F" && CHANGED+=("$UNIT") || rc=1; }
    continue
  fi
  [[ "$(cat "$F" 2>/dev/null)" == "$DROP" ]] && continue
  mkdir -p "${F%/*}" && printf '%s\n' "$DROP" > "$F" && chmod 644 "$F" && CHANGED+=("$UNIT") || rc=1
done < <(systemctl list-unit-files 'php*-fpm.service' --no-legend 2>/dev/null)
(( ${#CHANGED[@]} )) || exit $rc

systemctl daemon-reload || rc=1
for UNIT in "${CHANGED[@]}"; do
  if ! systemctl is-active --quiet "$UNIT"; then
    echo "fpm-sandbox: $UNIT $([[ -e "$PIN" ]] && echo removed || echo installed) (unit not running)"; continue
  fi
  if systemctl restart "$UNIT" && sleep 1 && systemctl is-active --quiet "$UNIT"; then
    if [[ -e "$PIN" ]]; then echo "fpm-sandbox: $UNIT sandbox removed (pinned off, restarted)"
    else echo "fpm-sandbox: $UNIT sandboxed (restarted)"; fi
  else
    echo "fpm-sandbox: $UNIT FAILED to restart — removing the sandbox"
    rm -f "/etc/systemd/system/$UNIT.d/$NAME"; systemctl daemon-reload
    systemctl restart "$UNIT" || true; rc=1
  fi
done
exit $rc
