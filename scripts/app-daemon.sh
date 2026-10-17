#!/usr/bin/env bash
# app-daemon.sh <create|remove|start|stop|restart|on|off> <username> <name> [command] [workdir]
# app-daemon.sh purge <username>      — remove every daemon of an account (delete-account)
# app-daemon.sh resume <username>     — start enabled daemons again (unsuspend)
# app-daemon.sh converge [--dry-run]  — rewrite old-format units (host-converge)
# Supervised user processes as systemd units (karnak-<user>-<name>.service).
# GUARDED (daemon-common.sh): name strict; workdir a real dir inside the home;
# the command an absolute executable + plain tokens, no control characters.
# The unit never contains the command: it runs app-daemon-run.sh, which reads
# it from the root-only store, installs the link filter as root and drops to
# the user (a newline in ExecStart=$CMD once meant extra unit lines = root).
set -euo pipefail
HERE="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
. "$HERE/account-check.sh"
. "$HERE/daemon-common.sh"
SYSD=/etc/systemd/system
MARK="; KarnakPanel supervised app"
# the stable live path, never $HERE: that resolves into releases/<ver>/, and
# every unit would break once that release is pruned
RUNNER=/usr/local/skypanel/scripts/app-daemon-run.sh

# unit_text <user> <name> — the one unit file a daemon may have
unit_text() {
  cat <<EOF
$MARK (managed by app-daemon.sh — do not hand-edit)
; command + workdir: $DAEMON_STORE/$1/$2 (root-only)
[Unit]
Description=Karnak app $1/$2
After=network.target
[Service]
Type=simple
ExecStart=$RUNNER $1 $2
Restart=on-failure
RestartSec=5
TasksMax=512
[Install]
WantedBy=multi-user.target
EOF
}
# write_daemon <user> <name> <dir> <command> — validated store file + unit
write_daemon() {
  local u="$1" n="$2" dir="$3" cmd="$4"
  daemon_dir_ok "$u" "$dir" || { echo "$DAEMON_WHY"; return 1; }
  daemon_cmd_ok "$cmd" || { echo "$DAEMON_WHY"; return 1; }
  install -d -m 700 -o root -g root "$DAEMON_STORE" "$DAEMON_STORE/$u"
  local tmp; tmp="$(mktemp "$DAEMON_STORE/$u/.new.XXXXXX")"
  printf '%s\n%s\n' "$dir" "$cmd" > "$tmp"; chmod 600 "$tmp"; mv -f "$tmp" "$DAEMON_STORE/$u/$n"
  unit_text "$u" "$n" > "$SYSD/karnak-$u-$n.service"
}
remove_daemon() {  # <user> <name>
  local unit="karnak-$1-$2"
  systemctl disable --now "$unit" 2>/dev/null || systemctl stop "$unit" 2>/dev/null || true
  rm -f "$SYSD/$unit.service" "$DAEMON_STORE/$1/$2"
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
    [[ "$(cat "$f")" == "$(unit_text "$u" "$n")" ]] && continue
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
    if [[ -z "$why" ]] && write_daemon "$u" "$n" "$dir" "$cmd" >/dev/null; then
      echo "app-daemon: $base moved to app-daemon-run.sh"
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
if [[ "$A" == purge || "$A" == resume ]]; then
  for f in "$SYSD/karnak-$U-"*.service; do
    [[ -f "$f" ]] && head -1 "$f" | grep -qF "$MARK" || continue
    n="$(basename "$f" .service)"; n="${n#karnak-"$U"-}"
    [[ "$n" =~ ^[a-z0-9_-]{1,32}$ ]] || continue
    if [[ "$A" == purge ]]; then remove_daemon "$U" "$n"
    elif systemctl is-enabled --quiet "karnak-$U-$n" 2>/dev/null; then systemctl start "karnak-$U-$n" 2>/dev/null || true; fi
  done
  if [[ "$A" == purge ]]; then rm -rf -- "${DAEMON_STORE:?}/$U"; systemctl daemon-reload; fi
  echo "daemons of $U: $A done"; exit 0
fi

N="${3:?}"
[[ "$N" =~ ^[a-z0-9_-]{1,32}$ ]] || { echo "bad name (a-z0-9_-)"; exit 1; }
panel_account "$U" || { echo "refused: $ACCOUNT_CHECK_WHY"; exit 1; }
UNIT="karnak-$U-$N"
case "$A" in
  create)
    CMD="${4:?}"; DIR="${5:-/home/$U}"
    [[ "$DIR" != *[[:cntrl:]]* ]] || { echo "bad workdir"; exit 1; }
    write_daemon "$U" "$N" "$(realpath -m -- "$DIR")" "$CMD"
    systemctl daemon-reload
    systemctl enable --now "$UNIT" 2>&1 | tail -1 || systemctl start "$UNIT" 2>&1 | tail -1
    echo "daemon $N created+started for $U"
    ;;
  remove)
    remove_daemon "$U" "$N"; systemctl daemon-reload
    echo "daemon $N removed"
    ;;
  start|stop|restart) systemctl "$A" "$UNIT" 2>&1 | tail -1; echo "daemon $N: $A" ;;
  on) systemctl enable "$UNIT" 2>&1 | tail -1; echo "autostart on" ;;
  off) systemctl disable "$UNIT" 2>&1 | tail -1; echo "autostart off" ;;
  *) echo "usage: create|remove|start|stop|restart|on|off|purge|resume|converge"; exit 1 ;;
esac
