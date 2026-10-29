#!/usr/bin/env bash
# update-check.sh — daily "is there a newer release?" probe (admin dashboard +
# update banners). Compares local panel/VERSION against downloads/VERSION with
# sort -V; severity comes from the newest CHANGELOG section (mentions of
# "security" = urgent, shown red until installed). Silent offline (no network
# = no notice, never an error). State: /var/lib/karnakpanel/update-check
# ("<local> <remote> <ok|urgent|info|unreachable>"). Runs daily by cron AND on
# demand from the Update page.
set -euo pipefail
BASE="${KARNAK_BASE_URL:-https://karnakpanel.com/downloads}"
STATE_DIR="/var/lib/karnakpanel"
STATE="$STATE_DIR/update-check"
mkdir -p "$STATE_DIR"
LOCAL="$(cat /usr/local/skypanel/panel/VERSION 2>/dev/null | tr -cd 'a-zA-Z0-9._-' | head -c 32 || true)"
[[ -n "$LOCAL" ]] || { echo "no local version"; exit 0; }
# post-update convergence, once per installed version: older updaters call
# this script (from the NEW release) right after swapping, so this is how
# system-level changes reach boxes whose updater predates them. Retried
# daily until it succeeds; never blocks the version probe.
if [[ "$(cat "$STATE_DIR/converged" 2>/dev/null)" != "$LOCAL" && -x "$(dirname "$(readlink -f "$0")")/post-update.sh" ]]; then
  "$(dirname "$(readlink -f "$0")")/post-update.sh" >> /var/log/karnakpanel/update.log 2>&1 || true
fi
REMOTE="$(curl -fsSL --max-time 10 "$BASE/VERSION" 2>/dev/null | tr -cd 'a-zA-Z0-9._-' | head -c 32 || true)"
if [[ -z "$REMOTE" ]]; then
  echo "$LOCAL ? unreachable" > "$STATE"; echo "unreachable"; exit 0
fi
if [[ "$(printf '%s\n%s\n' "$LOCAL" "$REMOTE" | sort -V | tail -1)" == "$LOCAL" ]]; then
  echo "$LOCAL $REMOTE ok" > "$STATE"; echo "current ($LOCAL)"; exit 0
fi
# behind: severity from the newest changelog section (first ~15 lines)
SEV="info"
# capture, then grep: head exiting early SIGPIPEs curl once the file outgrows a pipe buffer
CL="$(curl -fsSL --max-time 10 "$BASE/CHANGELOG.md" 2>/dev/null || true)"
if grep -qi "security" <<<"$(head -15 <<<"$CL")"; then
  SEV="urgent"
fi
echo "$LOCAL $REMOTE $SEV" > "$STATE"
echo "update available: $REMOTE ($SEV)"
