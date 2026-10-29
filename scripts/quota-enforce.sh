#!/usr/bin/env bash
# quota-enforce.sh — hard disk-quota backstop (kernel quotas unavailable: LXC
# and VPS mounts are host-owned, so no usrquota). Two locks, both self-healing:
#   over quota (active, non-admin) -> FTP dropped from userlist (530s) AND the
#     FPM pool file renamed aside + FPM reloaded (site goes offline: no reads,
#     no writes, no PHP at all — a chmod 555 was tried first and REJECTED: it
#     only blocks writes directly in home while public_html/wp-content stay
#     open). Virtual mail is outside homes so inbound mail is unaffected.
#   back under quota (active)     -> FTP re-added, pool restored + reloaded
# Suspended users are NEVER touched (suspend owns their shell; unsuspend must
# not silently flip FTP). Admins are exempt like in Helpers::diskBlocked().
# Runs every 5 min — worst case is ~5 min of over-quota writes, stated in docs.
# Known wart: a PHP-version switch mid-suspension can orphan an idle pool file
# in the old version dir (ondemand, zero traffic, harmless).
# SCALE NOTE (phase 2 trigger): at ~500+ accounts, or when the pass-duration
# line at the bottom trends toward minutes, revisit: mtime fast-path to skip
# unchanged homes + mail sizes via Dovecot accounting instead of du. Deliberately
# NOT built yet — the fast-path trades ground-truth-every-tick for derived
# truth (auditable, but harder to defend in quota disputes).
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/php-versions.sh"
# Single-flight: a late tick exits instead of stacking onto a slow pass
# (flock is kernel-held — a killed run can never jam future runs).
exec 9>/var/lock/skypanel-quota-enforce.lock
flock -n 9 || { echo "quota-enforce: previous pass still running, skipping this tick"; exit 0; }
# Idle-priority measurement: counting bytes must never starve customer traffic.
ionice -c3 -p $$ 2>/dev/null || true
renice +10 -p $$ >/dev/null 2>&1 || true
START=$SECONDS
PASS="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
[[ -n "$PASS" ]] || { echo "no panel db pass"; exit 1; }
UL="/etc/vsftpd.userlist"
touch "$UL"
notify() { # notify <username> <title> (hourly dedup, best-effort)
  local P; P="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"; [[ -n "$P" ]] || return 0
  MYSQL_PWD="$P" mysql -uskypanel skypanel -e "INSERT INTO notifications(user_id,type,title) SELECT (SELECT id FROM users WHERE username='$1'),'quota','$2' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM notifications n JOIN users u ON u.id=n.user_id WHERE u.username='$1' AND n.type='quota' AND n.title='$2' AND n.created_at > (NOW() - INTERVAL 1 HOUR));" >>/var/log/karnakpanel/panel.log 2>&1 || echo "$(date -Is) quota-enforce: notify insert failed" >>/var/log/karnakpanel/panel.log
}
# pool suspend/restore: rename aside (FPM only reads *.conf) + reload only the
# versions that changed. Restore renames back every .suspended found.
pool_flip() { # $1=user $2=suspend|restore — echoes 1 if anything changed
  # NOTE: one local per line — a single 'local a=x b=$a' line reads $a BEFORE
  # assignment and dies on set -u
  local U="$1"
  local MODE="$2"
  local changed=0
  local dir
  local vers=()
  while IFS= read -r dir; do
    local live="$dir/skypanel-$U.conf"
    local park="$dir/skypanel-$U.conf.suspended"
    if [[ "$MODE" == "suspend" && -f "$live" ]]; then mv "$live" "$park" && changed=1
    elif [[ "$MODE" == "restore" && -f "$park" ]]; then mv "$park" "$live" && changed=1
    else continue
    fi
    vers+=("${dir:9:3}")
  done < <(php_pool_dirs)
  if (( changed )); then
    for OV in "${vers[@]}"; do systemctl reload "php$OV-fpm" 2>/dev/null || true; done
  fi
  echo "$changed"
}
while IFS=$'\t' read -r U Q ROLE ST; do
  [[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || continue
  [[ "$ST" == "active" && "$ROLE" != "admin" ]] || continue
  id "$U" >/dev/null 2>&1 || continue
  # Per-user timeout: one giant tree must not stall every account behind it.
  # On timeout the user is SKIPPED (last DB total stands) — never zeroed: a
  # zeroed total would under-enforce AND wipe the display cache. timeout 124
  # = the du itself hung; anything else failing also skips (fail safe = keep
  # last known state, log it, move on).
  home_raw=""; rc=0
  # NOTE: the `|| rc=$?` is load-bearing — without it, set -e kills the whole
  # script on the first timeout instead of skipping one user (same class as
  # the ((n++)) and while-read-EOF traps fixed elsewhere)
  home_raw=$(timeout 60 du -sm "/home/$U" 2>/dev/null | cut -f1) || rc=$?
  if [[ $rc -ne 0 || -z "${home_raw:-}" ]]; then
    echo "$(date -Is) quota-enforce: measure timed out/failed for $U (rc=$rc), keeping last total" | tee -a /var/log/karnakpanel/panel.log
    continue
  fi
  home=$home_raw
  mail=0
  while IFS= read -r d; do
    [[ -n "$d" ]] || continue
    m=$(timeout 60 du -sm "/var/mail/virtual/$d" 2>/dev/null | cut -f1 || echo 0)
    mail=$((mail + ${m:-0}))
  done < <(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT d.domain FROM domains d JOIN users u ON u.id=d.user_id WHERE u.username='$U';" 2>/dev/null)
  total=$((home + mail))
  # feed the display cache: every enforcement tick stores fresh numbers so
  # pages read one indexed query instead of walking trees (worst case 5 min
  # stale; enforcement itself always uses the live $total above, never this)
  MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "UPDATE users SET disk_used_mb=$total, disk_measured_at=NOW() WHERE username='$U';" 2>/dev/null || true
  if [[ "$Q" -gt 0 && "$total" -ge "$Q" ]]; then
    if grep -qxF "$U" "$UL" 2>/dev/null; then
      sed -i "/^$U\$/d" "$UL"
      echo "$(date -Is) quota-enforce: FTP locked for $U (${total}MB >= ${Q}MB)" | tee -a /var/log/karnakpanel/panel.log
      notify "$U" "FTP locked: disk quota exceeded (${total}MB of ${Q}MB)"
    fi
    # PHP lock: pool aside = site fully offline (no reads, no writes)
    if [[ "$(pool_flip "$U" suspend)" == "1" ]]; then
      echo "$(date -Is) quota-enforce: site suspended for $U (${total}MB >= ${Q}MB)" | tee -a /var/log/karnakpanel/panel.log
      notify "$U" "Site offline: disk quota exceeded (${total}MB of ${Q}MB) — delete files/mail/backups to bring it back"
    fi
  else
    if ! grep -qxF "$U" "$UL" 2>/dev/null; then
      echo "$U" >> "$UL"; sort -u "$UL" -o "$UL"
      echo "$(date -Is) quota-enforce: FTP restored for $U" | tee -a /var/log/karnakpanel/panel.log
      notify "$U" "FTP restored: back under disk quota"
    fi
    if [[ "$(pool_flip "$U" restore)" == "1" ]]; then
      echo "$(date -Is) quota-enforce: site restored for $U" | tee -a /var/log/karnakpanel/panel.log
      notify "$U" "Site back online: back under disk quota"
    fi
  fi
done < <(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT username, disk_quota_mb, role, status FROM users;" 2>/dev/null)
# Phase-2 trigger metric: watch this line — when a pass trends toward minutes
# (or the fleet passes ~500 accounts), build the mtime fast-path (see SCALE
# NOTE at the top).
echo "$(date -Is) quota-enforce: pass took ${SECONDS}s" >> /var/log/karnakpanel/panel.log
# 80%/95% early warnings from the totals just cached (never fails the pass)
"$(dirname "$(readlink -f "$0")")/usage-alerts.sh" >/dev/null 2>&1 || true
