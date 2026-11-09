#!/usr/bin/env bash
# exim-log-hook.sh [ensure] — keep fail2ban's exim jail watching mainlog.
# exim4-base's logrotate stanza says `nocreate` (exim reopens lazily on its
# next log line), so after the nightly rotation mainlog can stay missing for
# hours on a quiet box; the polling backend errors on the absent file, DROPS
# it from the jail ("Too many errors. Remove file") and never re-adds it —
# `fail2ban-client get exim logpath` said "No file is currently monitored"
# on every box (SMTP AUTH brute force unbanned until the next f2b restart).
# The distro conffile is left alone (a modified conffile stalls unattended
# exim4 security updates); instead:
#   no arg:  install a logrotate.service drop-in that runs `ensure` after
#            every rotation (ExecStopPost: runs even if logrotate failed),
#            then `ensure` once. Called by the installer and host-converge.
#   ensure:  recreate a missing mainlog with the dir's owner (Debian-exim:adm
#            640), re-add it to the jail if dropped (`tail`: never re-reads
#            old lines — a rewind would re-ban every historical IP).
# Idempotent, quiet when nothing changes.
set -euo pipefail
LOGD=/var/log/exim4
LOG="$LOGD/mainlog"
ensure() {
  [[ -d "$LOGD" ]] || return 0
  if [[ ! -e "$LOG" ]]; then
    install -m 640 -o "$(stat -c %U "$LOGD")" -g adm /dev/null "$LOG"
    echo "exim-log-hook: recreated $LOG"
  fi
  command -v fail2ban-client >/dev/null 2>&1 && systemctl is-active --quiet fail2ban || return 0
  # capture first: grep -q on a live pipe trips SIGPIPE under pipefail
  local CUR
  CUR="$(fail2ban-client get exim logpath 2>/dev/null)" || return 0  # no exim jail here
  grep -qF "$LOG" <<<"$CUR" && return 0
  fail2ban-client set exim addlogpath "$LOG" tail >/dev/null
  echo "exim-log-hook: fail2ban exim jail watching $LOG again"
}
if [[ "${1:-}" == "ensure" ]]; then ensure; exit 0; fi
[[ -z "${1:-}" ]] || { echo "usage: exim-log-hook.sh [ensure]"; exit 1; }
D=/etc/systemd/system/logrotate.service.d
F="$D/karnakpanel-exim.conf"
WANT='# installed by KarnakPanel (exim-log-hook.sh) — keeps the fail2ban exim jail on mainlog
[Service]
ExecStopPost=/usr/local/skypanel/scripts/exim-log-hook.sh ensure'
if [[ -d "$LOGD" ]] && ! [[ -f "$F" && "$(cat "$F")" == "$WANT" ]]; then
  install -d -m 755 "$D"
  printf '%s\n' "$WANT" > "$F.tmp"; chmod 644 "$F.tmp"; chown root:root "$F.tmp"; mv -f "$F.tmp" "$F"
  systemctl daemon-reload
  echo "exim-log-hook: logrotate drop-in installed ($F)"
fi
ensure
