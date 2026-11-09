#!/usr/bin/env bash
# app-daemon.sh <create|remove|start|stop|restart|on|off> <username> <name> [command] [workdir] [lifetime-h]
# app-daemon.sh life <username> <name> <hours> — scheduled restart (0 = off)
# app-daemon.sh purge <username>      — remove every daemon of an account (delete-account)
# app-daemon.sh resume <username>     — start enabled daemons again (unsuspend)
# app-daemon.sh halt <username>       — stop every daemon, autostart kept (suspend, Kill all)
# app-daemon.sh converge [--dry-run]  — rewrite old-format units (host-converge)
# Supervised user processes as systemd units (karnak-<user>-<name>.service).
# GUARDED (daemon-common.sh): name strict; workdir a real dir inside the home;
# the command an absolute executable + plain tokens, no control characters.
# The unit never contains the command: it runs app-daemon-run.sh, which reads
# it from the root-only store, installs the link filter as root and drops to
# the user (a newline in ExecStart=$CMD once meant extra unit lines = root).
# Limits (daemon-caps.sh): karnakapp-<user>.slice + the same caps on the unit.
# Restart=always (a queue worker's --max-time exits 0 and must come back);
# the crash-loop guard is StartLimit*: 5 starts in 5 min = the unit stays
# failed until a manual start (which resets it) — user-abuse.sh tells the
# owner. Exit 75 = the runner refused (suspended…): not restarted.
# Store file: workdir, command, lifetime hours (3rd line optional).
set -euo pipefail
HERE="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
. "$HERE/account-check.sh"
. "$HERE/daemon-common.sh"
. "$HERE/daemon-caps.sh"
. "$HERE/abuse-events.sh"
SYSD=/etc/systemd/system
MARK="; KarnakPanel supervised app"
# the stable live path, never $HERE: that resolves into releases/<ver>/, and
# every unit would break once that release is pruned
RUNNER=/usr/local/skypanel/scripts/app-daemon-run.sh

# unit_text <user> <name> [lifetime-h] — the one unit file a daemon may have
# (kc_caps: the caps follow the box's RAM/cores; converge rewrites on change)
unit_text() {
  kc_caps
  local life="${3:-0}"
  printf '%s (managed by app-daemon.sh — do not hand-edit)\n' "$MARK"
  cat <<EOF
; command + workdir: $DAEMON_STORE/$1/$2 (root-only)
[Unit]
Description=Karnak app $1/$2
After=network.target
StartLimitIntervalSec=300
StartLimitBurst=5
[Service]
Type=simple
Slice=karnakapp-$1.slice
ExecStart=$RUNNER $1 $2
Restart=always
RestartSec=5
RestartPreventExitStatus=75
TasksMax=$KA_TASKS
MemoryMax=${KC_MEMMAX}M
CPUQuota=${KC_CPU}%
Nice=10
IOSchedulingClass=best-effort
IOSchedulingPriority=7
EOF
  if (( life > 0 )); then echo "RuntimeMaxSec=$(( life * 3600 ))"; fi
  printf '[Install]\nWantedBy=multi-user.target\n'
}
life_ok() { [[ "$1" =~ ^(0|1|3|6|12|24|48|72|168)$ ]]; }
# stored lifetime of a daemon (3rd store line; missing = 0)
store_life() {
  local f="$DAEMON_STORE/$1/$2" l=""
  if [[ -f "$f" && ! -L "$f" ]]; then l="$(sed -n 3p "$f")"; fi
  if life_ok "$l"; then echo "$l"; else echo 0; fi
}
# write_daemon <user> <name> <dir> <command> [lifetime-h] — validated store file + unit
write_daemon() {
  local u="$1" n="$2" dir="$3" cmd="$4" life="${5:-0}"
  life_ok "$life" || { echo "bad lifetime (hours: 0 1 3 6 12 24 48 72 168)"; return 1; }
  daemon_dir_ok "$u" "$dir" || { echo "$DAEMON_WHY"; return 1; }
  if ! daemon_cmd_ok "$cmd"; then
    # a denylisted binary (miner, tunnel, scanner) is an abuse signal
    if [[ "$DAEMON_WHY" == REFUSED:* ]]; then abuse_event "$u" daemon-refused "$(basename "${cmd%% *}")"; fi
    echo "$DAEMON_WHY"; return 1
  fi
  # the slice before the unit: a unit naming a missing slice gets an uncapped one
  ka_slice_put "$u" || { echo "could not write the limits slice"; return 1; }
  install -d -m 700 -o root -g root "$DAEMON_STORE" "$DAEMON_STORE/$u"
  local tmp; tmp="$(mktemp "$DAEMON_STORE/$u/.new.XXXXXX")"
  printf '%s\n%s\n%s\n' "$dir" "$cmd" "$life" > "$tmp"; chmod 600 "$tmp"; mv -f "$tmp" "$DAEMON_STORE/$u/$n"
  unit_text "$u" "$n" "$life" > "$SYSD/karnak-$u-$n.service"
}
# the account's daemon names (marked unit files only: karnak-sysupdate and
# other karnak-* units are never ours)
user_daemons() {
  local f n
  for f in "$SYSD/karnak-$1-"*.service; do
    [[ -f "$f" ]] && head -1 "$f" | grep -qF "$MARK" || continue
    n="$(basename "$f" .service)"; n="${n#karnak-"$1"-}"
    if [[ "$n" =~ ^[a-z0-9_-]{1,32}$ ]]; then echo "$n"; fi
  done
}
remove_daemon() {  # <user> <name> — every caller daemon-reloads once afterwards
  local unit="karnak-$1-$2"
  # --no-reload: a disable's own full reload costs 8 s at 1000 accounts
  systemctl disable --no-reload "$unit" 2>/dev/null || true
  systemctl stop "$unit" 2>/dev/null || true
  rm -f "$SYSD/$unit.service" "$DAEMON_STORE/$1/$2"
  systemctl reset-failed "$unit" 2>/dev/null || true
}

A="${1:?}"
if [[ "$A" == converge ]]; then
  # pre-1.5.38 units carried User= + WorkingDirectory= + ExecStart=<command>
  # (one template in every release) and no link filter: rebuilt from their
  # own lines after validation. New-format units must equal unit_text exactly;
  # an edited one is rewritten from the root-only store. Anything else (extra
  # or changed lines = injected, or a command the rules now refuse) is stopped
  # and removed, loudly. --dry-run only prints what would happen.
  DRY=0; [[ "${2:-}" == --dry-run ]] && DRY=1
  changed=0
  for f in "$SYSD"/karnak-*.service; do
    [[ -f "$f" ]] || continue
    head -1 "$f" | grep -qF "$MARK" || continue
    base="$(basename "$f" .service)"; rest="${base#karnak-}"; u="${rest%%-*}"; n="${rest#*-}"
    if ! [[ "$u" =~ ^[a-z][a-z0-9_]{2,31}$ && "$n" =~ ^[a-z0-9_-]{1,32}$ ]]; then
      echo "app-daemon: unexpected unit name $base — left alone, review by hand"; continue
    fi
    # limits slice first (also when the unit itself needs no rewrite)
    if (( ! DRY )) && panel_account "$u"; then ka_slice_put "$u" || true; fi
    life="$(store_life "$u" "$n")"
    [[ "$(cat "$f")" == "$(unit_text "$u" "$n" "$life")" ]] && continue
    mapfile -t L < "$f"
    dir=""; cmd=""; why=""
    if [[ "${L[0]}" == "$MARK (managed by app-daemon.sh — do not hand-edit)" ]]; then
      S="$DAEMON_STORE/$u/$n"
      if [[ -f "$S" && ! -L "$S" ]]; then { IFS= read -r dir; IFS= read -r cmd; } < "$S"; else why="no stored command"; fi
    else
      want=("; KarnakPanel supervised app (managed — do not hand-edit)" "[Unit]" "Description=Karnak app $u/$n"
        "After=network.target" "[Service]" "Type=simple" "User=$u" "" ""
        "Restart=on-failure" "RestartSec=5" "[Install]" "WantedBy=multi-user.target")
      if [[ "${#L[@]}" -ne "${#want[@]}" ]]; then why="${#L[@]} lines, old template has ${#want[@]}"
      else
        for i in "${!want[@]}"; do
          (( i == 7 || i == 8 )) && continue
          [[ "${L[$i]}" == "${want[$i]}" ]] || { why="line $((i+1)) differs from the old template"; break; }
        done
        [[ -n "$why" || ( "${L[7]}" == WorkingDirectory=/* && "${L[8]}" == ExecStart=/* ) ]] || why="workdir/command lines differ"
      fi
      dir="${L[7]#WorkingDirectory=}"; cmd="${L[8]#ExecStart=}"
    fi
    if [[ -z "$why" ]]; then
      if ! panel_account "$u"; then why="$ACCOUNT_CHECK_WHY"
      elif ! daemon_dir_ok "$u" "$dir" || ! daemon_cmd_ok "$cmd"; then why="$DAEMON_WHY"; fi
    fi
    if (( DRY )); then
      if [[ -z "$why" ]]; then echo "app-daemon: would rewrite $base"; else echo "app-daemon: would REMOVE $base ($why)"; fi
      continue
    fi
    changed=1
    was_active=0; systemctl is-active --quiet "$base" 2>/dev/null && was_active=1
    if [[ -z "$why" ]] && write_daemon "$u" "$n" "$dir" "$cmd" "$life" >/dev/null; then
      echo "app-daemon: $base rewritten (current unit + limits)"
      if (( was_active )); then systemctl daemon-reload; systemctl restart "$base" 2>/dev/null || true; fi
    else
      remove_daemon "$u" "$n"
      echo "app-daemon: REMOVED $base (${why:-rewrite failed}) — possible tampering, check its history"
    fi
  done
  if (( changed )); then systemctl daemon-reload; fi
  exit 0
fi

U="${2:?}"
valid_account_name "$U" >/dev/null || { echo "bad user"; exit 1; }
if [[ "$A" == purge || "$A" == resume || "$A" == halt ]]; then
  gone=0
  for n in $(user_daemons "$U"); do
    case "$A" in
      purge) remove_daemon "$U" "$n"; gone=1 ;;
      halt) systemctl stop "karnak-$U-$n" 2>/dev/null || true ;;
      resume)
        if systemctl is-enabled --quiet "karnak-$U-$n" 2>/dev/null; then
          systemctl reset-failed "karnak-$U-$n" 2>/dev/null || true
          systemctl start "karnak-$U-$n" 2>/dev/null || true
        fi ;;
    esac
  done
  if [[ "$A" == purge ]]; then
    rm -rf -- "${DAEMON_STORE:?}/$U"; if (( gone )); then systemctl daemon-reload; fi
    ka_slice_drop "$U" || true
  fi
  echo "daemons of $U: $A done"; exit 0
fi

N="${3:?}"
[[ "$N" =~ ^[a-z0-9_-]{1,32}$ ]] || { echo "bad name (a-z0-9_-)"; exit 1; }
panel_account "$U" || { echo "refused: $ACCOUNT_CHECK_WHY"; exit 1; }
UNIT="karnak-$U-$N"
case "$A" in
  create)
    CMD="${4:?}"; DIR="${5:-/home/$U}"; LIFE="${6:-0}"
    [[ "$DIR" != *[[:cntrl:]]* ]] || { echo "bad workdir"; exit 1; }
    write_daemon "$U" "$N" "$(realpath -m -- "$DIR")" "$CMD" "$LIFE"
    systemctl daemon-reload
    systemctl reset-failed "$UNIT" 2>/dev/null || true
    systemctl enable --now "$UNIT" 2>&1 | tail -1 || systemctl start "$UNIT" 2>&1 | tail -1
    echo "daemon $N created+started for $U"
    ;;
  remove)
    remove_daemon "$U" "$N"; systemctl daemon-reload
    [[ -n "$(user_daemons "$U")" ]] || ka_slice_drop "$U" || true
    echo "daemon $N removed"
    ;;
  life)
    LIFE="${4:?}"; S="$DAEMON_STORE/$U/$N"
    life_ok "$LIFE" || { echo "bad lifetime (hours: 0 1 3 6 12 24 48 72 168)"; exit 1; }
    [[ -f "$S" && ! -L "$S" ]] || { echo "no such daemon"; exit 1; }
    { IFS= read -r DIR; IFS= read -r CMD; } < "$S"
    write_daemon "$U" "$N" "$DIR" "$CMD" "$LIFE"
    # a running unit takes the new RuntimeMaxSec at the reload (counted from its start)
    systemctl daemon-reload
    if (( LIFE > 0 )); then echo "daemon $N: restarted every $LIFE h"; else echo "daemon $N: no scheduled restart"; fi
    ;;
  start|restart)
    # after the crash-loop guard tripped, start is refused until reset-failed
    systemctl reset-failed "$UNIT" 2>/dev/null || true
    systemctl "$A" "$UNIT" 2>&1 | tail -1; echo "daemon $N: $A" ;;
  stop) systemctl stop "$UNIT" 2>&1 | tail -1; echo "daemon $N: stop" ;;
  on) systemctl enable "$UNIT" 2>&1 | tail -1; echo "autostart on" ;;
  off) systemctl disable "$UNIT" 2>&1 | tail -1; echo "autostart off" ;;
  *) echo "usage: create|remove|start|stop|restart|on|off|life|purge|resume|halt|converge"; exit 1 ;;
esac
