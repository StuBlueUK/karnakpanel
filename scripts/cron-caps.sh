# cron-caps.sh — sourced (never run): resource limits for customer cron jobs.
# Every run of a job is its own transient scope (cron-run.sh):
#   karnakcron.slice                 all accounts' jobs together: below system
#                                    services and PHP on CPU/IO, never most RAM
#     karnakcron-<user>.slice        one account's jobs together (cron-sync.sh)
#       karnakcron-<user>-<id>-<pid>.scope   one run: same caps again (so a
#                                    missing slice file never means uncapped)
#                                    + the run-time limit (whole scope killed)
# Separate from karnakfpm-<user>.slice: an account's PHP and its cron jobs
# each get the budget, php-usage counts PHP only, and stopping an account's
# FPM slice (pool moved to shared) never kills a running job.
KC_SYSD=/etc/systemd/system
KC_RUNTIME=21600   # 6 h, then every process the run started is stopped
# CPU: half the cores (at least one). Memory: a quarter of the RAM, 256M-4G.
# Tasks: composer/wp-cli/imagick threads, never a fork bomb.
# No MemoryHigh (unlike PHP): a job past it is throttled, not killed — it
# sat at the cap for the whole run (TEST, 2026-10-31). Over MemoryMax = the
# kernel kills it at once and the log says so.
kc_caps() {
  local ram cpus
  # nosemgrep: bash.lang.correctness.unquoted-expansion.unquoted-command-substitution-in-command -- arithmetic context, no word splitting
  ram=$(( $(awk '/^MemTotal:/{print $2}' /proc/meminfo) / 1024 ))
  cpus="$(nproc)"
  KC_MEMMAX=$(( ram / 4 ))
  (( KC_MEMMAX < 256 )) && KC_MEMMAX=256
  (( KC_MEMMAX > 4096 )) && KC_MEMMAX=4096
  KC_CPU=$(( cpus / 2 * 100 )); (( KC_CPU < 100 )) && KC_CPU=100
  KC_TASKS=256
}
KC_PARENT='# managed by cron-caps.sh — every hosting account'"'"'s cron jobs, together
[Unit]
Description=KarnakPanel hosting accounts (cron jobs)

[Slice]
CPUWeight=30
IOWeight=30
MemoryMax=50%'
kc_slice_text() { # <user>  (kc_caps first)
  cat <<EOF
# managed by cron-caps.sh — cron job limits of hosting account $1
[Unit]
Description=KarnakPanel account $1 (cron jobs)

[Slice]
CPUQuota=${KC_CPU}%
MemoryMax=${KC_MEMMAX}M
TasksMax=$KC_TASKS
EOF
}
# write a unit file only when it differs; prints 1 when it changed
kc_put() { # <file> <text>
  if [[ -f "$1" && "$(cat "$1")" == "$2" ]]; then echo 0; return 0; fi
  printf '%s\n' "$2" > "$1.tmp" && chmod 644 "$1.tmp" && mv -f -- "$1.tmp" "$1" || return 1
  echo 1
}
kc_slice_put() { # <user> — parent + account slice; daemon-reload on change
  local a b
  kc_caps
  a="$(kc_put "$KC_SYSD/karnakcron.slice" "$KC_PARENT")" || return 1
  b="$(kc_put "$KC_SYSD/karnakcron-$1.slice" "$(kc_slice_text "$1")")" || return 1
  [[ "$a$b" == 00 ]] || systemctl daemon-reload
}
kc_slice_drop() { # <user> — after the account's last job went
  [[ -f "$KC_SYSD/karnakcron-$1.slice" ]] || return 0
  rm -f -- "$KC_SYSD/karnakcron-$1.slice"
  # an empty slice stays loaded after its file goes; stop it too
  systemctl stop "karnakcron-$1.slice" >/dev/null 2>&1 || true
  systemctl daemon-reload
}
# RuntimeMaxSec= + OOMPolicy= on a scope: systemd 255 (24.04 / Debian 13).
# Older (22.04's 249) falls back to timeout(1) inside the scope — that one
# misses processes that left its process group; the scope caps still hold.
# OOMPolicy=continue: the kernel kills only the process over the memory cap,
# the wrapper logs its exit 137 + output (default = the whole run stopped).
kc_scope_new() { local v; v="$(systemctl --version 2>/dev/null | awk 'NR==1{print $2}')"; [[ "$v" =~ ^[0-9]+$ ]] && (( v >= 253 )); }
