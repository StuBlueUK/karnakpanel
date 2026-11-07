#!/usr/bin/env bash
# security-audit.sh — root-only checks for Admin › Security Advisor. No args.
# One line per check: key<TAB>status<TAB>detail, status = ok|warn|bad|unknown.
# Reads LIVE state (sshd -T, ufw, iptables), never the configured intent.
# Offline and quick: no network, no apt lock (simulated upgrade on the cached
# lists), every slow call under timeout. A check that can't tell = unknown.
set -uo pipefail
out() { printf '%s\t%s\t%s\n' "$1" "$2" "${3//$'\t'/ }"; }
HERE="$(dirname "$(readlink -f "$0")")"

# SSH: effective config (drop-ins in sshd_config.d included)
if T="$(timeout 5 sshd -T 2>/dev/null)" && [[ -n "$T" ]]; then
  pw="$(awk '$1=="passwordauthentication"{print $2}' <<<"$T")"
  kb="$(awk '$1=="kbdinteractiveauthentication"{print $2}' <<<"$T")"
  rl="$(awk '$1=="permitrootlogin"{print $2}' <<<"$T")"
  keys=0; [[ -f /root/.ssh/authorized_keys ]] && keys="$(grep -cE '^(ssh-ed25519|ssh-rsa|ecdsa-sha2|sk-)' /root/.ssh/authorized_keys || true)"
  if [[ "$pw" == no && "$kb" != yes ]]; then out ssh_password ok "password logins off ($keys root key(s))"
  else out ssh_password warn "password logins allowed (PasswordAuthentication $pw) — $keys root key(s) installed"; fi
  case "$rl" in
    no) out ssh_root ok "root login off" ;;
    prohibit-password|without-password|forced-commands-only) out ssh_root ok "root: keys only" ;;
    *) out ssh_root bad "root may log in with a password (PermitRootLogin $rl)" ;;
  esac
else
  out ssh_password unknown "sshd -T failed"; out ssh_root unknown "sshd -T failed"
fi

# firewall
U="$(timeout 10 ufw status 2>/dev/null)" || U=""
if [[ -z "$U" ]]; then out ufw unknown "ufw status failed"
elif grep -q '^Status: active' <<<"$U"; then out ufw ok "UFW active"
else out ufw bad "UFW inactive — no ports are filtered"; fi
if [[ -n "$U" ]]; then
  if grep -E '^7080(/tcp)?[[:space:]]+(ALLOW|LIMIT)[[:space:]]+(IN[[:space:]]+)?Anywhere' <<<"$U" >/dev/null; then
    out ols_admin warn "LiteSpeed WebAdmin (7080) is open to everyone"
  else out ols_admin ok "LiteSpeed WebAdmin (7080) not open to everyone"; fi
fi

# fail2ban: running, and the jails that guard logins
if F="$(timeout 5 fail2ban-client status 2>/dev/null)"; then
  J="$(sed -n 's/.*Jail list:[[:space:]]*//p' <<<"$F" | tr -d ' ')"
  miss=""; for j in sshd panel; do [[ ",$J," == *",$j,"* ]] || miss="$miss $j"; done
  n="$(tr ',' '\n' <<<"$J" | grep -c . || true)"
  if [[ -z "$miss" ]]; then out fail2ban ok "$n jails running"
  else out fail2ban warn "$n jails running; missing:$miss"; fi
else out fail2ban bad "fail2ban not running"; fi

# outbound SMTP restriction (live rules, not the stored mode)
if S="$("$HERE/smtp-restrict.sh" status 2>/dev/null)"; then
  live="$(awk -F'\t' '$1=="live"{print $2}' <<<"$S")"; mode="$(awk -F'\t' '$1=="mode"{print $2}' <<<"$S")"
  case "$live" in
    mx) out smtp ok "hosting accounts can't reach other servers' port 25" ;;
    all) out smtp ok "hosting accounts can't reach other mail servers on any SMTP port" ;;
    off) if [[ "$mode" == off ]]; then out smtp warn "hosting accounts can send straight to other mail servers"
         else out smtp bad "set to $mode but the rules are not loaded (ufw disabled or reloaded without the hook?)"; fi ;;
    *) out smtp unknown "status unreadable" ;;
  esac
else out smtp unknown "smtp-restrict.sh status failed"; fi

# PHP: every account on its own master with CPU/RAM/process caps (states only:
# the memory read takes minutes at 1000 accounts while systemd is reloading)
if S="$(timeout 20 "$HERE/fpm-account.sh" status states 2>/dev/null)"; then
  own="$(awk -F'\t' '$2!="shared" && $2!="off"' <<<"$S" | grep -c . || true)"
  shared="$(awk -F'\t' '$2=="shared"' <<<"$S" | grep -c . || true)"
  down="$(awk -F'\t' '$2!="shared" && $2!="off" && $2!="active" && $2!="idle"{print $1}' <<<"$S" | head -5 | paste -sd' ' -)"
  if [[ -n "$down" ]]; then out fpm_caps bad "PHP master not running for: $down"
  elif (( shared > 0 )); then out fpm_caps warn "$shared account(s) share a PHP master: no CPU/RAM cap, one site can starve the server"
  elif (( own > 0 )); then out fpm_caps ok "$own account(s), each on its own PHP master with CPU, RAM and process caps"
  else out fpm_caps ok "no hosting accounts with PHP yet"; fi
else out fpm_caps unknown "systemd did not answer in 20 s (busy reloading?) — reload this page"; fi

# MariaDB on the network
if L="$(ss -Hltn 2>/dev/null)"; then
  if awk '{print $4}' <<<"$L" | grep -Eq '^(0\.0\.0\.0|\*|\[::\]):3306$'; then out mysql_net warn "MariaDB listens on every interface (3306)"
  else out mysql_net ok "MariaDB local only"; fi
else out mysql_net unknown "ss failed"; fi

# packages: cached lists only (apt-get -s takes no lock)
if A="$(timeout 30 apt-get -s -o Debug::NoLocking=1 upgrade 2>/dev/null)"; then
  all="$(grep -c '^Inst ' <<<"$A" || true)"; sec="$(grep '^Inst ' <<<"$A" | grep -ci 'security' || true)"
  if (( sec > 0 )); then out updates warn "$sec security update(s) pending ($all in total)"
  elif (( all > 0 )); then out updates ok "no security updates pending ($all other)"
  else out updates ok "system up to date"; fi
  # newest Release file = last successful `apt update`
  st="$(find /var/lib/apt/lists -maxdepth 1 -name '*Release' -printf '%T@\n' 2>/dev/null | sort -n | tail -1)"; st="${st%.*}"
  if [[ "$st" =~ ^[0-9]+$ ]] && (( ( $(date +%s) - st ) / 86400 > 7 )); then
    # nosemgrep: bash.lang.correctness.unquoted-expansion.unquoted-command-substitution-in-command -- arithmetic context, no word splitting
    out apt_lists warn "package lists are $(( ( $(date +%s) - st ) / 86400 )) days old — the counts above may be stale"
  fi
else out updates unknown "apt simulation failed or timed out"; fi
# effective value (the panel's 52karnakpanel-unattended overrides 20auto-upgrades)
if [[ "$(apt-config dump 2>/dev/null)" == *$'\nAPT::Periodic::Unattended-Upgrade "1";'* ]]; then out auto_updates ok "unattended security upgrades on"
else out auto_updates warn "unattended security upgrades off"; fi
if [[ -f /run/reboot-required ]]; then
  pk="$(head -c 200 /run/reboot-required.pkgs 2>/dev/null | tr '\n' ' ')"
  out reboot warn "reboot pending${pk:+ ($pk)}"
else out reboot ok "no reboot pending"; fi
exit 0
