#!/usr/bin/env bash
# bandwidth-collect.sh — tally per-domain bytes from OLS vhost access logs into
# panel DB (bandwidth_daily). IDEMPOTENT: sums today's dated lines and upserts,
# so re-runs and hourly cron are safe. Skips the panel's own access.log.
set -euo pipefail
PASS="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
[[ -n "$PASS" ]] || { echo "no panel db pass"; exit 1; }
DAY="$(date +%d/%b/%Y)"   # matches OLS %t, e.g. 05/Oct/2026
for LOG in /var/log/karnakpanel/*.access.log; do
  [[ -f "$LOG" ]] || continue
  BASE="$(basename "$LOG" .access.log)"
  [[ "$BASE" == "access" ]] && continue   # panel's own log, not billable
  D="$BASE"
  # NOTE: bytes are NOT $NF — since UA/referrer fields were appended, $NF is the
  # user-agent (its version digits counted as bytes!). Parse after "STATUS below.
  BYTES="$(awk -v day="[$DAY" 'index($4,day)==1 { l=$0; if (sub(/" [0-9][0-9][0-9] /, "\n", l)) { n=split(l, a, "\n"); split(a[n], b, " "); gsub(/[^0-9]/, "", b[1]); s+=(b[1]==""?0:b[1]) } } END { print s+0 }' "$LOG")"
  UIDN="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT user_id FROM domains WHERE domain='$D' LIMIT 1;" 2>/dev/null || true)"
  [[ -n "$UIDN" ]] || continue
  MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "INSERT INTO bandwidth_daily(user_id,domain,day,bytes) VALUES($UIDN,'$D',CURDATE(),$BYTES) ON DUPLICATE KEY UPDATE bytes=VALUES(bytes);" 2>/dev/null || true
  echo "$D: $BYTES bytes today"
done
# disk history for usage graphs (migration 039): snapshot today's measured
# usage per account. Upsert = today's row converges on every hourly run, so
# resolution is daily by construction and re-runs are free.
MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "INSERT INTO disk_daily(user_id,day,mb) SELECT id,CURDATE(),COALESCE(disk_used_mb,0) FROM users ON DUPLICATE KEY UPDATE mb=VALUES(mb);" 2>/dev/null || true
