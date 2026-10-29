#!/usr/bin/env bash
# log-read.sh <service> [lines] — tail a WHITELISTED log for the panel log viewer.
# Runs as root (most service logs are 640/600). Anything off-list is refused;
# line count is clamped so a 1MB OLS error.log can't blow up the web request.
set -euo pipefail
SVC="${1:?}"; N="${2:-150}"
[[ "$N" =~ ^[0-9]{1,3}$ ]] || N=150
(( N >= 20 && N <= 500 )) || N=150
case "$SVC" in
  panel)    F="/var/log/karnakpanel/panel.log" ;;
  exim)     F="/var/log/exim4/mainlog" ;;
  exim_reject) F="/var/log/exim4/rejectlog" ;;
  mail)     F="/var/log/mail.log" ;;
  vsftpd)   F="/var/log/vsftpd.log" ;;
  fail2ban) F="/var/log/fail2ban.log" ;;
  litespeed) F="/usr/local/lsws/logs/error.log" ;;
  upgrade)  F="/var/log/karnakpanel/apt-upgrade.log" ;;
  phpext)   F="/var/log/karnakpanel/php-ext.log" ;;
  update)   F="/var/log/karnakpanel/update.log" ;;
  software) F="/var/log/karnakpanel/software.log" ;;
  apptoolkit) F="/var/log/karnakpanel/apptoolkit.log" ;;
  malware)  F="/var/log/karnakpanel/malware.log" ;;
  offsite)  F="/var/log/karnakpanel/offsite.log" ;;
  certbot)  F="/var/log/karnakpanel/certbot.log" ;;
  named)    grep -h "named\[" /var/log/syslog 2>/dev/null | tail -n "$N"; exit 0 ;;
  *) echo "unknown log: $SVC"; exit 1 ;;
esac
[[ -f "$F" ]] || { echo "no such log yet"; exit 1; }
tail -n "$N" -- "$F"
