#!/usr/bin/env bash
# perms-fix.sh — keep the panel's code trees root-owned and non-writable by
# anyone else, and config.php (DB + provision creds) at root:nogroup 640.
# Root-run code must never be owned or writable by anyone else, and the
# credentials file must stay worker-group-only. This sweep is a self-healing
# backstop (cron, every 10 min) on top of the update/install paths, which
# set the same modes — it also covers boxes running an older updater.
# Runtime state (panel/storage) is deliberately left alone. Quiet unless it
# changes something. No args.
set -euo pipefail
SKY=/usr/local/skypanel
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
  if [[ "$(stat -c '%U:%G %a' "$c")" != "root:nogroup 640" ]]; then
    chown root:nogroup "$c"; chmod 640 "$c"; note "config locked: $c"; fixed=1
  fi
done < <(find "$SKY" -path '*/storage' -prune -o -path '*/config/config.php' -type f -print0 2>/dev/null)
# panel logs: per-domain access/error logs (visitor IPs, URLs with tokens),
# per-account PHP error logs and the panel's own logs were 644 — readable by
# every account's cron. Dir 711 (no listing; FPM pools still reach their own
# log), files never "other"-readable: root-owned ones become root:nogroup 640
# so the panel (nobody) still reads them; account-owned PHP logs get 600 +
# u:nobody:r. OLS-rolled files are new 644s: this 10-minute sweep re-closes.
LD=/var/log/karnakpanel
if [[ -d "$LD" && ! -L "$LD" ]]; then
  [[ "$(stat -c %a "$LD")" == 711 ]] || { chmod 711 "$LD"; note "log dir $LD now 711"; fixed=1; }
  n=0
  while IFS= read -r -d '' f; do
    o="$(stat -c %U "$f")"
    if [[ "$o" == root ]]; then chgrp nogroup "$f"; chmod 640 "$f"
    elif [[ "$f" == "$LD"/php-*.error.log ]]; then chmod 600 "$f"; setfacl -m u:nobody:r,m::r "$f" 2>/dev/null || true
    else chmod o-rwx "$f"
    fi
    n=$((n+1))
  done < <(find "$LD" -xdev -mindepth 1 -type f -perm /o=rwx -print0 2>/dev/null)
  (( n > 0 )) && { note "closed $n log file(s) in $LD to other accounts"; fixed=1; }
fi
(( fixed )) || echo "perms-fix: nothing to do"
