#!/usr/bin/env bash
# integrity-check.sh [--baseline] — tripwire-lite for the box's own brains:
# panel code, privileged scripts, and the configs that control mail, firewall
# and schedule. Anything changing outside the known writers (panel-update,
# ssl-issue, exim-acl-patch, post-update — all re-baseline after legit changes) means
# either a human edited by hand or something worse. State (root-only):
# /var/lib/karnakpanel/integrity.sha256. Default run compares and notifies;
# --baseline (re)seeds after intentional change; --rebase <file> refreshes ONE
# watched file's entry (panel toggles that edit /etc/cron.d/skypanel — glue
# check, backup schedule, restic lines — must not page the admin, but a full
# re-baseline from a click would also bless unrelated tampering).
set -euo pipefail
PASS="$(cat /root/.skypanel-dbpass 2>/dev/null || true)"
BASE="/var/lib/karnakpanel/integrity.sha256"
mkdir -p /var/lib/karnakpanel
FILES=(
  /usr/local/skypanel/panel/app
  /usr/local/skypanel/panel/public
  /usr/local/skypanel/panel/cli
  /usr/local/skypanel/panel/database
  /usr/local/skypanel/panel/VERSION
  /usr/local/skypanel/panel/config/config.sample.php
  /usr/local/skypanel/scripts
  /etc/exim4/exim4.conf
  /etc/fail2ban/jail.local
  /etc/cron.d/skypanel
  /usr/local/skypanel/update-pubkey.pem
  # upstream webmail code + its config (roundcube-upstream.sh re-baselines)
  /usr/local/skypanel/roundcube/current
  /etc/karnak-roundcube
  # calendar/contacts sync code copy + its config (dav-setup.sh re-baselines)
  /usr/local/skypanel/dav/current
  /etc/karnak-dav
  # release-upgrade pin + apt sources: root CAN undo the release-upgrade pin, but
  # not silently — any change here pages the admin the next morning
  /etc/update-manager/release-upgrades
  /etc/apt/sources.list
  /etc/apt/sources.list.d
)
if [[ "${1:-}" == "--rebase" ]]; then
  F="${2:?usage: integrity-check.sh --rebase <watched file>}"
  ok=0; for f in "${FILES[@]}"; do [[ "$f" == "$F" && ! -d "$f" ]] && ok=1; done
  (( ok )) || { echo "not a watched file: $F"; exit 1; }
  [[ -f "$BASE" ]] || { echo "no baseline yet — nothing to refresh"; exit 0; }
  if [[ -f "$F" ]]; then NEW="$(sha256sum "$F")"; else NEW="MISSING $F"; fi
  T="$(mktemp "$BASE.XXXXXX")"
  # exact string match on the path field (no regex) — the line for $F only
  awk -v f="$F" -v new="$NEW" '
    { p = ($1 == "MISSING") ? substr($0, 9) : substr($0, 67) }
    p == f { if (!done) print new; done = 1; next } { print }
    END { exit !done }' "$BASE" > "$T" || {
    # not in the baseline at all: appending would break the ordered compare
    rm -f "$T"; echo "$F not in baseline — run --baseline"; exit 1; }
  chmod 600 "$T"; mv -f "$T" "$BASE"
  echo "baseline refreshed for $F"
  exit 0
fi
TMP="$(mktemp)"
trap 'rm -f "$TMP"' EXIT
for f in "${FILES[@]}"; do
  # -L: panel/ and scripts/ are often symlinks into releases/<ver>/ — without
  # it find records the link itself and never sees the files (planted files
  # invisible). Printed paths keep the logical /usr/local/skypanel prefix,
  # so the manifest survives release swaps; the updater re-baselines anyway.
  if [[ -d "$f" ]]; then find -L "$f" -type f -print0 | sort -z | xargs -0 sha256sum 2>/dev/null >> "$TMP" || true
  elif [[ -f "$f" ]]; then sha256sum "$f" >> "$TMP"
  else echo "MISSING $f" >> "$TMP"
  fi
done
if [[ "${1:-}" == "--baseline" ]]; then
  cp -f "$TMP" "$BASE"; chmod 600 "$BASE"
  echo "baseline recorded ($(wc -l < "$BASE") entries)"
  exit 0
fi
[[ -f "$BASE" ]] || { echo "no baseline — run with --baseline first"; exit 1; }
if cmp -s "$BASE" "$TMP"; then
  echo "integrity OK"
else
  DIFF="$(diff "$BASE" "$TMP" | head -20 || true)"
  echo "INTEGRITY CHANGE:"
  echo "$DIFF"
  if [[ -n "$PASS" ]]; then
    SAFE="${DIFF//\\/\\\\}"; SAFE="${SAFE//\'/\'\'}"  # backslashes first: \' would escape the quote
    MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "INSERT INTO notifications(user_id,type,title,body) SELECT NULL,'integrity','Server files changed outside updates',LEFT('$SAFE',2000) FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM notifications WHERE user_id IS NULL AND type='integrity' AND created_at > (NOW() - INTERVAL 24 HOUR));" >>/var/log/karnakpanel/panel.log 2>&1 || echo "$(date -Is) integrity-check: notify insert failed" >>/var/log/karnakpanel/panel.log
  fi
  exit 1
fi
