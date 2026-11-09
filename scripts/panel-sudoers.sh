#!/usr/bin/env bash
# panel-sudoers.sh [nobody|karnak-panel|both] — render /etc/sudoers.d/skypanel:
# the panel's PHP worker may run, as root, ONLY the scripts the installed
# panel code names — not every scripts/*.sh (installer, converge, transfer
# and other operator-only scripts were all reachable for the web uid).
# The allowlist is derived from the LIVE panel tree (root-owned, signed
# release): every quoted 'name.sh' literal under app/ + public/ that exists
# in scripts/. So a script the panel calls must appear in app/ as a literal
# 'x.sh' string (run() target, map value, probe arg) — a computed name is
# refused by sudo. No list to keep in sync: each update re-renders it.
# Who: default = the vhost's runtime user (panel-id.sh); `both` is only for
# the moment panel-user.sh switches the worker uid.
# Safe apply: candidate written beside the live file under a dotted name
# (sudo skips those), checked with visudo, then renamed over it — a broken
# include would kill sudo for the whole panel. Too few names = refuse and
# keep the old file. Idempotent; quiet when nothing changes.
set -euo pipefail
HERE="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
. "$HERE/panel-id.sh"
WHO="${1:-$PANEL_U}"
case "$WHO" in
  nobody|karnak-panel) USERS="$WHO" ;;
  both) USERS="nobody, karnak-panel" ;;
  *) echo "usage: panel-sudoers.sh [nobody|karnak-panel|both]"; exit 1 ;;
esac
[[ "$WHO" == nobody ]] || getent passwd karnak-panel >/dev/null || { echo "panel-sudoers: no karnak-panel user"; exit 1; }
SD=/usr/local/skypanel/scripts
APP=/usr/local/skypanel/panel
S=/etc/sudoers.d/skypanel
CMDS=()
while IFS= read -r n; do
  [[ "$n" =~ ^[a-z0-9-]+\.sh$ && -f "$SD/$n" ]] && CMDS+=("$SD/$n")
done < <(grep -rhoE "'[a-z0-9-]+\.sh'" "$APP/app" "$APP/public" --include='*.php' 2>/dev/null | tr -d "'" | sort -u)
# the panel calls ~150: a handful means a broken tree/grep, not a policy
(( ${#CMDS[@]} >= 50 )) || { echo "panel-sudoers: only ${#CMDS[@]} panel scripts found under $APP — refusing, $S left as is"; exit 1; }
TMP="$(mktemp /etc/sudoers.d/.skypanel.XXXXXX)"
trap 'rm -f "$TMP"' EXIT
{
  echo "# managed by $SD/panel-sudoers.sh — re-rendered on every update, do not edit"
  echo "# the panel worker may run these (and only these) panel scripts as root"
  echo "Cmnd_Alias KARNAK_PANEL = \\"
  for i in "${!CMDS[@]}"; do
    if (( i < ${#CMDS[@]} - 1 )); then echo "  ${CMDS[$i]}, \\"; else echo "  ${CMDS[$i]}"; fi
  done
  echo "$USERS ALL=(root) NOPASSWD: KARNAK_PANEL"
} > "$TMP"
visudo -cqf "$TMP" || { echo "panel-sudoers: candidate failed visudo — $S left as is"; exit 1; }
[[ -f "$S" ]] && cmp -s "$TMP" "$S" && exit 0
chmod 440 "$TMP"; chown root:root "$TMP"
mv -f "$TMP" "$S"
echo "panel-sudoers: $USERS may run ${#CMDS[@]} panel scripts as root"
