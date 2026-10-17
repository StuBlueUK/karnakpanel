#!/usr/bin/env bash
# session-list.sh — list panel sessions (runs as root: the sessions dir is
# drwx-wx-wt, i.e. listable only by root by design). Tab-separated:
# sid, username, role, last-activity-epoch, auth (1 = logged in; 0 = a
# pre-login visit holding only the login form's CSRF token — every login
# page hit/health check makes one, PHP's session GC expires them).
set -euo pipefail
# the PANEL's PHP (skypanel-php -> its lsphp) decides where its sessions live
DIR="$(/usr/bin/skypanel-php -r 'echo ini_get("session.save_path");' 2>/dev/null || echo /var/lib/php/sessions)"
[[ -d "$DIR" ]] || DIR="/var/lib/php/sessions"
for f in "$DIR"/sess_*; do
  [[ -f "$f" ]] || continue
  sid="${f##*/sess_}"
  [[ "$sid" =~ ^[a-z0-9,-]{22,128}$ ]] || continue
  user="$(grep -aoP 's:8:"username";s:\d+:"\K[^"]+' "$f" 2>/dev/null | head -1 || true)"
  role="$(grep -aoP 's:4:"role";s:\d+:"\K[^"]+' "$f" 2>/dev/null | head -1 || true)"
  mt="$(stat -c %Y "$f" 2>/dev/null || echo 0)"
  auth=0; grep -aq '\(^\|[;}]\)user|' "$f" 2>/dev/null && auth=1
  printf '%s\t%s\t%s\t%s\t%s\n' "$sid" "${user:-?}" "${role:-?}" "$mt" "$auth"
done
