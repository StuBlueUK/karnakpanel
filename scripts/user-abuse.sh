#!/usr/bin/env bash
# user-abuse.sh — every 5 min: snapshot per-user CPU/MEM/processes to
# /var/lib/skypanel/abuse.json (the admin Abuse board reads it — no costly
# ps/du inside web requests) and alert on SUSTAINED offenders: >150% CPU
# (1.5 cores) or >25% RAM on two consecutive runs mails admins + notifies.
# One alert per episode (state files); recovery clears silently. Thresholds
# are constants below — tune for box size (this box: see nproc/free).
# Test hooks: ABUSE_STATE_DIR + ABUSE_ALERT_TO (space-separated override).
set -uo pipefail
CPU_PCT=150
MEM_PCT=25
STATE_BASE="${ABUSE_STATE_DIR:-/var/lib/skypanel/abuse}"
JSON="/var/lib/skypanel/abuse.json"
LOG="/var/log/karnakpanel/panel.log"
mkdir -p "$STATE_BASE" "$(dirname "$JSON")" 2>/dev/null || true
HOST="$(hostname -f 2>/dev/null || hostname)"
PASS="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
ps -eo user:32,pcpu,pmem,comm --sort=-pcpu 2>/dev/null | awk '
  NR>1 { cpu[$1]+=$2; mem[$1]+=$3; n[$1]++ }
  END { for (u in cpu) printf "%s\t%.1f\t%.1f\t%d\n", u, cpu[u], mem[u], n[u] }' \
  | sort -t"$(printf '\t')" -k2 -nr | head -20 > "$JSON.tmp"
# per-user top-3 processes (command + args, truncated — answers "WHAT is high")
while IFS=$'\t' read -r U C M N; do
  [[ -n "${U:-}" && "$U" != *" "* && "$U" != *"/"* ]] || continue
  # exclude the scanner itself: ps examining /proc burns CPU for that sample
  # and would otherwise report itself as the top offender (seen live: 120%)
  TOP="$(ps -u "$U" -o pcpu=,comm=,args= --sort=-pcpu 2>/dev/null | awk '$2!="ps"' | head -3 | tr '\t' ' ' | cut -c1-80 | tr '\n' '|' || true)"
  printf '%s\t%s\t%s\t%s\t%s\n' "$U" "$C" "$M" "$N" "$TOP"
done < "$JSON.tmp" > "$JSON.tmp2"
mv "$JSON.tmp2" "$JSON.tmp"
mv "$JSON.tmp" "$JSON" 2>/dev/null || true
[[ -n "$PASS" ]] || exit 0
ULIST="$(mktemp)"; trap 'rm -f "$ULIST"' EXIT
MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT username FROM users;" 2>/dev/null > "$ULIST" || exit 0
notify() {
  # title travels hex-encoded: it must never be able to close the SQL string
  # (a tenant's process args once reached it and broke the insert)
  local h; h="$(printf '%s' "${1:0:250}" | od -An -v -tx1 | tr -d ' \n')"
  [[ "$h" =~ ^[0-9a-f]+$ ]] || return 0
  local t="_utf8mb4 X'$h'"
  MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "INSERT INTO notifications(user_id,type,title) SELECT NULL,'abuse',$t FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM notifications WHERE user_id IS NULL AND type='abuse' AND title=$t AND created_at > (NOW() - INTERVAL 1 HOUR));" >>/var/log/karnakpanel/panel.log 2>&1 || echo "$(date -Is) user-abuse: notify insert failed" >>/var/log/karnakpanel/panel.log
}
recipients() {
  local out
  out="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT value FROM settings WHERE name='alert_email';" 2>/dev/null | grep -E '^[^@[:space:]]+@[^@[:space:]]+\.[a-z]{2,}$' | grep -vE '@localhost$' | head -1 || true)"
  if [[ -n "$out" ]]; then echo "$out"; return 0; fi
  MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT DISTINCT email FROM users WHERE role='admin';" 2>/dev/null | grep -E '^[^@[:space:]]+@[^@[:space:]]+\.[a-z]{2,}$' | grep -vE '@localhost$' || true
}
alert() {
  local user="$1" what="$2"
  local TOS="${ABUSE_ALERT_TO:-$(recipients)}"
  echo "$(date -Is) abuse: $user $what" >> "$LOG"
  notify "abuse: $user $what"
  [[ -n "${TOS// }" ]] || return 0
  local to
  for to in $TOS; do
    /usr/sbin/sendmail -t 2>/dev/null <<EOF || true
To: $to
From: KarnakPanel Abuse <abuse@$HOST>
Auto-Submitted: auto-generated
Subject: [KarnakPanel $HOST] resource abuse: $user

User $user $what (thresholds: CPU>${CPU_PCT}% x2 runs, MEM>${MEM_PCT}% x2 runs).
Check the Abuse board, then suspend or throttle the account if it is real.
EOF
  done
}
# 5th field (top processes) is tenant-controlled text for the Abuse board
# only: read it off so it never reaches alert titles, SQL or mail
while IFS=$'\t' read -r U C M N _TOP; do
  [[ -n "${U:-}" && "$C" =~ ^[0-9.]+$ && "$M" =~ ^[0-9.]+$ && "$N" =~ ^[0-9]+$ ]] || continue
  # customer abuse only: system daemons (and any name collision with them)
  # are the watchdog's job, not this board's. Membership = panel users table.
  grep -qxF "$U" "$ULIST" 2>/dev/null || continue
  OVER=""
  if awk "BEGIN{exit !( $C > $CPU_PCT )}"; then OVER="CPU ${C}%"; fi
  if awk "BEGIN{exit !( $M > $MEM_PCT )}"; then OVER="${OVER:+$OVER, }MEM ${M}%"; fi
  SF="$STATE_BASE/$U"
  if [[ -n "$OVER" ]]; then
    if [[ -f "$SF" ]]; then
      if ! grep -qxF "alerted" "$SF" 2>/dev/null; then
        echo "alerted" > "$SF"
        alert "$U" "sustained high usage ($OVER, ${N} procs)"
      fi
    else
      echo "once" > "$SF"
    fi
  else
    rm -f "$SF"
  fi
done < "$JSON"
echo "abuse check done"
