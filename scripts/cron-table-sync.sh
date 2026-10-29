#!/usr/bin/env bash
# cron-table-sync.sh <table> — merge a release cron table (cron/skypanel.cron)
# into /etc/cron.d/skypanel. Lines are keyed by their COMMAND (the token after
# the user field): a shipped script path or `certbot`. Missing keys append;
# a changed schedule for an existing key replaces that line only. Identical
# lines are untouched, and hand-managed lines under other keys are never
# touched. Lines outside the shipped shapes are refused even though the
# release is signed.
# ADMIN_KEYS: the admin sets these from the panel (Backups page time/disable,
# setup-guide dismiss of the glue check; off = commented line) — once any
# line for the key exists, active or commented, it is theirs.
# RETIRED_KEYS: scripts dropped from the table; their old lines are removed
# (only this exact root line shape — hand-managed lines stay).
# Writes go to a dotted temp name in /etc/cron.d (cron ignores dotted names)
# and are mv'd into place, so cron never reads a half-written table.
set -euo pipefail
TABLE="${1:?usage: cron-table-sync.sh <table>}"
CRON_FILE="${CRON_FILE:-/etc/cron.d/skypanel}"   # override only for tests
[[ -f "$TABLE" ]] || { echo "cron-sync: no table at $TABLE"; exit 0; }
CRONDIR="$(mktemp -d)"; trap 'rm -rf "$CRONDIR"' EXIT
touch "$CRON_FILE"
ADMIN_KEYS=(/usr/local/skypanel/scripts/backup-all.sh /usr/local/skypanel/scripts/dns-glue-check.sh)
RETIRED_KEYS=(/usr/local/skypanel/scripts/quota-check.sh)
TMP_FILE="$(dirname "$CRON_FILE")/.$(basename "$CRON_FILE").sync-$$"
put() { cat > "$TMP_FILE"; chown root:root "$TMP_FILE" 2>/dev/null || true; chmod 644 "$TMP_FILE"; mv -f "$TMP_FILE" "$CRON_FILE"; }
CERT_CMD='certbot renew --quiet --post-hook "/usr/local/skypanel/scripts/ssl-renew-hook.sh" >>/var/log/karnakpanel/certbot.log 2>&1'
# command = everything after: five schedule fields + the user field
cmd_of() { printf '%s\n' "$1" | sed -E 's/^([^[:space:]]+[[:space:]]+){5}root[[:space:]]+//'; }
esc()    { printf '%s' "$1" | sed -E 's/[].[^$*+?(){}|\\/]/\\&/g'; }
while IFS= read -r line; do
  case "$line" in \#*|'') continue;; esac
  CMD="$(cmd_of "$line")"
  if [[ "$line" =~ ^[0-9*,/-]+([[:space:]]+[0-9*,/-]+){4}[[:space:]]+root[[:space:]]+/usr/local/skypanel/scripts/[a-zA-Z0-9_.-]+\.sh([[:space:]]|$) ]]; then
    KEY="${CMD%% *}"
  elif [[ "$CMD" == "$CERT_CMD" ]]; then
    KEY="certbot"
  else
    echo "cron-sync: REFUSED non-shipped shape: $(printf '%s' "$line" | cut -c1-80)"; continue
  fi
  sched="$(printf '%s\n' "$line" | awk '{print $1,$2,$3,$4,$5}')"
  if grep -qxF -- "$line" "$CRON_FILE"; then continue; fi
  if grep -qE "root[[:space:]]+$(esc "$KEY")([[:space:]]|\$)" "$CRON_FILE"; then
    for k in "${ADMIN_KEYS[@]}"; do [[ "$KEY" == "$k" ]] && continue 2; done
    grep -vE "root[[:space:]]+$(esc "$KEY")([[:space:]]|\$)" "$CRON_FILE" > "$CRONDIR/cur.cron" || true
    { cat "$CRONDIR/cur.cron"; printf '%s\n' "$line"; } | put
    echo "cron-sync: schedule refreshed for $KEY ($sched)"
  else
    { cat "$CRON_FILE"; printf '%s\n' "$line"; } | put
    echo "cron-sync: added $KEY ($sched)"
  fi
done < "$TABLE"
for k in "${RETIRED_KEYS[@]}"; do
  RE="^[0-9*,/-]+([[:space:]]+[0-9*,/-]+){4}[[:space:]]+root[[:space:]]+$(esc "$k")([[:space:]]|\$)"
  grep -qE "$RE" "$CRON_FILE" || continue
  { grep -vE "$RE" "$CRON_FILE" || true; } | put
  echo "cron-sync: retired $k"
done
# cron ignores files not owned by root (WRONG FILE OWNER) — never leave it so
(( EUID != 0 )) || chown root:root "$CRON_FILE"; chmod 644 "$CRON_FILE"
