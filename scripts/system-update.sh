#!/usr/bin/env bash
# system-update.sh status | check | go | tail <offset|last> — OS packages (apt).
#   status   what is waiting, from the current package lists (no network,
#            ~1s; the lists refresh daily via apt-daily, or with "check"):
#              lists<TAB>epoch          last successful list refresh
#              pkg<TAB>name<TAB>installed<TAB>new<TAB>security|update<TAB>needs-reboot 0|1
#              kept<TAB>name            held back by apt (phased rollout / new deps)
#              reboot<TAB>0|1<TAB>pkgs  /var/run/reboot-required(.pkgs)
#              auto<TAB>0|1             unattended-upgrades installs security nightly
#              running<TAB>panel|auto|no  a panel run, or apt-daily-upgrade, active
#   check    refresh package lists, then report     } detached in the systemd
#   go       upgrade everything that's waiting       } unit karnak-sysupdate
#   tail     live view: "state<TAB>running|idle<TAB>next-offset" then up to
#            64 KB of /var/log/karnakpanel/apt-upgrade.log from <offset>
#            ("last" = from the start of the latest run).
# Why a systemd unit: started from the panel, the old background subshell
# lived in lsws.service's cgroup — the openlitespeed package stops lsws,
# which could kill apt mid-upgrade. A unit outlives that (and the 15s sudo
# timeout), and can't be started twice.
# Upgrade (not dist-upgrade): no new packages, no removals — services restart
# in place; a reboot may still be flagged (shown in the panel UI).
set -euo pipefail
LOG="/var/log/karnakpanel/apt-upgrade.log"
UNIT=karnak-sysupdate
START=/run/karnak-sysupdate.start
M="${1:-status}"
export DEBIAN_FRONTEND=noninteractive

case "$M" in
status)
  ST=/var/lib/apt/periodic/update-success-stamp
  [[ -f "$ST" ]] || ST=/var/lib/apt/lists
  echo -e "lists\t$(stat -c %Y "$ST" 2>/dev/null || echo 0)"
  # Inst libx [1.0] (1.1 Ubuntu:24.04/noble-updates, Ubuntu:24.04/noble-security [amd64])
  # 6th field: 1 = only takes effect after a reboot (auto-updates.sh regex)
  RRE="$("$(dirname "$(readlink -f "$0")")/auto-updates.sh" regex 2>/dev/null || echo '^$')"
  apt-get -s -o Debug::NoLocking=1 upgrade 2>/dev/null | awk -v rre="^$RRE" '
    /^Inst / {
      n = $2; o = ($3 ~ /^\[/) ? $3 : "-"; gsub(/[\[\]]/, "", o)
      p = $0; sub(/^[^(]*\(/, "", p); split(p, a, " "); v = a[1]
      k = (p ~ /-security/ || p ~ /Debian-Security/) ? "security" : "update"
      if (++c <= 400) printf "pkg\t%s\t%s\t%s\t%s\t%d\n", n, o, v, k, (n ~ rre)
      next
    }
    /kept back:$/ { kb = 1; next }
    kb && /^  / { for (i = 1; i <= NF; i++) printf "kept\t%s\n", $i; next }
    { kb = 0 }'
  if [[ -f /var/run/reboot-required ]]; then
    echo -e "reboot\t1\t$(sort -u /var/run/reboot-required.pkgs 2>/dev/null | head -10 | tr '\n' ' ')"
  else echo -e "reboot\t0\t"; fi
  # not "apt-config | grep -q": grep exits on the match, SIGPIPE + pipefail = "off"
  [[ "$(apt-config dump 2>/dev/null)" == *$'\nAPT::Periodic::Unattended-Upgrade "1";'* ]] && A=1 || A=0
  dpkg -s unattended-upgrades >/dev/null 2>&1 || A=0
  echo -e "auto\t$A"
  if systemctl is-active --quiet "$UNIT"; then R=panel
  elif systemctl is-active --quiet apt-daily-upgrade.service; then R=auto
  else R=no; fi
  echo -e "running\t$R"
  ;;
check|go)
  systemctl is-active --quiet "$UNIT" && { echo "an update is already running"; exit 0; }
  systemctl reset-failed "$UNIT" 2>/dev/null || true
  touch "$LOG"; chmod 640 "$LOG"
  stat -c %s "$LOG" > "$START"
  systemd-run --unit="$UNIT" --collect --quiet -p Nice=5 "$(readlink -f "$0")" run "$M"
  echo "started"
  ;;
run)
  # the unit's own job. Refused from sudo (no INVOCATION_ID there): a direct
  # call would die at the 15s sudo timeout, mid-dpkg.
  [[ -n "${INVOCATION_ID:-}" ]] || { echo "run is internal — use check or go"; exit 1; }
  MODE="${2:-check}"
  [[ "$MODE" =~ ^(check|go)$ ]] || exit 1
  exec >> "$LOG" 2>&1
  echo "=== $(date -Is) update started (mode: $MODE) ==="
  # wait for a nightly unattended run instead of failing on the dpkg lock
  W=(-o DPkg::Lock::Timeout=600 -o APT::Color=0 -o Dpkg::Progress-Fancy=0)
  apt-get "${W[@]}" update || echo "!! package list refresh FAILED"
  if [[ "$MODE" == go ]]; then
    # keep our edited conffiles (php.ini, pools, OLS): a conffile prompt with
    # no tty aborts dpkg mid-upgrade
    KEEP=(-o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold)
    echo "PLAN $(apt-get -s upgrade 2>/dev/null | grep -c '^Inst' || true) packages"
    # openlitespeed's package stops lsws at unpack and starts it only when
    # configured — after every other package, so panel + all sites were down
    # for the whole run (~2.5 min for 100 packages, TEST 2026-10-31). Hold it
    # for the bulk upgrade, then upgrade it alone: down for seconds.
    held=0
    if dpkg -s openlitespeed >/dev/null 2>&1 && ! apt-mark showhold | grep -qx openlitespeed; then
      apt-mark hold openlitespeed >/dev/null && held=1
    fi
    fail=0
    apt-get upgrade -y "${W[@]}" "${KEEP[@]}" || { fail=1; echo "apt upgrade returned an error — will check and retry"; }
    if (( held )); then
      apt-mark unhold openlitespeed >/dev/null
      if apt-get -s install --only-upgrade openlitespeed 2>/dev/null | grep -q '^Inst'; then
        echo "--- openlitespeed (web server + panel restart for a few seconds) ---"
        apt-get install -y --only-upgrade "${W[@]}" "${KEEP[@]}" openlitespeed || { fail=1; echo "openlitespeed upgrade returned an error — will check and retry"; }
      fi
    fi
    # A package's maintainer script can die mid-configure and leave it
    # half-configured (OLS 1.9.4 on swiftnest 2026-11-01: lswsctrl's restart
    # fallback `pkill -9 litespeed` also matched the postinst itself, comm
    # "openlitespeed.p"). Finish every pending configure once, retry a failed
    # upgrade once, and only then report FAILED — naming what is left.
    broken() { dpkg-query -W -f='${db:Status-Status} ${Package}\n' 2>/dev/null \
      | awk '$1 ~ /^(half-configured|unpacked|half-installed|triggers-awaited|triggers-pending)$/ {print $2}' | sort -u | tr '\n' ' ' || true; }
    B="$(broken)"
    if [[ -n "$B" ]]; then
      echo "left unfinished: $B— finishing (dpkg --configure -a)"
      sleep 5
      dpkg --configure -a || true
    fi
    if (( fail )); then
      echo "--- retrying the upgrade once ---"
      apt-get upgrade -y "${W[@]}" "${KEEP[@]}" || true
    fi
    if [[ -n "$B" ]] || (( fail )); then
      B="$(broken)"
      LEFT="$(apt-get -s upgrade 2>/dev/null | awk '/^Inst /{print $2}' | tr '\n' ' ' || true)"
      if [[ -z "$B" && -z "$LEFT" ]]; then echo "recovered: all packages configured and upgraded"
      else
        [[ -n "$B" ]] && echo "!! still unfinished: $B(run: dpkg --configure -a)"
        [[ -n "$LEFT" ]] && echo "!! not upgraded: $LEFT"
        echo "!! apt upgrade FAILED"
      fi
    fi
    systemctl is-active --quiet lsws || { echo "lsws not running after upgrade: starting it"; systemctl start lsws; }
    [[ -f /var/run/reboot-required ]] && echo "REBOOT REQUIRED" || echo "no reboot required"
  else
    apt-get -s upgrade 2>/dev/null | grep -E "^[0-9]+ upgraded" || echo "nothing to upgrade"
  fi
  echo "=== $(date -Is) done ==="
  ;;
tail)
  O="${2:-last}"
  SZ=$(stat -c %s "$LOG" 2>/dev/null || echo 0)
  if [[ "$O" == last ]]; then
    # no start mark (rebooted since, /run is tmpfs): the log's last 16 KB
    O="$(cat "$START" 2>/dev/null || echo "$(( SZ > 16384 ? SZ - 16384 : 0 ))")"
  fi
  [[ "$O" =~ ^[0-9]{1,12}$ ]] || { echo "bad offset"; exit 1; }
  (( O > SZ )) && O=0   # log rotated
  N=$(( SZ - O )); (( N > 65536 )) && N=65536
  systemctl is-active --quiet "$UNIT" && R=running || R=idle
  echo -e "state\t$R\t$(( O + N ))"
  # head stops early on a big backlog: tail's SIGPIPE is expected, not a failure
  (( N > 0 )) && { tail -c +"$(( O + 1 ))" "$LOG" | head -c "$N" || true; }
  exit 0
  ;;
*) echo "usage: system-update.sh status|check|go|tail <offset|last>"; exit 1 ;;
esac
