#!/usr/bin/env bash
# ssl-issue-bg.sh <domain> <email> — request a Let's Encrypt cert without
# holding the web request open (subdomain create). Starts a transient unit
# karnak-ssl-<domain> that runs ssl-issue.sh, then marks the row ssl_enabled
# and refreshes expiry. A domain deleted meanwhile has the fresh cert dropped
# again (ssl-remove.sh), so no orphan renewals. One unit per domain at a time.
# State per domain in /run/skypanel/ssl-bg/<domain> (root-only): running |
# ok | fail <reason>; `ssl-issue-bg.sh pending` lists them (Domains page
# spinner + its poll). Finished entries age out after an hour.
set -euo pipefail
STD=/run/skypanel/ssl-bg
if [[ "${1:-}" == "pending" ]]; then
  [[ -d "$STD" ]] || exit 0
  find "$STD" -maxdepth 1 -type f -mmin +60 -delete 2>/dev/null || true
  for f in "$STD"/*; do
    [[ -f "$f" ]] || continue
    n="$(basename "$f")"; [[ "$n" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || continue
    st="$(head -c 300 "$f" | tr -d '\t\r\n')"
    # unit gone but never wrote a result (killed, reboot): report it once
    if [[ "$st" == running ]] && ! systemctl is-active --quiet "karnak-ssl-${n//./-}"; then
      st="fail request stopped before finishing"; printf '%s' "$st" > "$f"
    fi
    printf '%s\t%s\t%s\n' "$n" "${st%% *}" "$( [[ "$st" == *" "* ]] && printf '%s' "${st#* }" )"
  done
  exit 0
fi
MODE=start
[[ "${1:-}" == "--run" ]] && { MODE=run; shift; }
D="${1:-}"; E="${2:-}"
[[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad domain"; exit 1; }
[[ "$E" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[a-z]{2,}$ ]] || { echo "refusing: ACME needs a real email (not $E)"; exit 1; }
HERE="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
UNIT="karnak-ssl-${D//./-}"
LOG=/var/log/karnakpanel/panel.log
log(){ echo "$(date -u +%FT%T+00:00) ssl-issue-bg: $*" >> "$LOG" 2>/dev/null || true; echo "$*"; }
q(){ MYSQL_PWD="$(cat /root/.skypanel-dbpass)" mysql -uskypanel skypanel -N -e "$1"; }
if [[ "$MODE" == start ]]; then
  systemctl is-active --quiet "$UNIT" && { echo "SSL request for $D already running"; exit 0; }
  systemctl reset-failed "$UNIT" 2>/dev/null || true
  install -d -m 700 "$STD"; printf running > "$STD/$D"
  systemd-run --unit="$UNIT" --collect --quiet -p RuntimeMaxSec=300 "$HERE/ssl-issue-bg.sh" --run "$D" "$E" || { rm -f "$STD/$D"; exit 1; }
  echo "SSL request for $D started in the background"
  exit 0
fi
# certbot allows one instance per box: a renewal (cron/timer) or another
# domain's request holding its lock fails ours instantly — retry for up to
# 2 min (then one last try inside RuntimeMaxSec: 120 + certbot's 150 < 300)
install -d -m 700 "$STD"; printf running > "$STD/$D"
trap 'printf "fail timed out" > "$STD/$D"; exit 1' TERM
T0=$SECONDS
while :; do
  OUT="$("$HERE/ssl-issue.sh" "$D" "$E" 2>&1)" && RC=0 || RC=$?
  (( RC != 0 && SECONDS - T0 < 120 )) && grep -q 'Another instance of Certbot is already running' <<<"$OUT" || break
  sleep 10
done
if (( RC == 0 )); then
  if [[ "$(q "SELECT COUNT(*) FROM domains WHERE domain='$D'")" == "0" ]]; then
    "$HERE/ssl-remove.sh" "$D" >/dev/null 2>&1 || true
    rm -f "$STD/$D"; log "$D was deleted while its cert was issued — cert dropped"; exit 0
  fi
  q "UPDATE domains SET ssl_enabled=1 WHERE domain='$D'"
  "$HERE/expiry-check.sh" "$D" >/dev/null 2>&1 || true
  printf ok > "$STD/$D"
  log "SSL issued for $D"
else
  # the real reason, not certbot's closing "Ask for help..." line
  WHY="$(grep -E 'Another instance|Detail:|[Ee]rror' <<<"$OUT" | grep -v '^\s*$' | tail -1 || true)"
  WHY="${WHY:-$(printf '%s' "$OUT" | grep -v '^\s*$' | tail -1)}"
  printf 'fail %s' "$(printf '%s' "$WHY" | tr -d '\t\r\n' | cut -c1-200)" > "$STD/$D"
  log "SSL not issued for $D (rc $RC): $WHY"
  exit 1
fi
