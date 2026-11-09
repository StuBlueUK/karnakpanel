# daemon-caps.sh — sourced (never run): resource limits for supervised
# customer apps (app-daemon.sh). Same shape as cron-caps.sh, own tree:
#   karnakapp.slice                every account's daemons together: below
#                                  system services and PHP on CPU/IO, never
#                                  more than 30% of RAM (cron's tree has 50%:
#                                  the two together still leave the box room)
#     karnakapp-<user>.slice       one account's daemons together
#       karnak-<user>-<name>.service   one daemon: the same caps again on the
#                                  unit (a missing slice file = systemd makes
#                                  an implicit, uncapped one)
# The budget per account matches cron's (kc_caps: half the cores, a quarter of
# the RAM 256M-4G); daemons and cron jobs are separate budgets. No MemoryHigh
# (cron lesson: throttled, not killed). Over MemoryMax = the kernel kills it,
# Restart=always brings it back, the crash-loop guard stops a repeat offender.
. "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/cron-caps.sh"
KA_TASKS=256
KA_PARENT='# managed by daemon-caps.sh — every hosting account'"'"'s daemons, together
[Unit]
Description=KarnakPanel hosting accounts (daemons)

[Slice]
CPUWeight=30
IOWeight=30
MemoryMax=30%'
ka_slice_text() { # <user>  (kc_caps first)
  cat <<EOF
# managed by daemon-caps.sh — daemon limits of hosting account $1
[Unit]
Description=KarnakPanel account $1 (daemons)

[Slice]
CPUQuota=${KC_CPU}%
MemoryMax=${KC_MEMMAX}M
TasksMax=$KA_TASKS
EOF
}
ka_slice_put() { # <user> — parent + account slice; daemon-reload on change
  local a b
  kc_caps
  a="$(kc_put "$KC_SYSD/karnakapp.slice" "$KA_PARENT")" || return 1
  b="$(kc_put "$KC_SYSD/karnakapp-$1.slice" "$(ka_slice_text "$1")")" || return 1
  [[ "$a$b" == 00 ]] || systemctl daemon-reload
}
ka_slice_drop() { # <user> — after the account's last daemon went
  [[ -f "$KC_SYSD/karnakapp-$1.slice" ]] || return 0
  rm -f -- "$KC_SYSD/karnakapp-$1.slice"
  systemctl stop "karnakapp-$1.slice" >/dev/null 2>&1 || true
  systemctl daemon-reload
}
