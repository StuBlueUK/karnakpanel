#!/usr/bin/env bash
# perms-fix.sh — keep the panel's code trees root-owned and non-writable by
# anyone else, and config.php (DB + provision creds) at root:<worker group> 640.
# Root-run code must never be owned or writable by anyone else, and the
# credentials file must stay worker-group-only. This sweep is a self-healing
# backstop (cron, every 10 min) on top of the update/install paths, which
# set the same modes — it also covers boxes running an older updater.
# Runtime state (panel/storage) is deliberately left alone. Quiet unless it
# changes something. No args.
set -euo pipefail
SKY=/usr/local/skypanel
# PANEL_U/PANEL_G: the panel worker (nobody, or karnak-panel after panel-user.sh);
# web-acl.sh sources panel-id.sh and gives acl_panel_only (who reads logs)
. "$(dirname "$(readlink -f "$0")")/web-acl.sh"
LOG=/var/log/karnakpanel/panel.log
fixed=0
note() { echo "$(date -Is) perms-fix: $*" >> "$LOG" 2>/dev/null || true; echo "$*"; }
TREES=()
[[ -d "$SKY/releases" ]] && TREES+=("$SKY/releases")
# upstream webmail code: root-owned, read-only to its karnak-webmail pool
[[ -d "$SKY/roundcube" ]] && TREES+=("$SKY/roundcube")
# pre-release layouts: live dirs are real directories, not symlinks
for t in "$SKY/panel" "$SKY/scripts"; do [[ -d "$t" && ! -L "$t" ]] && TREES+=("$t"); done
for T in "${TREES[@]}"; do
  n="$(find "$T" -path '*/storage' -prune -o \! -user root -print | wc -l)"
  if (( n > 0 )); then
    find "$T" -path '*/storage' -prune -o \! -user root -print0 | xargs -0 -r chown -h root:root
    note "chowned $n non-root entries under $T"; fixed=1
  fi
  n="$(find "$T" -path '*/storage' -prune -o \( -perm -g+w -o -perm -o+w \) \! -type l -print | wc -l)"
  if (( n > 0 )); then
    find "$T" -path '*/storage' -prune -o \( -perm -g+w -o -perm -o+w \) \! -type l -print0 | xargs -0 -r chmod go-w
    note "removed group/world write from $n entries under $T"; fixed=1
  fi
done
while IFS= read -r -d '' c; do
  if [[ "$(stat -c '%U:%G %a' "$c")" != "root:$PANEL_G 640" ]]; then
    chown root:"$PANEL_G" "$c"; chmod 640 "$c"; note "config locked: $c"; fixed=1
  fi
done < <(find "$SKY" -path '*/storage' -prune -o -path '*/config/config.php' -type f -print0 2>/dev/null)
# panel logs: per-domain access/error logs (visitor IPs, URLs with tokens),
# per-account PHP error logs and the panel's own logs were 644 — readable by
# every account's cron. Dir 711 (no listing; FPM pools still reach their own
# log), files never "other"-readable: root-owned ones become root:nogroup 640
# so the panel worker (nobody, or karnak-panel via its nogroup membership)
# still reads them; account-owned PHP logs get 600 + a panel read ACL
# (acl_panel_only: u:karnak-panel:r once the panel left nobody, which then
# loses its entry — OLS never reads them). OLS-rolled files are new 644s: this 10-minute sweep re-closes.
LD=/var/log/karnakpanel
if [[ -d "$LD" && ! -L "$LD" ]]; then
  [[ "$(stat -c %a "$LD")" == 711 ]] || { chmod 711 "$LD"; note "log dir $LD now 711"; fixed=1; }
  n=0
  while IFS= read -r -d '' f; do
    o="$(stat -c %U "$f")"
    if [[ "$o" == root ]]; then chgrp nogroup "$f"; chmod 640 "$f"
    elif [[ "$f" == "$LD"/php-*.error.log ]]; then chmod 600 "$f"; acl_panel_only "$f"
    else chmod o-rwx "$f"
    fi
    n=$((n+1))
  done < <(find "$LD" -xdev -mindepth 1 -type f -perm /o=rwx -print0 2>/dev/null)
  (( n > 0 )) && { note "closed $n log file(s) in $LD to other accounts"; fixed=1; }
  # PHP logs already closed: nobody's entry follows the panel (dropped once
  # split, back when the panel returns to nobody — panel-user.sh revert)
  n=0
  for f in "$LD"/php-*.error.log; do
    [[ -f "$f" && ! -L "$f" ]] || continue
    if getfacl -cp -- "$f" 2>/dev/null | grep -q '^user:nobody:'; then has=1; else has=0; fi
    (( has == WEB_ACL_SPLIT )) || continue
    acl_panel_only "$f"; n=$((n+1))
  done
  (( n > 0 )) && { note "PHP logs: panel read ACL re-set on $n file(s)"; fixed=1; }
  # the panel's own log is written by the worker (Helpers::log is @-silenced:
  # a wrong owner = a silently blind panel fail2ban jail)
  PL="$LD/panel.log"
  if [[ -f "$PL" && ! -L "$PL" && "$(stat -c %U "$PL")" != "$PANEL_U" ]]; then
    chown "$PANEL_U:$PANEL_G" "$PL"; note "panel.log owner now $PANEL_U"; fixed=1
  fi
fi
(( fixed )) || echo "perms-fix: nothing to do"
