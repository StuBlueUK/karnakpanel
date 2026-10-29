#!/usr/bin/env bash
# cron-wrap.sh <jobid> [command...] — run a user cron job with logging.
# Appends START/END/EXIT lines + capped output to ~/.skypanel-cron/<id>.log
# (panel-readable via ACL) so the Cron page shows last-run state.
# The command comes on fd 3 from cron-run.sh (kept out of argv, which every
# account can read via ps); a command in argv is the legacy crontab form.
set -uo pipefail
ID="${1:?}"; shift || true
[[ "$ID" =~ ^[0-9]{1,10}$ ]] || { echo "bad job"; exit 1; }
if (( $# == 0 )) && { true <&3; } 2>/dev/null; then
  KP_CMD="$(cat <&3)"; exec 3<&-
else
  KP_CMD="$*"
fi
export KP_CMD
# temp files in the account's private ~/.tmp (umask stays 022: a file made
# elsewhere then mv'd into a docroot needs "other" for the web server)
[[ -d "$HOME/.tmp" && ! -L "$HOME/.tmp" ]] && export TMPDIR="$HOME/.tmp"
D="$HOME/.skypanel-cron"
# acl_panel_only: the panel worker's read on the log (web-acl.sh)
. "$(dirname "$(readlink -f "$0")")/web-acl.sh"
# root's verdict from cron-run.sh (as the account, panel-id.sh can't read the
# panel vhost and always falls back to nobody)
[[ "${KP_WEB_SPLIT:-}" =~ ^[01]$ ]] && WEB_ACL_SPLIT="$KP_WEB_SPLIT"
mkdir -p "$D" 2>/dev/null || exit 0
LOG="$D/$ID.log"
{
  echo "=== START $(date -Is) ==="
  # eval of the env var: the command never appears in bash's argv either
  out=$(bash -c 'eval "$KP_CMD"' 2>&1); code=$?
  echo "${out:0:8192}"
  echo "=== END exit=$code $(date -Is) ==="
} >> "$LOG" 2>/dev/null || true
# chmod first (on an ACL file it sets the mask), then the ACL entry resets it
chmod 600 "$LOG" 2>/dev/null || true
acl_panel_only "$LOG"
exit 0
