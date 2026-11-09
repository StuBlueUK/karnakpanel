#!/usr/bin/env bash
# disk-usage.sh — print "label<TAB>MB" lines for the cleanup page.
set -euo pipefail
m() { du -sm "$1" 2>/dev/null | cut -f1 || echo 0; }
echo -e "backups\t$(m /backup/skypanel)"
echo -e "weblogs\t$(m /var/log/karnakpanel)"
echo -e "syslog\t$(m /var/log)"
echo -e "releases\t$(m /usr/local/skypanel/releases)"
echo -e "staging\t$(m /usr/local/skypanel/panel/storage/tmp-uploads)"
SESS=0; for f in /var/lib/php/sessions/sess_*; do [[ -f "$f" ]] || continue; SESS=$((SESS+1)); done
echo -e "sessions\t$SESS files"
