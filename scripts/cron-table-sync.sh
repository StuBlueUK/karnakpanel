#!/usr/bin/env bash
# cron-table-sync.sh <table> — merge a release cron table (cron/skypanel.cron)
# into /etc/cron.d/skypanel. Lines are keyed by their COMMAND (the token after
# the user field): a shipped script path or `certbot`. Missing keys append;
# a changed schedule for an existing key replaces that line only. Identical
# lines are untouched, and hand-managed lines under other keys are never
# touched. Lines outside the shipped shapes are refused even though the
# release is signed.
set -euo pipefail
TABLE="${1:?usage: cron-table-sync.sh <table>}"
CRON_FILE="${CRON_FILE:-/etc/cron.d/skypanel}"   # override only for tests
[[ -f "$TABLE" ]] || { echo "cron-sync: no table at $TABLE"; exit 0; }
CRONDIR="$(mktemp -d)"; trap 'rm -rf "$CRONDIR"' EXIT
touch "$CRON_FILE"
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
    grep -vE "root[[:space:]]+$(esc "$KEY")([[:space:]]|\$)" "$CRON_FILE" > "$CRONDIR/cur.cron" || true
    { cat "$CRONDIR/cur.cron"; printf '%s\n' "$line"; } > "$CRON_FILE"
    echo "cron-sync: schedule refreshed for $KEY ($sched)"
  else
    printf '%s\n' "$line" >> "$CRON_FILE" && echo "cron-sync: added $KEY ($sched)"
  fi
done < "$TABLE"
# cron ignores files not owned by root (WRONG FILE OWNER) — never leave it so
chown root:root "$CRON_FILE"; chmod 644 "$CRON_FILE"
