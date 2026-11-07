#!/usr/bin/env bash
# weblogin-slow-jail.sh — fail2ban [weblogin-slow] jail: bans SLOW WordPress
# password guessing. [weblogin] (15 POSTs / 5 min) only sees floods; prod
# 2026-10-28..11-02 had one IP POST wp-login.php 3,216 times over 6 days at
# ~30/h — always under the flood rule, never banned. This counts FAILED logins
# only (WordPress answers a wrong password with 200, a good one with a 302),
# wp-login.php only (xmlrpc has legit app/Jetpack callers): 30 in 6 h -> 24 h
# ban, repeats double up to a week (history: dbpurgeage 8d, scan-jail.sh).
# The count is per IP across every site on the box — an agency logging into
# many customer sites succeeds (302), so it never adds up.
# Shared ban action (ufw-port, rewritten here): ufw keeps ONE rule per
# ip+port and deletes by spec, so when [weblogin] (1 h) and this jail (24 h)
# both banned an IP, weblogin's unban silently lifted the 24 h ban (same for
# webscan). The action now leaves a per-jail marker and deletes the rule only
# when the last jail unbans, and bans with `ufw prepend` (`insert 1` failed
# for every IPv6 address). Only a stock (shipped) ufw-port.conf is replaced.
# Port-scoped (80/443 only — SSH/panel/mail stay up). Section lives in a
# jail.d drop-in (jail.local untouched); on a failed reload every file this
# run created is removed again. Called by the installer and host-converge.
# NOTE: polling reads the logs from the top: the first start may ban IPs
# from the last 6 h straight away. Idempotent; quiet when nothing changes.
set -euo pipefail
FD=/etc/fail2ban/filter.d; AD=/etc/fail2ban/action.d; JD=/etc/fail2ban/jail.d
[[ -d "$FD" && -f /etc/fail2ban/jail.local ]] || exit 0
# lazy .*? timestamp gap + ^'? (stray quote) as in skypanel-weblogin
FW='# installed by KarnakPanel (weblogin-slow-jail.sh) — failed wp-login.php POSTs
[Definition]
failregex = ^'"'"'?<HOST> \S+ \S+ \[.*?\] "POST /wp-login\.php[^"]*" 200
ignoreregex ='
AW="$(cat <<'ACTEOF'
# KarnakPanel: port-scoped ufw ban. Stock `ufw` action ignores the jail port
# and firewalls the whole IP (see gotchas: SSH lockout). This one denies only
# <port>. Delete is BY SPEC, never by rule number (numbers shift on delete).
# `proto tcp` is REQUIRED — ufw refuses multi-port rules without it.
# Shared rule: ufw keeps ONE rule per ip+port (a second insert — even as
# reject — is skipped), so weblogin (1 h), weblogin-slow (24 h) and webscan
# banning one IP share it. Each jail leaves a marker; the rule is deleted
# only when the last jail holding the IP unbans. Markers live in /run: after
# a reboot fail2ban's re-bans recreate them, and none left = delete.
# `prepend`, not `insert 1`: insert 1 fails for an IPv6 ban ("Invalid position
# '1'" — rule 1 is IPv4), so IPv6 bans never reached the firewall.
# (single source: also embedded in scripts/weblogin-slow-jail.sh — keep equal)
[Definition]
actionstart =
actionstop =
actioncheck = ufw status | grep -q "Status: active"
actionban = d="/run/karnak-f2b-ufw/<ip>_<port>"; mkdir -p "$d" && touch "$d/<name>"
            ufw prepend deny from <ip> to any port <port> proto tcp
actionunban = d="/run/karnak-f2b-ufw/<ip>_<port>"; rm -f "$d/<name>"
              if [ -z "$(ls -A "$d" 2>/dev/null)" ]; then rmdir "$d" 2>/dev/null; ufw delete deny from <ip> to any port <port> proto tcp; fi
ACTEOF
)"
# the action as shipped up to 1.5.53 (replaced; a hand-edited one is left alone)
AOLD=565067202ed21cf1fd02e762206a627cab7fd05b4e32f4b9a72792e41bc1fa24
JW='# installed by KarnakPanel (weblogin-slow-jail.sh) — slow WordPress password guessing
[weblogin-slow]
enabled = true
backend = polling
banaction = ufw-port
port = 80,443
filter = skypanel-weblogin-slow
logpath = /var/log/karnakpanel/*.access.log
maxretry = 30
findtime = 21600
bantime = 86400
bantime.increment = true
bantime.maxtime = 604800'
CHANGED=0; NEW=()
A="$AD/ufw-port.conf"; AOK=1
if [[ -f "$A" && "$(cat "$A")" != "$AW" && "$(sha256sum < "$A" | cut -d' ' -f1)" != "$AOLD" ]]; then
  AOK=0; echo "weblogin-slow-jail: $A is hand-edited — left alone (jails banning one IP may lift each other's ban)"
fi
L=("$FD/skypanel-weblogin-slow.conf|$FW" "$JD/karnakpanel-weblogin-slow.local|$JW"); (( AOK )) && L=("$A|$AW" "${L[@]}")
for P in "${L[@]}"; do
  T="${P%%|*}"; W="${P#*|}"
  [[ -f "$T" && "$(cat "$T")" == "$W" ]] && continue
  [[ -f "$T" ]] || NEW+=("$T")
  [[ "$T" == "$A" && -f "$T" ]] && cp -a "$T" "$T.pre-karnak"
  install -d -m 755 "$(dirname "$T")"
  printf '%s\n' "$W" > "$T.tmp"; chmod 644 "$T.tmp"; chown root:root "$T.tmp"; mv -f "$T.tmp" "$T"
  CHANGED=1
done
(( CHANGED )) || exit 0
# the glob must match something or fail2ban refuses to start (every jail)
install -d -m 755 /var/log/karnakpanel; touch /var/log/karnakpanel/placeholder.access.log
if systemctl is-active --quiet fail2ban; then
  # full reload: a NEW jail is only picked up that way (bans are kept)
  if ! { timeout 90 fail2ban-client reload >/dev/null 2>&1 && timeout 10 fail2ban-client status weblogin-slow >/dev/null 2>&1; }; then
    (( ${#NEW[@]} )) && rm -f "${NEW[@]}"
    [[ -f "$A.pre-karnak" ]] && mv -f "$A.pre-karnak" "$A"
    timeout 90 fail2ban-client reload >/dev/null 2>&1 || true
    echo "weblogin-slow-jail: jail did not start — new files removed"; exit 1
  fi
fi
rm -f "$A.pre-karnak"
"$(cd "$(dirname "$0")" && pwd)"/integrity-check.sh --baseline >/dev/null 2>&1 || true
echo "weblogin-slow-jail: 30 failed wp-login.php POSTs in 6 h -> 24 h ban on 80/443 (repeats double, up to a week)"
