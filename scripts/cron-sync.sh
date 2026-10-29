#!/usr/bin/env bash
# cron-sync.sh <username> — rebuild a customer's cron jobs from the DB.
# Jobs run from /etc/cron.d/skypanel-user-<user> as root -> cron-run.sh,
# which drops to the user under a no-symlink seccomp filter (see there).
# Commands are stored root-only in /var/lib/karnakpanel/cron/<user>/<id>
# (never in a world-readable cron.d line; no cron '%' mangling either).
# The legacy per-user crontab is removed only AFTER the new set is in place.
set -euo pipefail
U="${1:?}"
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ && "$U" != root ]] || { echo "bad user"; exit 1; }
id "$U" >/dev/null 2>&1 || { echo "no such user"; exit 1; }
PASS=$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")
BASE=/var/lib/karnakpanel/cron
D="$BASE/$U"; CF="/etc/cron.d/skypanel-user-$U"
install -d -m 700 -o root -g root "$BASE"
ROWS="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT j.id, j.schedule, j.command FROM cron_jobs j JOIN users u ON u.id=j.user_id WHERE u.username='$U' AND active=1;" 2>/dev/null)" \
  || { echo "cron-sync: DB read failed for $U"; exit 1; }
rm -rf -- "$D.new"; install -d -m 700 -o root -g root "$D.new"
LINES=""
# basic safety: block destructive patterns + known-harmful binaries (miners,
# scanners, tunnels, shells). Denylist, not a boundary — the jobs run as the
# user either way; this stops the casual/lazy abuse, the abuse board catches
# the determined kind.
DENY='rm -rf /|mkfs|:(){:|:&}|xmrig|minerd|cpuminer|cgminer|bfgminer|ethminer|nmap|masscan|\btor\b|proxychains|hydra|sqlmap|ngrok|chisel|\bfrp\b|iodine|dnscat'
while IFS=$'\t' read -r ID SCH CMD; do
  [[ -n "${ID:-}" ]] || continue
  [[ "$ID" =~ ^[0-9]{1,10}$ ]] || continue
  # same shape CronController accepts; anything else never reaches cron
  [[ "$SCH" =~ ^[0-9*,/-]+(\ [0-9*,/-]+){4}$ ]] || { echo "cron-sync: skipped job $ID (bad schedule)"; continue; }
  # undo mysql batch escaping (\\ \n \t \0) — it doubled every backslash
  CMD="$(printf '%s' "$CMD" | perl -0777 -pe 's/\\(.)/$1 eq "n" ? "\n" : $1 eq "t" ? "\t" : $1 eq "0" ? "" : $1/ges')"
  if grep -qE "$DENY" <<<"$CMD"; then echo "cron-sync: skipped job $ID (blocked command)"; continue; fi
  printf '%s' "$CMD" > "$D.new/$ID"; chmod 600 "$D.new/$ID"
  # stable path, never the resolved releases/<ver>/ (pruned after updates)
  LINES+="$SCH root /usr/local/skypanel/scripts/cron-run.sh $U $ID"$'\n'
done <<<"$ROWS"
# job files first, then the table that names them, then drop the old set
rm -rf -- "$D.old"; [[ -d "$D" ]] && mv -- "$D" "$D.old"
mv -- "$D.new" "$D"
if [[ -n "$LINES" ]]; then
  # cron.d ignores names with a dot: write the temp under one, rename in
  T="/etc/cron.d/.skypanel-user-$U.tmp"
  printf '# managed by cron-sync.sh — edits are overwritten\nSHELL=/bin/sh\nPATH=/usr/sbin:/usr/bin:/sbin:/bin\n%s' "$LINES" > "$T"
  chown root:root "$T"; chmod 600 "$T"; mv -f -- "$T" "$CF"
else
  rm -f -- "$CF"
fi
rm -rf -- "$D.old"
crontab -r -u "$U" 2>/dev/null || true
echo "cron synced for $U"
