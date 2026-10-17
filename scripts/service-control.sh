#!/usr/bin/env bash
# service-control.sh <restart|start|stop|status> <service-key>
set -euo pipefail
A="${1:?}"; K="${2:?}"
declare -A MAP=( [openlitespeed]=lshttpd [mariadb]=mariadb [bind9]=named [dovecot]=dovecot [exim4]=exim4 [vsftpd]=vsftpd [fail2ban]=fail2ban [spamassassin]=spamd [cron]=cron [ssh]=ssh )
UNIT="${MAP[$K]:-}"
# "php-fpm 8.N" for any php-fpm installed on this box (php-versions.sh)
if [[ -z "$UNIT" && "$K" =~ ^php-fpm\ (8\.[0-9])$ ]]; then
  . "$(dirname "$(readlink -f "$0")")/php-versions.sh"
  php_known "${BASH_REMATCH[1]}" && UNIT="php${BASH_REMATCH[1]}-fpm"
fi
[[ "$A" =~ ^(restart|start|stop|status|reload)$ ]] || { echo "bad action $A"; exit 1; }
[[ -n "$UNIT" ]] || { echo "unknown service $K"; exit 1; }
systemctl "$A" "$UNIT"
systemctl is-active "$UNIT" || true  # 'stop' legitimately ends inactive — don't fail the panel call
