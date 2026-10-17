#!/usr/bin/env bash
# service-watchdog.sh — restart critical units that died. Runs every 5 min via cron.
# Logs recoveries to the panel log. Never touches ssh/cron themselves blindly:
# restarting those is safe (no config change), so they're included — a dead
# cron is still revived on the NEXT tick by... nothing. If cron itself is dead,
# this script never runs: that case needs external monitoring. Included anyway
# for the common crash case (it costs nothing when healthy).
#
# Downtime email: one DOWN alert per outage episode + one RECOVERED mail, to
# every admin with a routable address (admin@localhost is skipped — it goes
# nowhere). Outage state lives in STATE_DIR/<unit> so repeats never re-mail.
# Test hooks: WATCHDOG_STATE_DIR + WATCHDOG_ALERT_TO (space-separated) override
# the live paths/recipients for a single manual run.
set -uo pipefail
LOG="/var/log/karnakpanel/panel.log"
STATE_DIR="${WATCHDOG_STATE_DIR:-/var/lib/skypanel/watchdog}"
mkdir -p "$STATE_DIR" 2>/dev/null || true
# liveness proof, checked by the panel (Services + Notifications banners) and
# optionally by the outside world: if cron dies, this file goes stale and the
# external monitor (healthchecks.io, Uptime Kuma, anything that pages when
# pings stop) is the only thing that can tell you. Set its URL via Admin >
# Services > watchdog heartbeat.
date +%s > /var/lib/karnakpanel/watchdog-last 2>/dev/null || true
HEARTBEAT="$(MYSQL_PWD="$(cat /root/.skypanel-dbpass 2>/dev/null)" mysql -uskypanel skypanel -N -e "SELECT value FROM settings WHERE name='watchdog_heartbeat';" 2>/dev/null || true)"
if [[ "$HEARTBEAT" =~ ^https?://[a-zA-Z0-9.-]+ ]]; then
  curl -fsSL --max-time 15 "$HEARTBEAT" >/dev/null 2>&1 || true
fi
HOST="$(hostname -f 2>/dev/null || hostname)"
# panel feed (best-effort: old installs may lack the table)
notify() {
  local P; P="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"; [[ -n "$P" ]] || return 0
  MYSQL_PWD="$P" mysql -uskypanel skypanel -e "INSERT INTO notifications(user_id,type,title) SELECT NULL,'service','$1' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM notifications WHERE user_id IS NULL AND type='service' AND title='$1' AND created_at > (NOW() - INTERVAL 1 HOUR));" >>/var/log/karnakpanel/panel.log 2>&1 || echo "$(date -Is) service-watchdog: notify insert failed" >>/var/log/karnakpanel/panel.log
}
# recipients: explicit alert_email setting first, else routable admin account
# addresses (seeded admin@localhost + blanks skipped). Test hook
# WATCHDOG_ALERT_TO (space-separated) wins over both for a single manual run.
recipients() {
  local P; P="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"; [[ -n "$P" ]] || return 0
  local explicit
  explicit="$(MYSQL_PWD="$P" mysql -uskypanel skypanel -N -e "SELECT value FROM settings WHERE name='alert_email';" 2>/dev/null \
    | grep -E '^[^@[:space:]]+@[^@[:space:]]+\.[a-z]{2,}$' | grep -vE '@localhost$' | head -1 || true)"
  if [[ -n "$explicit" ]]; then echo "$explicit"; return 0; fi
  MYSQL_PWD="$P" mysql -uskypanel skypanel -N -e "SELECT DISTINCT email FROM users WHERE role='admin';" 2>/dev/null \
    | grep -E '^[^@[:space:]]+@[^@[:space:]]+\.[a-z]{2,}$' | grep -vE '@localhost$' || true
}
send_alert() {
  local subject="$1" body="$2" to
  local TOS="${WATCHDOG_ALERT_TO:-$(recipients)}"
  [[ -n "${TOS// }" ]] || { echo "$(date -Is) watchdog: no routable admin email — alert logged only: $subject" >> "$LOG"; return 0; }
  for to in $TOS; do
    /usr/sbin/sendmail -t 2>/dev/null <<EOF || echo "$(date -Is) watchdog: sendmail to $to failed" >> "$LOG"
To: $to
From: KarnakPanel Watchdog <watchdog@$HOST>
Auto-Submitted: auto-generated
Subject: $subject

$body
EOF
  done
}
declare -A UNITS=( [lshttpd]=openlitespeed [mariadb]=mariadb [named]=bind9 [dovecot]=dovecot [exim4]=exim4 [vsftpd]=vsftpd [fail2ban]=fail2ban [cron]=cron [ssh]=ssh )
# spamd unit renamed across releases (spamassassin.service on 22.04,
# spamd.service on 24.04+) — register whichever exists, same systemctl-cat
# probe as clamav below (a hardcoded key reports phantom DOWNs otherwise)
# system disk: per-user quotas bound accounts, but nothing watched the box
# itself (full disk kills MariaDB first, then mail, logins, backups, renewals).
# Alert at 90% — hourly dedup via notify(), clears automatically below.
DISK_PCT="$(df -P / 2>/dev/null | awk 'NR==2{print $5}' | tr -d '%' || echo 0)"
if [[ "$DISK_PCT" =~ ^[0-9]+$ ]] && (( DISK_PCT >= 90 )); then
  echo "$(date -Is) watchdog: SYSTEM DISK ${DISK_PCT}% full" >> "$LOG"
  notify "system disk ${DISK_PCT}% full — MariaDB/mail/logins at risk, free space or grow the volume"
fi
# clamav only where the installer put it (opt-in): never report a missing daemon
if systemctl cat clamav-daemon >/dev/null 2>&1; then UNITS[clamav-daemon]=clamav; fi
if systemctl cat spamd >/dev/null 2>&1; then UNITS[spamd]=spamassassin; else UNITS[spamassassin]=spamassassin; fi
for unit in "${!UNITS[@]}"; do
  name="${UNITS[$unit]}"
  if systemctl is-active --quiet "$unit" 2>/dev/null; then
    if [[ -f "$STATE_DIR/$unit" ]]; then
      rm -f "$STATE_DIR/$unit"
      echo "$(date -Is) watchdog: RECOVERED $name ($unit)" >> "$LOG"
      notify "watchdog recovered $name"
      send_alert "[KarnakPanel $HOST] RECOVERED: $name" "Service $name ($unit) on $HOST is back up as of $(date -Is). No action needed."
    fi
    continue
  fi
  # confirm-before-restart: a single failed check can be a hiccup (reload,
  # log rotation, load spike) — killing a healthy server over it caused a
  # real outage 2026-10-09. Second reading 20s later must agree.
  if ! systemctl is-active --quiet "$unit" 2>/dev/null; then
    sleep 20
    systemctl is-active --quiet "$unit" 2>/dev/null && continue
  fi
  # down: alert once per episode, then attempt restart
  if [[ ! -f "$STATE_DIR/$unit" ]]; then
    touch "$STATE_DIR/$unit"
    echo "$(date -Is) watchdog: DOWN $name ($unit) — alerting" >> "$LOG"
    notify "watchdog DOWN $name"
    send_alert "[KarnakPanel $HOST] DOWN: $name" "Service $name ($unit) on $HOST was found down at $(date -Is). The watchdog is attempting a restart; you will get a RECOVERED mail when it is back."
  fi
  if systemctl restart "$unit" 2>/dev/null && systemctl is-active --quiet "$unit"; then
    echo "$(date -Is) watchdog: restarted $name ($unit)" >> "$LOG"
  else
    echo "$(date -Is) watchdog: FAILED to restart $name ($unit)" >> "$LOG"
  fi
done
