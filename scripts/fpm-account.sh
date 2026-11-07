#!/usr/bin/env bash
# fpm-account.sh — one PHP-FPM master per hosting account, each in its own
# capped cgroup. Before: one master per PHP version ran every account's pool,
# so one site could take all CPU/RAM (the OOM killer then picked MariaDB or
# Exim) and all sites of a version shared one /dev/shm.
# Now, per account <u>:
#   pool    /etc/php/<v>/fpm/accounts/skypanel-<u>.conf (php-pool.sh writes it;
#           outside pool.d, so the version master never loads it)
#           + skypanel-<u>.verified, the copy that passed `php-fpm -t`: the
#           master only ever loads that, so a broken pool edit can't stop an
#           idle account from starting again
#   master  /etc/php/<v>/fpm/accounts/skypanel-<u>.master ([global] + include)
#   unit    karnakfpm-<u>.service; sandbox + no-symlink filter come from the
#           prefix drop-ins in karnakfpm-.service.d (fpm-sandbox.sh,
#           symlink-guard.sh), the same text as the version units
#   socket  karnakfpx-<u>.socket holds /run/php/skypanel-<u>.sock (OLS is
#           unchanged). The first request starts karnakfpx-<u>.service
#           (systemd-socket-proxyd), which starts the master behind it on
#           /run/php/karnakfpm-<u>.sock; after FPMA_IDLE without a request the
#           relay exits and the master stops (StopWhenUnneeded): an idle
#           account costs no memory. FPM itself can't do this: it unlinks its
#           socket path on stop, so a handed-over socket never wakes again.
#           karnakfpx, not karnakfpm: the prefix drop-ins and restart sweeps
#           must not catch the relay. No proxyd = always-on master on the
#           public socket (enabled at boot).
#   slice   karnakfpm-<u>.slice: CPUQuota, MemoryHigh/Max, TasksMax from the
#           pool's max_children; all of them under karnakfpm.slice, capped
#           as a whole so MariaDB/Exim/the panel keep headroom
# A worker killed at MemoryMax takes only that request (OOMPolicy=continue);
# the master and the other workers stay. Own unit = own /dev/shm tmpfs.
# The version masters (php8.x-fpm) stay for www + webmail/phpMyAdmin.
# Sourced: fpm_sync <user> (php-pool.sh, php-pool-remove.sh, quota-enforce,
# group-heal), fpm_pool_dir <8.x>. Run:
#   converge   every account onto the layout in force (host-converge)
#   status     TSV user, state (active|idle|failed|…|shared|off), mem used MB,
#              mem cap MB, cpu quota %
#   shared     pin: back to the shared per-version masters (rollback)
#   own        drop the pin
# Idempotent; quiet when nothing changes.
FPMA_PIN=/var/lib/karnakpanel/fpm-shared-masters
FPMA_SYSD=/etc/systemd/system
FPMA_HERE="$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")"
FPMA_PROXYD=/usr/lib/systemd/systemd-socket-proxyd
# idle time before an account's master stops (next request: ~0.1s cold start)
FPMA_IDLE=10min

fpm_own() { [[ ! -e "$FPMA_PIN" ]]; }
fpm_idle() { fpm_own && [[ -x "$FPMA_PROXYD" ]]; }
# where php-pool.sh writes a pool for version $1
fpm_pool_dir() { if fpm_own; then echo "/etc/php/$1/fpm/accounts"; else echo "/etc/php/$1/fpm/pool.d"; fi; }
# the pool's listen path: behind the relay in idle mode, else the public one
fpm_listen() { if fpm_idle; then echo "/run/php/karnakfpm-$1.sock"; else echo "/run/php/skypanel-$1.sock"; fi; }

# caps from max_children ($1). Memory: 128M a worker + 128M opcache, at
# least 512M, at most half the RAM. CPU: half the cores (at least one).
# Tasks: imagick/OpenMP threads per core, plus sendmail/exim for mail().
fpm_caps() {
  local c="$1" ram cpus
  ram=$(( $(awk '/^MemTotal:/{print $2}' /proc/meminfo) / 1024 ))
  cpus="$(nproc)"
  FPMA_MEMMAX=$(( c * 128 + 128 )); (( FPMA_MEMMAX < 512 )) && FPMA_MEMMAX=512
  (( FPMA_MEMMAX > ram / 2 )) && FPMA_MEMMAX=$(( ram / 2 ))
  FPMA_MEMHIGH=$(( FPMA_MEMMAX * 85 / 100 ))
  FPMA_CPU=$(( cpus / 2 * 100 )); (( FPMA_CPU < 100 )) && FPMA_CPU=100
  FPMA_TASKS=$(( c * (cpus + 3) + 16 ))
}

fpm_unit_text() { # <user> <8.x>
  local idle="" inst="
[Install]
WantedBy=multi-user.target"
  fpm_idle && { idle="# started by karnakfpx-$1.socket; stops once the relay has gone idle
StopWhenUnneeded=yes"; inst=""; }
  cat <<EOF
# managed by fpm-account.sh — PHP-FPM master of hosting account $1
[Unit]
Description=PHP $2 FastCGI for account $1 (KarnakPanel)
After=network.target
$idle

[Service]
Type=notify
Slice=karnakfpm-$1.slice
ExecStart=/usr/sbin/php-fpm$2 --nodaemonize --fpm-config /etc/php/$2/fpm/accounts/skypanel-$1.master
ExecReload=/bin/kill -USR2 \$MAINPID
Restart=on-failure
RestartSec=2
# a worker killed at MemoryMax is that request only, not the whole account
OOMPolicy=continue
# the distro php-fpm unit's own hardening (sandbox: drop-ins)
ProtectSystem=full
PrivateDevices=true
ProtectKernelModules=true
ProtectKernelTunables=true
ProtectControlGroups=true
RestrictRealtime=true
RestrictAddressFamilies=AF_INET AF_INET6 AF_NETLINK AF_UNIX
RestrictNamespaces=true
$inst
EOF
}
fpm_socket_text() { # <user>
  cat <<EOF
# managed by fpm-account.sh — account $1's public FastCGI socket (OLS connects here)
[Unit]
Description=PHP FastCGI socket for account $1 (KarnakPanel)
# a master that can't start leaves the request queued, the socket re-fires
# and hits its trigger limit within a second: retry instead of staying down
OnFailure=karnakfpx-heal@$1.service

[Socket]
ListenStream=/run/php/skypanel-$1.sock
SocketUser=nobody
SocketGroup=nogroup
SocketMode=0660
Backlog=128
RemoveOnStop=yes

[Install]
WantedBy=sockets.target
EOF
}
FPMA_HEAL="# managed by fpm-account.sh — brings a tripped account socket back (30s backoff)
[Unit]
Description=Re-arm the PHP FastCGI socket of account %i (KarnakPanel)

[Service]
Type=oneshot
ExecStartPre=/bin/sleep 30
ExecStart=-/usr/bin/systemctl reset-failed karnakfpm-%i.service karnakfpx-%i.service karnakfpx-%i.socket
ExecStart=-/usr/bin/systemctl start karnakfpx-%i.socket"
fpm_relay_text() { # <user>
  cat <<EOF
# managed by fpm-account.sh — relays account $1's requests to its PHP master,
# starting it first; exits after $FPMA_IDLE without one, the master follows
[Unit]
Description=PHP FastCGI relay for account $1 (KarnakPanel)
BindsTo=karnakfpm-$1.service
After=karnakfpm-$1.service

[Service]
Slice=karnakfpm-$1.slice
ExecStart=$FPMA_PROXYD --exit-idle-time=$FPMA_IDLE /run/php/karnakfpm-$1.sock
User=nobody
Group=nogroup
NoNewPrivileges=yes
CapabilityBoundingSet=
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes
PrivateDevices=yes
PrivateNetwork=yes
RestrictAddressFamilies=AF_UNIX
ProtectKernelModules=yes
ProtectKernelTunables=yes
ProtectControlGroups=yes
SystemCallFilter=@system-service
MemoryMax=32M
EOF
}
fpm_slice_text() { # <user>  (fpm_caps first)
  cat <<EOF
# managed by fpm-account.sh — resource caps of hosting account $1
[Unit]
Description=KarnakPanel account $1

[Slice]
CPUQuota=${FPMA_CPU}%
MemoryHigh=${FPMA_MEMHIGH}M
MemoryMax=${FPMA_MEMMAX}M
TasksMax=$FPMA_TASKS
EOF
}
# all accounts together: below system services on CPU, and never all the RAM
FPMA_PARENT='# managed by fpm-account.sh — every hosting account'"'"'s PHP, together
[Unit]
Description=KarnakPanel hosting accounts (PHP)

[Slice]
CPUWeight=50
MemoryHigh=70%
MemoryMax=80%'
fpm_master_text() { # <user> <8.x> [pool file]  (the pool's hash: a changed pool = reload)
  local p="${3:-/etc/php/$2/fpm/accounts/skypanel-$1.verified}"
  cat <<EOF
; managed by fpm-account.sh — master of account $1's pool (do not hand-edit)
; pool sha256 $(sha256sum "$p" | cut -c1-16)
[global]
pid = /run/php/karnakfpm-$1.pid
error_log = syslog
syslog.ident = karnakfpm-$1
include = $p
EOF
}

# write $2 to $1 when it differs; echoes 1 when written
fpm_put() {
  [[ "$(cat "$1" 2>/dev/null)" == "$2" ]] && { echo 0; return 0; }
  printf '%s\n' "$2" > "$1.tmp" && chmod 644 "$1.tmp" && mv -f "$1.tmp" "$1" && echo 1
}

# the sandbox + no-symlink filter must be in place before a unit starts
fpm_guards() {
  local d="$FPMA_SYSD/karnakfpm-.service.d"
  [[ -f "$d/karnak-nosymlink.conf" ]] && { [[ -f "$d/karnak-sandbox.conf" || -e /var/lib/karnakpanel/fpm-sandbox-off ]]; } && return 0
  "$FPMA_HERE/symlink-guard.sh" fpm >/dev/null || true
  [[ -f "$d/karnak-nosymlink.conf" ]] || echo "WARN: fpm-account: no-symlink filter missing for karnakfpm units"
}

# the old master holds the socket until its (async) reload lands; never
# called while our own socket unit holds it
fpm_wait_socket_free() {
  local i
  systemctl is-active --quiet "karnakfpx-$1.socket" && return 0
  for i in $(seq 1 20); do
    ss -Hxl 2>/dev/null | grep -qF " /run/php/skypanel-$1.sock " || return 0
    sleep 0.5
  done
  return 0
}

fpm_drop_relay() { # <user> — stop + remove socket and relay (echoes 1 if any)
  local U="$1" ch=0
  if [[ -f "$FPMA_SYSD/karnakfpx-$U.socket" || -f "$FPMA_SYSD/karnakfpx-$U.service" ]]; then
    # disable without its own full reload (8 s at 1000 accounts): every caller
    # reloads once after the files are gone. One stop call: systemd orders the
    # stops (no new trigger in between)
    systemctl disable --no-reload "karnakfpx-$U.socket" >/dev/null 2>&1 || true
    systemctl stop "karnakfpx-$U.socket" "karnakfpx-$U.service" >/dev/null 2>&1 || true
    rm -f "$FPMA_SYSD/karnakfpx-$U.socket" "$FPMA_SYSD/karnakfpx-$U.service"; ch=1
  fi
  echo "$ch"
}

fpm_drop_unit() { # <user> — stop + remove socket, relay, unit, slice, masters
  local U="$1" u="karnakfpm-$1.service" ch f
  ch="$(fpm_drop_relay "$U")"
  if [[ -f "$FPMA_SYSD/$u" ]]; then
    systemctl disable --no-reload "$u" >/dev/null 2>&1 || true
    systemctl stop "$u" >/dev/null 2>&1 || true
    rm -f "$FPMA_SYSD/$u"; ch=1
  fi
  if [[ -f "$FPMA_SYSD/karnakfpm-$U.slice" ]]; then
    # an empty slice stays loaded after its file goes; stop it too
    systemctl stop "karnakfpm-$U.slice" >/dev/null 2>&1 || true
    rm -f "$FPMA_SYSD/karnakfpm-$U.slice"; ch=1
  fi
  for f in /etc/php/8.[0-9]/fpm/accounts/skypanel-"$U".{master,verified}; do [[ -f "$f" ]] && rm -f "$f"; done
  (( ch )) && { systemctl daemon-reload; echo "fpm-account: $U own PHP master removed"; }
  return 0
}

# point the user's pool files at the listen path of the layout in force
fpm_fix_listen() {
  local U="$1" want f
  want="$(fpm_listen "$U")"
  for f in /etc/php/8.[0-9]/fpm/{pool.d,accounts}/skypanel-"$U".conf{,.suspended}; do
    [[ -f "$f" ]] || continue
    grep -qxF "listen = $want" "$f" && continue
    sed -i -E "s#^listen = /run/php/(skypanel|karnakfpm)-$U\.sock\$#listen = $want#" "$f"
  done
}

# fpm_sync <user> — the account's PHP onto the layout in force: pool files
# moved to the right dir, unit/slice written, started/reloaded/stopped.
fpm_sync() {
  local U="$1" d v f from to live="" n=0 unit ch_u ch_s ch_m ch_p
  [[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "fpm-account: bad user $U"; return 1; }
  local -A MOVED=()
  for d in /etc/php/8.[0-9]/fpm; do
    [[ -d "$d" ]] || continue
    v="${d#/etc/php/}"; v="${v%/fpm}"
    if fpm_own; then from="$d/pool.d"; to="$d/accounts"; else from="$d/accounts"; to="$d/pool.d"; fi
    for f in "skypanel-$U.conf" "skypanel-$U.conf.suspended"; do
      [[ -f "$from/$f" ]] || continue
      mkdir -p "$to" && chmod 755 "$to" && mv -f "$from/$f" "$to/$f" && MOVED["$v"]=1
    done
  done
  fpm_fix_listen "$U"
  if ! fpm_own; then
    # rollback: this master lets go of the socket BEFORE the version one takes it
    fpm_drop_unit "$U"
    for v in "${!MOVED[@]}"; do systemctl reload "php$v-fpm" 2>/dev/null || systemctl restart "php$v-fpm" || true; done
    (( ${#MOVED[@]} )) && echo "fpm-account: $U back on the shared PHP ${!MOVED[*]} master"
    return 0
  fi
  # the version master lets go of the socket first
  for v in "${!MOVED[@]}"; do systemctl reload "php$v-fpm" 2>/dev/null || true; done
  for f in /etc/php/8.[0-9]/fpm/accounts/skypanel-"$U".conf; do [[ -f "$f" ]] && { live="$f"; n=$((n + 1)); }; done
  (( n <= 1 )) || echo "WARN: fpm-account: $U has pools on $n versions — using $live"
  if [[ -z "$live" ]]; then fpm_drop_unit "$U"; return 0; fi   # removed or suspended
  v="${live#/etc/php/}"; v="${v%%/*}"
  [[ -x "/usr/sbin/php-fpm$v" ]] || { echo "fpm-account: $U: php-fpm$v not installed"; return 1; }
  local A="/etc/php/$v/fpm/accounts"
  for f in /etc/php/8.[0-9]/fpm/accounts/skypanel-"$U".{master,verified}; do
    [[ -f "$f" && "${f%/*}" != "$A" ]] && rm -f "$f"
  done
  # a broken pool must not reach the master: test a candidate, keep the last good copy
  local t ch_v
  fpm_master_text "$U" "$v" "$live" > "$A/skypanel-$U.candidate"
  t="$("/usr/sbin/php-fpm$v" -t -y "$A/skypanel-$U.candidate" 2>&1)"; local trc=$?
  rm -f "$A/skypanel-$U.candidate"
  if (( trc )); then
    echo "fpm-account: $U pool config FAILED the test — last good config kept: $(tail -2 <<<"$t" | tr '\n' ' ')"
    return 1
  fi
  ch_v="$(fpm_put "$A/skypanel-$U.verified" "$(cat "$live")")" || return 1
  local c; c="$(grep -E '^pm\.max_children' "$live" | grep -oE '[0-9]+' | head -1 || true)"
  [[ "$c" =~ ^[0-9]{1,2}$ ]] || c=10
  fpm_caps "$c"
  unit="karnakfpm-$U.service"
  local sock="karnakfpx-$U.socket" relay="karnakfpx-$U.service" ch_x=0 woke=0
  ch_p="$(fpm_put "$FPMA_SYSD/karnakfpm.slice" "$FPMA_PARENT")" || return 1
  ch_s="$(fpm_put "$FPMA_SYSD/karnakfpm-$U.slice" "$(fpm_slice_text "$U")")" || return 1
  ch_u="$(fpm_put "$FPMA_SYSD/$unit" "$(fpm_unit_text "$U" "$v")")" || return 1
  ch_m="$(fpm_put "$A/skypanel-$U.master" "$(fpm_master_text "$U" "$v")")" || return 1
  if fpm_idle; then
    ch_x="$(fpm_put "$FPMA_SYSD/$sock" "$(fpm_socket_text "$U")")$(fpm_put "$FPMA_SYSD/$relay" "$(fpm_relay_text "$U")")" || return 1
    ch_x+="$(fpm_put "$FPMA_SYSD/karnakfpx-heal@.service" "$FPMA_HEAL")" || return 1
    [[ "$ch_x" == 000 ]] && ch_x=0 || ch_x=1
  else
    ch_x="$(fpm_drop_relay "$U")"
  fi
  fpm_guards
  [[ "$ch_p$ch_s$ch_u$ch_x" == 0000 ]] || systemctl daemon-reload
  if ! fpm_idle; then
    # enable re-reads every unit (1.7 s at 200 accounts, 3 s at 400): only when
    # the link is missing, and without the reload — the daemon-reload above
    # already loaded the unit, the link only matters at boot
    [[ -L "/etc/systemd/system/multi-user.target.wants/$unit" ]] || systemctl enable --no-reload "$unit" >/dev/null 2>&1 || true
    if [[ "$ch_u" == 1 ]] || ! systemctl is-active --quiet "$unit"; then
      fpm_wait_socket_free "$U"
      systemctl restart "$unit" || true
    elif [[ "$ch_m" == 1 ]]; then
      systemctl reload "$unit" || systemctl restart "$unit" || true
    fi
    systemctl is-active --quiet "$unit" || { echo "fpm-account: $unit FAILED to start: $(journalctl -u "$unit" -n 3 --no-pager -q 2>/dev/null | tr '\n' ' ')"; return 1; }
    return 0
  fi
  # on demand: the boot link of the always-on layout would keep it "needed"
  if [[ -L "/etc/systemd/system/multi-user.target.wants/$unit" ]]; then
    rm -f "/etc/systemd/system/multi-user.target.wants/$unit"; systemctl daemon-reload
  fi
  [[ -L "/etc/systemd/system/sockets.target.wants/$sock" ]] || systemctl enable --no-reload "$sock" >/dev/null 2>&1 || true
  if ! systemctl is-active --quiet "$sock"; then
    # first switch (an always-on master still holds the public socket) or a
    # socket that tripped its trigger limit
    systemctl stop "$relay" "$unit" >/dev/null 2>&1 || true
    fpm_wait_socket_free "$U"
    systemctl reset-failed "$sock" "$relay" "$unit" >/dev/null 2>&1 || true
    systemctl start "$sock" || { echo "fpm-account: $sock FAILED to start: $(journalctl -u "$sock" -n 3 --no-pager -q 2>/dev/null | tr '\n' ' ')"; return 1; }
  elif systemctl is-active --quiet "$unit"; then
    if [[ "$ch_u$ch_x" != 00 ]]; then
      systemctl stop "$relay" "$unit" >/dev/null 2>&1 || true; woke=1
    elif [[ "$ch_m" == 1 ]]; then
      systemctl reload "$unit" || { systemctl stop "$relay" "$unit" >/dev/null 2>&1 || true; woke=1; }
    fi
  fi
  # idle accounts stay idle (the next request reads the new config); a pool
  # the owner just changed, or a master we stopped, gets one test start
  [[ "$ch_v" == 1 ]] && woke=1
  if (( woke )); then
    systemctl start "$relay" >/dev/null 2>&1 || true
    systemctl is-active --quiet "$unit" || { echo "fpm-account: $unit FAILED to start: $(journalctl -u "$unit" -n 3 --no-pager -q 2>/dev/null | tr '\n' ' ')"; return 1; }
    # stop it again: a relay started without a connection never arms its idle
    # timer (it counts from the last connection closing), so an account with
    # no visitors kept its master forever — 150 new accounts filled TEST's RAM.
    # The next request starts it through the socket.
    systemctl stop "$relay" >/dev/null 2>&1 || true
  fi
  return 0
}

# accounts with a pool anywhere, or a unit left behind
fpm_accounts() {
  local f b
  {
    for f in /etc/php/8.[0-9]/fpm/{pool.d,accounts}/skypanel-*.conf /etc/php/8.[0-9]/fpm/{pool.d,accounts}/skypanel-*.conf.suspended; do
      [[ -f "$f" ]] || continue
      b="${f##*/skypanel-}"; echo "${b%%.conf*}"
    done
    for f in "$FPMA_SYSD"/karnakfpm-*.service; do
      [[ -f "$f" ]] || continue
      b="${f##*/karnakfpm-}"; echo "${b%.service}"
    done
  } | grep -E '^[a-z][a-z0-9_]{2,31}$' | sort -u
}

fpm_converge() {
  local U rc=0
  while IFS= read -r U; do fpm_sync "$U" || rc=1; done < <(fpm_accounts)
  if ! fpm_idle && [[ -f "$FPMA_SYSD/karnakfpx-heal@.service" ]]; then
    rm -f "$FPMA_SYSD/karnakfpx-heal@.service"; systemctl daemon-reload
  fi
  if ! fpm_own && [[ -f "$FPMA_SYSD/karnakfpm.slice" ]]; then
    rm -f "$FPMA_SYSD/karnakfpm.slice"; systemctl daemon-reload
  fi
  return $rc
}

# fpm_status [states]: "states" skips the per-slice memory/CPU read — one
# systemd round trip per account, 2 min at 1000 accounts while reloads run
fpm_status() {
  local U st mem max cpu q p d k v only="${1:-}"
  local -a US=()
  mapfile -t US < <(fpm_accounts)
  (( ${#US[@]} )) || return 0
  # ONE systemctl call for all accounts (list-units stays fast while systemd
  # reloads); RAM/caps straight from the slice cgroup in /sys — a
  # `systemctl show` of every slice took 45 s under delete load and the
  # PHP pools page (15 s limit) came up empty
  local -A ST=()
  while read -r k _ v _; do [[ -n "$k" ]] && ST["$k"]="$v"; done \
    < <(systemctl list-units --all --plain --no-legend 'karnakfpm-*.service' 'karnakfpx-*.socket' 2>/dev/null)
  for U in "${US[@]}"; do
    if [[ ! -f "$FPMA_SYSD/karnakfpm-$U.service" ]]; then
      # shared = a live pool in a version master; off = parked (quota) or none
      st=off; compgen -G "/etc/php/8.[0-9]/fpm/pool.d/skypanel-$U.conf" >/dev/null && st=shared
      printf '%s\t%s\t-\t-\t-\n' "$U" "$st"; continue
    fi
    st="${ST["karnakfpm-$U.service"]:-inactive}"
    if [[ "$st" != active && -f "$FPMA_SYSD/karnakfpx-$U.socket" ]]; then
      # socket up, master stopped = idle (starts on the next request)
      [[ "${ST["karnakfpx-$U.socket"]:-}" == active ]] && st=idle || st=failed
    fi
    mem=-; max=-; cpu=-
    if [[ -z "$only" ]]; then
      d="/sys/fs/cgroup/karnakfpm.slice/karnakfpm-$U.slice"
      if [[ -d "$d" ]]; then
        mem="$(cat "$d/memory.current" 2>/dev/null || true)"; max="$(cat "$d/memory.max" 2>/dev/null || true)"
        [[ "$mem" =~ ^[0-9]+$ ]] && mem=$(( mem / 1048576 )) || mem=-
        [[ "$max" =~ ^[0-9]+$ ]] && max=$(( max / 1048576 )) || max=-
        read -r q p < "$d/cpu.max" 2>/dev/null || { q=; p=; }
        [[ "$q" =~ ^[0-9]+$ && "$p" =~ ^[0-9]+$ ]] && cpu=$(( q * 100 / p ))
      else
        # not started since boot: no cgroup yet, caps from the slice file
        max="$(sed -n 's/^MemoryMax=\([0-9]*\)M$/\1/p' "$FPMA_SYSD/karnakfpm-$U.slice" 2>/dev/null || true)"; max="${max:--}"
        cpu="$(sed -n 's/^CPUQuota=\([0-9]*\)%$/\1/p' "$FPMA_SYSD/karnakfpm-$U.slice" 2>/dev/null || true)"; cpu="${cpu:--}"
      fi
    fi
    printf '%s\t%s\t%s\t%s\t%s\n' "$U" "${st:-unknown}" "$mem" "$max" "$cpu"
  done
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  set -uo pipefail
  case "${1:-}" in
    converge) fpm_converge; exit $? ;;
    status) [[ "${2:-}" =~ ^(states)?$ ]] || { echo "usage: $0 status [states]"; exit 1; }; fpm_status "${2:-}" ;;
    shared) mkdir -p "${FPMA_PIN%/*}"; : > "$FPMA_PIN"; fpm_converge; exit $? ;;
    own) rm -f "$FPMA_PIN"; fpm_converge; exit $? ;;
    *) echo "usage: $0 converge|status [states]|shared|own"; exit 1 ;;
  esac
fi
