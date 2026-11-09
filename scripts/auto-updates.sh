#!/usr/bin/env bash
# auto-updates.sh get | set <security> <reboot> <all> <hour> | init | precheck | report | regex
# Automatic OS updates = the distro's own unattended-upgrades, run by the
# stock apt-daily-upgrade.timer (~06:00 + random delay). No cron of ours:
# the panel only writes config and two systemd drop-ins.
#   security 1  OS security updates install nightly (default on)
#   reboot   1  ...including packages that need a reboot to take effect
#               (kernel, firmware, microcode, libc, dbus, apparmor, PAM).
#               Default 0: those wait on the Update page for a manual
#               upgrade + reboot. Never reboots by itself (Automatic-Reboot off).
#   all      1  also regular updates + the PHP (ondrej/sury) and OpenLiteSpeed
#               repos (default 0; needs security 1)
#   hour     0-23 | os   start hour (os = the distro timer's own ~06:00)
# Config: /etc/apt/apt.conf.d/52karnakpanel-unattended (sorts after
# 20auto-upgrades + 50unattended-upgrades: its scalars win, lists append).
#   get       security/reboot/all/hour/installed + last<TAB>epoch<TAB>result<TAB>count
#   init      write the defaults once (converge); an existing file is kept
#   precheck  ExecCondition of apt-daily-upgrade: waits for a running backup,
#             skips the night (exit 1) on <1 GB free on / or /var
#   report    ExecStopPost: summarise the run that just ended (panel
#             notification; email only on failure). Deduplicated per run.
#   regex     the needs-a-reboot package regex (system-update.sh status)
set -euo pipefail
CONF=/etc/apt/apt.conf.d/52karnakpanel-unattended
TDROP=/etc/systemd/system/apt-daily-upgrade.timer.d/karnakpanel.conf
SDROP=/etc/systemd/system/apt-daily-upgrade.service.d/karnakpanel.conf
UULOG=/var/log/unattended-upgrades/unattended-upgrades.log
STATE=/var/lib/karnakpanel/auto-updates
SELF=/usr/local/skypanel/scripts/auto-updates.sh
# packages whose update only takes effect after a reboot (Ubuntu flags
# reboot-required for these: kernel postinst.d hook, libc6/dbus/apparmor/
# libpam0g postinst). POSIX ERE and Python re alike, anchored at the start.
REBOOT_RE='(linux-(image|modules|headers|generic|virtual|kvm|firmware|signed|aws|azure|gcp|oracle|lowlatency|hwe|cloud)|intel-microcode|amd64-microcode|libc6|libc-bin|libc-dev-bin|dbus|libdbus-|apparmor|libapparmor|libpam0g|libpam-modules|libpam-runtime)'
M="${1:-get}"

# value of a "// karnak: k=v" marker in our file
mark() { [[ -f "$CONF" ]] || return 0; sed -n "s|^// karnak: .*\b$1=\([0-9a-z]*\).*|\1|p" "$CONF" 2>/dev/null | head -1; }
uu_on() { [[ "$(apt-config dump 2>/dev/null)" == *$'\nAPT::Periodic::Unattended-Upgrade "1";'* ]]; }

write_conf() { # write_conf <security> <reboot> <all>
  local S="$1" RB="$2" A="$3" T; T="$(mktemp "$CONF.XXXX")"
  {
    echo "// KarnakPanel automatic updates — written by auto-updates.sh (Update page). Edit there."
    echo "// karnak: security=$S reboot=$RB all=$A"
    echo 'APT::Periodic::Update-Package-Lists "1";'
    echo "APT::Periodic::Unattended-Upgrade \"$S\";"
    echo 'Unattended-Upgrade::Automatic-Reboot "false";'
    (( RB )) || printf 'Unattended-Upgrade::Package-Blacklist { "%s"; };\n' "$REBOOT_RE"
    if (( A )); then
      # patterns that match no configured repo are harmless (Ubuntu + Debian
      # in one list); LiteSpeed's repo has no Origin field: matched by site
      cat <<'EOF'
Unattended-Upgrade::Origins-Pattern {
  "origin=Ubuntu,archive=${distro_codename}-updates";
  "origin=Debian,codename=${distro_codename}-updates";
  "origin=Debian,codename=${distro_codename},label=Debian";
  "origin=LP-PPA-ondrej-php";
  "origin=deb.sury.org";
  "origin=MariaDB";
  "site=rpms.litespeedtech.com";
};
EOF
    fi
  } > "$T"
  chmod 644 "$T"; mv -f "$T" "$CONF"
}

write_timer() { # write_timer <hour|os>
  if [[ "$1" == os ]]; then
    [[ -f "$TDROP" ]] || return 0
    rm -f "$TDROP"
  else
    mkdir -p "${TDROP%/*}"
    # empty OnCalendar= first: drop-ins ADD to the stock schedule otherwise
    printf '[Timer]\nOnCalendar=\nOnCalendar=*-*-* %02d:00\nRandomizedDelaySec=20m\n' "$((10#$1))" > "$TDROP.tmp"
    cmp -s "$TDROP.tmp" "$TDROP" && { rm -f "$TDROP.tmp"; return 0; }
    mv -f "$TDROP.tmp" "$TDROP"
  fi
  systemctl daemon-reload
  systemctl restart apt-daily-upgrade.timer 2>/dev/null || true
}

write_hooks() {
  mkdir -p "${SDROP%/*}"
  # guarded: a missing panel script must never break the distro's own job
  cat > "$SDROP.tmp" <<EOF
# KarnakPanel: skip low-disk nights, wait for backups, report each run
[Service]
ExecCondition=/bin/sh -c '[ ! -x $SELF ] || exec $SELF precheck'
ExecStopPost=-/bin/sh -c '[ ! -x $SELF ] || exec $SELF report'
EOF
  if cmp -s "$SDROP.tmp" "$SDROP"; then rm -f "$SDROP.tmp"; else mv -f "$SDROP.tmp" "$SDROP"; systemctl daemon-reload; fi
}

sql() { MYSQL_PWD="$(cat /root/.skypanel-dbpass 2>/dev/null)" mysql -uskypanel skypanel -N -e "$1"; }
# notify <title> <body> — quotes and backslashes stripped (text comes from apt logs)
notify() {
  local t b; t="$(tr -d "'\\\\" <<<"$1" | head -c 180)"; b="$(tr -d "'\\\\" <<<"$2" | head -c 2000)"
  sql "INSERT INTO notifications(user_id,type,title,body) VALUES(NULL,'updates','$t','$b')" 2>/dev/null || true
}

case "$M" in
regex) echo "$REBOOT_RE" ;;
get)
  dpkg -s unattended-upgrades >/dev/null 2>&1 && I=1 || I=0
  uu_on && S=1 || S=0
  RB="$(mark reboot)"; A="$(mark all)"
  [[ -f "$CONF" ]] || { RB=1; A=0; }   # not set up yet: distro behaviour
  H=os; [[ -f "$TDROP" ]] && H="$(sed -n 's/^OnCalendar=\*-\*-\* \([0-9][0-9]\):00$/\1/p' "$TDROP" | head -1)"
  echo -e "installed\t$I"; echo -e "security\t$S"; echo -e "reboot\t${RB:-0}"; echo -e "all\t$(( S ? ${A:-0} : 0 ))"; echo -e "hour\t${H:-os}"
  NX="$(systemctl show apt-daily-upgrade.timer -p NextElapseUSecRealtime --value 2>/dev/null || true)"
  [[ -n "$NX" && "$NX" != n/a ]] && echo -e "next\t$(date -d "$NX" +%s 2>/dev/null || echo 0)"
  [[ -f "$STATE" ]] && sed -n 's/^last\t/last\t/p' "$STATE" | head -1
  exit 0
  ;;
set)
  S="${2:-}"; RB="${3:-}"; A="${4:-}"; H="${5:-}"
  [[ "$S$RB$A" =~ ^[01]{3}$ ]] || { echo "bad flags"; exit 1; }
  [[ "$H" == os || "$H" =~ ^([01]?[0-9]|2[0-3])$ ]] || { echo "bad hour"; exit 1; }
  dpkg -s unattended-upgrades >/dev/null 2>&1 || { echo "unattended-upgrades is not installed (apt install unattended-upgrades)"; exit 1; }
  (( S )) || A=0
  write_conf "$S" "$RB" "$A"
  write_timer "$H"
  write_hooks
  # the result, as apt sees it — refuse to claim a state it didn't take
  D="$(apt-config dump 2>/dev/null)"
  [[ "$D" == *$'\nAPT::Periodic::Unattended-Upgrade "'"$S"'";'* ]] || { echo "apt did not take the setting (another apt.conf.d file overrides it?)"; exit 1; }
  echo "saved: security=$S reboot=$RB all=$A hour=$H"
  ;;
init)
  dpkg -s unattended-upgrades >/dev/null 2>&1 || { echo "auto-updates: unattended-upgrades not installed — skipped"; exit 0; }
  if [[ ! -f "$CONF" ]]; then
    # keep the owner's on/off; new defaults: reboot-needing updates manual
    uu_on && S=1 || S=0
    write_conf "$S" 0 0
    echo "auto-updates: defaults written (security=$S, reboot-needing updates manual)"
  fi
  write_hooks
  ;;
precheck)
  # a nightly backup reads every home + dumps MariaDB: let it finish (max 2h).
  # backup-all holds its run lock end to end; the per-account lock is free
  # between two accounts (and covers a single on-demand backup)
  flock -w 7200 /run/lock/karnak-backup-all.lock true 2>/dev/null || true
  flock -w 7200 /run/lock/karnak-backup.lock true 2>/dev/null || true
  for d in / /var; do
    F=$(df -Pk "$d" 2>/dev/null | awk 'NR==2{print $4}')
    if [[ "$F" =~ ^[0-9]+$ ]] && (( F < 1048576 )); then
      notify "Automatic updates skipped: low disk space" "Less than 1 GB free on $d — tonight's automatic updates did not run. Free space, or upgrade from the Update page."
      echo "skipped: <1 GB free on $d"; exit 1
    fi
  done
  exit 0
  ;;
report)
  [[ -f "$UULOG" ]] || exit 0
  # the run that just ended = from the last "Starting" line
  RUN="$(awk '/INFO Starting unattended upgrades script/{b=""} {b=b $0 "\n"} END{printf "%s", b}' "$UULOG" | tail -400)"
  [[ -n "$RUN" ]] || exit 0
  TS="$(head -1 <<<"$RUN" | cut -c1-19)"
  EP="$(date -d "$TS" +%s 2>/dev/null || echo 0)"
  [[ "$(sed -n 's/^reported\t//p' "$STATE" 2>/dev/null)" == "$EP" ]] && exit 0
  PK="$(sed -n 's/.*INFO Packages that will be upgraded: //p' <<<"$RUN" | tr ' ' '\n' | grep -v '^$' | sort -u | tr '\n' ' ' || true)"
  N=$(wc -w <<<"$PK")
  if grep -qE ' ERROR |Installing the upgrades failed|dpkg returned an error' <<<"$RUN"; then R=failed
  elif (( N )); then R=installed
  else R=nothing; fi
  ERR="$(grep -E ' ERROR ' <<<"$RUN" | head -5 | cut -c1-300 || true)"
  # security updates still waiting that this run may not install (reboot-needing)
  WAIT="$(apt-get -s -o Debug::NoLocking=1 upgrade 2>/dev/null | awk '/^Inst / && /-security|Debian-Security/ {print $2}' | grep -E "^$REBOOT_RE" | sort -u | head -20 | tr '\n' ' ' || true)"
  NW=$(wc -w <<<"$WAIT")
  case "$R" in
    installed) notify "Automatic updates installed $N package(s)" "$PK" ;;
    failed)
      notify "Automatic updates FAILED" "${ERR:-see $UULOG}"
      HOST="$(hostname -f 2>/dev/null || hostname)"
      TO="$(sql "SELECT value FROM settings WHERE name='alert_email'" 2>/dev/null | grep -E '^[^@[:space:]]+@[^@[:space:]]+\.[a-z]{2,}$' | grep -vE '@localhost$' | head -1 || true)"
      if [[ -n "$TO" ]]; then
        /usr/sbin/sendmail -t 2>/dev/null <<EOF || true
To: $TO
From: KarnakPanel Updates <watchdog@$HOST>
Auto-Submitted: auto-generated
Subject: Automatic updates failed on $HOST

Tonight's automatic OS updates did not complete.

$ERR

Details: $UULOG on the server, or the Update page in the panel
(System update) to see what is still waiting and run it by hand.
EOF
      fi ;;
  esac
  # reboot-needing security fixes: once per distinct set
  if (( NW )) && [[ "$(sed -n 's/^waitnote\t//p' "$STATE" 2>/dev/null)" != "$WAIT" ]]; then
    notify "$NW security update(s) need a manual install + reboot" "$WAIT— Update page: Upgrade now, then Reboot when convenient."
  fi
  [[ -f /run/reboot-required && "$R" == installed ]] && notify "Reboot required after automatic updates" "$(sort -u /run/reboot-required.pkgs 2>/dev/null | head -10 | tr '\n' ' ')"
  mkdir -p "${STATE%/*}"
  printf 'reported\t%s\nlast\t%s\t%s\t%s\nwaitnote\t%s\n' "$EP" "$EP" "$R" "$N" "$WAIT" > "$STATE.tmp"
  mv -f "$STATE.tmp" "$STATE"
  ;;
*) echo "usage: auto-updates.sh get|set <security 0|1> <reboot 0|1> <all 0|1> <hour 0-23|os>|init|precheck|report|regex"; exit 1 ;;
esac
