#!/usr/bin/env bash
# usage-alerts.sh — early warnings at 80% and 95% of disk / monthly bandwidth,
# BEFORE quota-enforce.sh (100% disk: FTP lock + site offline) or
# bandwidth-enforce.sh (100% bandwidth: suspended) act. Chained from the end
# of quota-enforce.sh (every 5 min) so there is no extra cron line.
# Reads only the cached numbers: disk_used_mb (fresh <1h, written by the
# enforcer) and SUM(bandwidth_daily) for this month — it never walks a tree.
# One alert per level: users.disk_alert / bw_alert hold the last level sent
# (0/80/95); it re-arms only after usage falls under 75% (no flapping at
# 79/80). Bandwidth state is per month (bw_alert_month) so it re-arms on the
# 1st by itself. Already at/over 100% = the enforcer has spoken: level is
# recorded silently. Admins + suspended accounts skipped, quota <=0 =
# unlimited (same rules as the enforcers).
# Delivery: the user's panel feed + email; a reseller owner gets a feed entry
# for their client. Type 'usage', always user-scoped — never user_id NULL
# (push-digest.sh would page the admin for it).
# Branded resellers (branding table): their panel name is the From display
# name and their support contact closes the email.
# Test hook: USAGE_ALERTS_QUEUE_ONLY=1 queues mail without delivery (-odq).
set -euo pipefail
PASS="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
[[ -n "$PASS" ]] || { echo "no panel db pass"; exit 1; }
LOG=/var/log/karnakpanel/panel.log
CUR="$(date +%Y-%m)"
sql() { MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "$1" 2>/dev/null; }
# From: the panel domain (what Exim's primary_hostname is built from), not the
# VPS provider's hostname — a mismatched sender domain lands in spam
HOST="$(sql "SELECT value FROM settings WHERE name='panel_domain';" || true)"
[[ "$HOST" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || HOST="$(hostname -f 2>/dev/null || hostname)"
notify() { # notify <user_id> <title>
  sql "INSERT INTO notifications(user_id,type,title) VALUES($1,'usage','$2');" \
    || echo "$(date -Is) usage-alerts: notify insert failed" >>"$LOG"
}
gb() { awk -v m="$1" 'BEGIN{ if (m >= 1024) printf "%.2f GB", m/1024; else printf "%.0f MB", m }'; }
mail_user() { # mail_user <email> <subject> <body>   (uses $FROMNAME $HELP)
  local to="$1"
  # [^@[:space:]] also rules out CR/LF (no header injection); reserved
  # .invalid / localhost addresses are never routable — feed only
  if [[ ! "$to" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[a-z]{2,}$ || "$to" =~ @localhost$ || "$to" =~ \.invalid$ ]]; then
    echo "$(date -Is) usage-alerts: no routable email for alert: $2" >>"$LOG"; return 0
  fi
  local q=(); [[ "${USAGE_ALERTS_QUEUE_ONLY:-}" == "1" ]] && q=(-odq)
  /usr/sbin/sendmail -t "${q[@]}" 2>/dev/null <<EOF || echo "$(date -Is) usage-alerts: sendmail to $to failed" >>"$LOG"
To: $to
From: $FROMNAME <noreply@$HOST>
Auto-Submitted: auto-generated
Subject: $2

$3
$HELP
-- 
Sent automatically by the hosting control panel on $HOST.
EOF
}
# level <pct> <stored>: 95/80 at or above, 0 under 75, otherwise keep (hysteresis)
level() {
  if (( $1 >= 95 )); then echo 95; elif (( $1 >= 80 )); then echo 80; elif (( $1 < 75 )); then echo 0; else echo "$2"; fi
}
# NOTE: every text column is '-' when empty — tab is IFS whitespace, so an
# empty field would collapse and shift every column after it
while IFS=$'\t' read -r ID U EMAIL DQ DU DFRESH BQ BUSED DA BA BAM OID OROLE BNAME BMAIL BURL; do
  [[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ && "$ID" =~ ^[0-9]+$ ]] || continue
  [[ "$EMAIL" == "-" ]] && EMAIL=""
  # header-safe display name (plain characters only), else stock
  FROMNAME="$(printf '%s' "$BNAME" | tr -cd "A-Za-z0-9 .&'_-" | cut -c1-40)"
  [[ "$BNAME" == "-" || -z "${FROMNAME// }" ]] && FROMNAME="KarnakPanel"
  HELP=""
  [[ "$BMAIL" != "-" ]] && HELP+=$'\n'"Support: $BMAIL"
  [[ "$BURL" != "-" ]] && HELP+=$'\n'"Help: $BURL"
  # ---- disk (fresh cache only: stale/NULL = wait for the enforcer) ----
  if [[ "$DQ" =~ ^[0-9]+$ && "$DQ" -gt 0 && "$DU" =~ ^[0-9]+$ && "$DFRESH" == "1" ]]; then
    PCT=$(( DU * 100 / DQ ))
    L="$(level "$PCT" "$DA")"
    if [[ "$L" != "$DA" ]]; then
      if (( L > DA && PCT < 100 )); then
        T="Disk ${PCT}% used ($(gb "$DU") of $(gb "$DQ"))"
        notify "$ID" "$T — at 100% FTP locks and sites go offline"
        [[ "$OROLE" == "reseller" ]] && notify "$OID" "Client $U: $T"
        mail_user "$EMAIL" "Disk space ${PCT}% used: $U" "Your hosting account $U has used $(gb "$DU") of its $(gb "$DQ") disk space (${PCT}%).

When it reaches 100%, FTP uploads are locked and your websites go offline until usage drops back under the limit. Mail keeps arriving.

To free space: delete old backups, unused files or large mailboxes from the control panel (Disk usage shows what is using it), or ask your provider for a bigger plan."
        echo "$(date -Is) usage-alerts: disk $L% alert for $U (${DU}MB of ${DQ}MB)" >>"$LOG"
      fi
      sql "UPDATE users SET disk_alert=$L WHERE id=$ID;" || true
    fi
  fi
  # ---- bandwidth (this calendar month; state re-arms on a new month) ----
  [[ "$BAM" == "$CUR" ]] || BA=0
  if [[ "$BQ" =~ ^[0-9]+$ && "$BQ" -gt 0 && "$BUSED" =~ ^[0-9]+$ ]]; then
    PCT=$(( BUSED * 100 / (BQ * 1073741824) ))
    L="$(level "$PCT" "$BA")"
    if [[ "$L" != "$BA" || "$BAM" != "$CUR" ]]; then
      if (( L > BA && PCT < 100 )); then
        USEDMB=$(( BUSED / 1048576 ))
        T="Bandwidth ${PCT}% used this month ($(gb "$USEDMB") of ${BQ} GB)"
        notify "$ID" "$T — at 100% the account is suspended until the 1st"
        [[ "$OROLE" == "reseller" ]] && notify "$OID" "Client $U: $T"
        mail_user "$EMAIL" "Bandwidth ${PCT}% used: $U" "Your hosting account $U has used $(gb "$USEDMB") of its ${BQ} GB monthly bandwidth (${PCT}%).

When it reaches 100%, the account is suspended until the counter resets on the 1st of next month.

If you expect more traffic, ask your provider for a bigger plan before then."
        echo "$(date -Is) usage-alerts: bandwidth $L% alert for $U (${USEDMB}MB of ${BQ}GB)" >>"$LOG"
      fi
      sql "UPDATE users SET bw_alert=$L, bw_alert_month='$CUR' WHERE id=$ID;" || true
    fi
  fi
done < <(sql "SELECT u.id, u.username, IF(IFNULL(u.email,'')='','-',u.email), u.disk_quota_mb, IFNULL(u.disk_used_mb,-1),
  IF(u.disk_measured_at > NOW() - INTERVAL 1 HOUR, 1, 0), u.bandwidth_quota_gb,
  (SELECT IFNULL(SUM(b.bytes),0) FROM bandwidth_daily b WHERE b.user_id=u.id AND b.day >= DATE_FORMAT(CURDATE(),'%Y-%m-01')),
  u.disk_alert, u.bw_alert, IFNULL(u.bw_alert_month,'-'), IFNULL(o.id,0), IFNULL(o.role,'-'),
  IF(IFNULL(br.name,'')='','-',br.name), IF(IFNULL(br.support_email,'')='','-',br.support_email), IF(IFNULL(br.support_url,'')='','-',br.support_url)
  FROM users u LEFT JOIN users o ON o.id=u.owner_id
  LEFT JOIN branding br ON br.reseller_id = IF(u.role='reseller', u.id, IF(o.role='reseller', o.id, 0))
  WHERE u.role != 'admin' AND u.status='active';")
