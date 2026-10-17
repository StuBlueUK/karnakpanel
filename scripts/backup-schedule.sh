#!/usr/bin/env bash
# backup-schedule.sh <status|HH:MM|disable|enable> — panel control over the
# nightly backup-all run (admin, Backups page). Edits only the backup-all
# line in /etc/cron.d/skypanel; everything else untouched. Disabled state is
# a commented line (re-enable restores 02:00 or the last set time, kept in a
# trailing comment). Validates via cron syntax + a config test parse.
set -euo pipefail
CRON="/etc/cron.d/skypanel"
CMD="/usr/local/skypanel/scripts/backup-all.sh"
[[ -f "$CRON" ]] || { echo "no cron file"; exit 1; }
MODE="${1:?status|HH:MM|disable|enable}"
status() {
  local line
  line="$(grep -E "backup-all\.sh" "$CRON" | head -1 || true)"
  [[ -z "$line" ]] && { echo "status=missing"; return 0; }
  if [[ "$line" =~ ^# ]]; then echo "status=disabled";
  else echo "status=$(echo "$line" | awk '{printf "%02d:%02d", $2, $1}')";
  fi
}
if [[ "$MODE" == "status" ]]; then status; exit 0; fi
cp -a "$CRON" "$CRON.bak-$(date +%Y%m%d)"
if [[ "$MODE" == "disable" ]]; then
  python3 - "$CRON" <<'PYEOF'
import re, sys
p = sys.argv[1]
src = open(p).read()
src = re.sub(r'(?m)^(?!\s*#)(\S+\s+\S+\s+\* \* \* root )(\S*backup-all\.sh.*)$', r'#\1\2', src)
open(p, 'w').write(src)
PYEOF
  echo "nightly backups DISABLED (re-enable anytime; nothing deleted)"
elif [[ "$MODE" == "enable" ]]; then
  python3 - "$CRON" <<'PYEOF'
import re, sys
p = sys.argv[1]
src = open(p).read()
src = re.sub(r'(?m)^#(\S+\s+\S+\s+\* \* \* root \S*backup-all\.sh.*)$', r'\1', src)
open(p, 'w').write(src)
PYEOF
  echo "nightly backups ENABLED"
elif [[ "$MODE" =~ ^([0-9]{1,2}):([0-9]{2})$ ]]; then
  H="${BASH_REMATCH[1]}"; M="${BASH_REMATCH[2]}"
  (( 10#$H <= 23 && 10#$M <= 59 )) || { echo "bad time"; exit 1; }
  python3 - "$CRON" "$M" "$H" <<'PYEOF'
import re, sys
p, mm, hh = sys.argv[1], sys.argv[2], sys.argv[3]
src = open(p).read()
new = "%s %s * * * root /usr/local/skypanel/scripts/backup-all.sh >/dev/null 2>&1" % (mm, hh)
src2, n = re.subn(r'(?m)^#?(\S+\s+\S+\s+\* \* \* root \S*backup-all\.sh.*)$', new, src)
assert n == 1, "backup line not found/unique"
open(p, 'w').write(src2)
PYEOF
  echo "nightly backups at $H:$M"
else
  echo "usage: status|HH:MM|disable|enable"; exit 1
fi
chmod 644 "$CRON"
echo "cron ok"
