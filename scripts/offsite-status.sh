#!/usr/bin/env bash
# offsite-status.sh — TSV for the Backups page: configured, provider, target,
# last sync line, remote file count + size. Panel runs as nobody; this runs
# privileged (root-only rclone.conf). Never prints secrets.
set -euo pipefail
RCLONE="$(command -v rclone || echo /usr/bin/rclone)"
CONF="/root/.config/rclone/rclone.conf"
LOG="/var/log/karnakpanel/offsite.log"
if [[ ! -f "$CONF" ]] || ! grep -q '^\[offsite-crypt\]' "$CONF" 2>/dev/null; then
  echo -e "no\t\t\t\t\t"
  exit 0
fi
PROV=""; TGT=""
if [[ -f /root/.skypanel-dbpass ]]; then
  P="$(cat /root/.skypanel-dbpass)"
  PROV="$(MYSQL_PWD="$P" mysql -uskypanel skypanel -N -e "SELECT value FROM settings WHERE name='offsite_provider';" 2>/dev/null || true)"
  TGT="$(MYSQL_PWD="$P" mysql -uskypanel skypanel -N -e "SELECT value FROM settings WHERE name='offsite_target';" 2>/dev/null || true)"
fi
LAST="$(grep -E "sync (OK|FAILED)" "$LOG" 2>/dev/null | tail -1 || true)"
COUNT=""; SIZE=""
if SZOUT="$("$RCLONE" --config "$CONF" size offsite-crypt: 2>/dev/null)"; then
  COUNT="$(echo "$SZOUT" | grep -oP 'Total objects: \K[0-9]+' || true)"
  SIZE="$(echo "$SZOUT" | grep -oP 'Total size: \K.*' | head -1 || true)"
fi
printf 'yes\t%s\t%s\t%s\t%s\t%s\n' "$PROV" "$TGT" "$LAST" "$COUNT" "$SIZE"
