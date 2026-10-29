#!/usr/bin/env bash
# symlink-guard.sh [all|fpm] — converge the cross-account symlink defences.
# OLS reads every home as nobody, so a customer symlink pointing into another
# account's files would be served by the customer's own site. Customers have
# no shell; the only ways they can create links are PHP (FPM) and cron, so:
#  1. fpm:  systemd drop-in on every php*-fpm unit — symlink/symlinkat and
#           io_uring_* fail with EPERM in the master and all pool workers
#           (+ anything they spawn: putenv/mail() LD_PRELOAD tricks included).
#           The master runs as root, so no no_new_privs is implied (setuid
#           exim keeps working for mail()). Changed units are restarted.
#  2. vhosts: customer vhosts (docRoot under /home) get allowSymbolLink 2 —
#           OLS serves a link only when link and target share an owner. OLS
#           checks only the LAST path component, which is why 1 is needed.
#  3. cron: customer jobs moved from per-user crontabs to root-launched
#           cron-run.sh (seccomp as root, then drop to the user), driven by
#           the DB's active jobs; cron-sync drops the old crontab only after
#           the new set is written.
#  1b. fpm-sandbox.sh: the rest of the FPM systemd sandbox (see there).
# Idempotent; quiet when nothing changes. Called by host-converge.sh (all),
# php-install.sh (fpm) and the installer (all).
set -uo pipefail
MODE="${1:-all}"
[[ "$MODE" == all || "$MODE" == fpm ]] || { echo "usage: $0 [all|fpm]"; exit 1; }
HERE="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
rc=0

# ---- 1. FPM units ----
DROP='# managed by symlink-guard.sh — customer PHP may not create symlinks
[Service]
SystemCallFilter=~symlink symlinkat io_uring_setup io_uring_enter io_uring_register
SystemCallErrorNumber=EPERM
SystemCallArchitectures=native'
CHANGED=()
while read -r UNIT _; do
  [[ "$UNIT" =~ ^php8\.[0-9]+-fpm\.service$ ]] || continue
  F="/etc/systemd/system/$UNIT.d/karnak-nosymlink.conf"
  [[ "$(cat "$F" 2>/dev/null)" == "$DROP" ]] && continue
  mkdir -p "${F%/*}" && printf '%s\n' "$DROP" > "$F" && chmod 644 "$F" && CHANGED+=("$UNIT") || rc=1
done < <(systemctl list-unit-files 'php*-fpm.service' --no-legend 2>/dev/null)
if (( ${#CHANGED[@]} )); then
  systemctl daemon-reload || rc=1
  for UNIT in "${CHANGED[@]}"; do
    if systemctl is-active --quiet "$UNIT"; then
      if systemctl restart "$UNIT" && systemctl is-active --quiet "$UNIT"; then
        echo "symlink-guard: $UNIT may no longer create symlinks (restarted)"
      else
        echo "symlink-guard: $UNIT FAILED to restart with the no-symlink filter — removing it"
        rm -f "/etc/systemd/system/$UNIT.d/karnak-nosymlink.conf"; systemctl daemon-reload
        systemctl restart "$UNIT" || true; rc=1
      fi
    else
      echo "symlink-guard: $UNIT filter installed (unit not running)"
    fi
  done
fi
# the rest of the FPM sandbox (own drop-in, own rollback)
"$HERE/fpm-sandbox.sh" || rc=1
[[ "$MODE" == fpm ]] && exit $rc

# ---- 2. customer vhosts: allowSymbolLink 2 ----
C=/usr/local/lsws/conf/httpd_config.conf
if [[ -f "$C" ]]; then
  HOMEVH=()
  for VC in /usr/local/lsws/conf/vhosts/*/vhost.conf; do
    [[ -f "$VC" ]] || continue
    grep -qE '^docRoot[[:space:]]+/home/' "$VC" && HOMEVH+=("$(basename "${VC%/vhost.conf}")")
  done
  if (( ${#HOMEVH[@]} )); then
    T="$(mktemp)"
    awk -v list="$(printf '%s\n' "${HOMEVH[@]}")" '
      BEGIN { n = split(list, a, "\n"); for (i = 1; i <= n; i++) if (a[i] != "") want[a[i]] = 1 }
      /^virtualhost [^ ]+ \{$/ { cur = $2; inb = 1 }
      inb && /^}/ { inb = 0 }
      inb && (cur in want) && /^[[:space:]]*allowSymbolLink[[:space:]]+1[[:space:]]*$/ { sub(/1[[:space:]]*$/, "2"); fixed++ }
      { print }
      END { print fixed + 0 > "/dev/stderr" }' "$C" > "$T" 2>"$T.n"
    N="$(cat "$T.n")"
    if [[ "${N:-0}" != 0 ]] && [[ -s "$T" ]]; then
      cp -a "$C" "$C.pre-symlinkguard"
      cat "$T" > "$C"
      if /usr/local/lsws/bin/lswsctrl restart >/dev/null 2>&1; then
        echo "symlink-guard: $N customer vhost(s) now serve symlinks only when owner matches"
      else
        cat "$C.pre-symlinkguard" > "$C"; /usr/local/lsws/bin/lswsctrl restart >/dev/null 2>&1
        echo "symlink-guard: lsws restart failed — vhost config restored"; rc=1
      fi
      rm -f "$C.pre-symlinkguard"
    fi
    rm -f "$T" "$T.n"
  fi
fi

# ---- 3. customer cron onto cron-run.sh ----
PASS=$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")
if [[ -n "$PASS" ]]; then
  CU="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT DISTINCT u.username FROM cron_jobs j JOIN users u ON u.id=j.user_id;" 2>/dev/null)" || CU=""
  # plus anyone still holding a legacy crontab that names the wrapper
  for f in /var/spool/cron/crontabs/*; do
    [[ -f "$f" ]] && grep -q 'skypanel/scripts/cron-wrap.sh' "$f" && CU+=$'\n'"$(basename "$f")"
  done
  while read -r U; do
    [[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || continue
    id "$U" >/dev/null 2>&1 || continue
    if [[ -f "/var/spool/cron/crontabs/$U" ]] || ! [[ -d "/var/lib/karnakpanel/cron/$U" ]]; then
      OUT="$("$HERE/cron-sync.sh" "$U" 2>&1)" && echo "symlink-guard: cron jobs for $U moved to cron-run" \
        || { echo "symlink-guard: cron-sync $U failed: $OUT"; rc=1; }
    fi
  done < <(sort -u <<<"$CU")
fi
exit $rc
