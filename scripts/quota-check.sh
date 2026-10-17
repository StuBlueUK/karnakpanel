#!/usr/bin/env bash
# quota-check.sh — per-user disk report (home + virtual mail), flags over-quota.
# MANUAL USE ONLY (deliberately not in cron/skypanel.cron — output goes
# nowhere and quota-enforce.sh already measures + caches + enforces).
# Consistency rule: Helpers::quota() measures the same two trees, so the
# panel's hard gate and this report can never disagree about who's over.
set -euo pipefail
PASS="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
for h in /home/*; do
  [[ -d "$h" ]] || continue
  U=$(basename "$h")
  [[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || continue
  usage=$(du -sm "$h" 2>/dev/null | cut -f1)
  mail=0
  if [[ -n "$PASS" ]]; then
    while IFS= read -r d; do
      [[ -n "$d" ]] || continue
      m=$(du -sm "/var/mail/virtual/$d" 2>/dev/null | cut -f1 || echo 0)
      mail=$((mail + ${m:-0}))
    done < <(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT d.domain FROM domains d JOIN users u ON u.id=d.user_id WHERE u.username='$U';" 2>/dev/null)
  fi
  total=$((usage + mail))
  q=$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT disk_quota_mb FROM users WHERE username='$U';" 2>/dev/null || echo 0)
  flag=""; [[ "$q" -gt 0 && "$total" -ge "$q" ]] && flag=" OVER-QUOTA"
  echo "$U: home=${usage}MB mail=${mail}MB total=${total}MB quota=${q}MB$flag"
done
