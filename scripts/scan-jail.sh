#!/usr/bin/env bash
# scan-jail.sh — fail2ban [webscan] jail: bans IPs probing customer sites for
# secrets/VCS dirs (/.env*, /.git/, /.aws/, /api/.env ...). The bot shield 403s
# only the exact `.env`/`.git` endings, so `/.env.local`, `/.env.bak` etc. fell
# through to WordPress's front controller — every probe a PHP request. One
# scanner (prod 2026-11-01, ~115 probes in 6 s over one HTTP/2 connection)
# saturated an account's pool into a 503 burst; weblogin (wp-login/xmlrpc
# POSTs only) never counted it. Real visitors never request these paths.
# LIMIT: ufw accepts ESTABLISHED traffic before user rules, so a ban stops the
# scanner's NEW connections and repeat scans — not a burst already in flight
# on one keep-alive/HTTP2 connection (the shield is what keeps those off PHP).
# Behind a proxy (Cloudflare) without real-IP logging it would ban edge IPs.
# Port-scoped like weblogin (ufw-port: 80/443 only — SSH/panel/mail stay up).
# Installs the filter + appends the jail to jail.local when missing (a
# hand-tuned section is left alone), reloads, proves the jail is live or
# restores jail.local; plus the repeat-ban drop-ins (jail.d/karnakpanel-
# webscan.local, fail2ban.d/karnakpanel.local). Called by the installer and
# host-converge.
# Idempotent; quiet when nothing changes.
# NOTE: our own shield tests from one IP count too (8 probes / 10 min bans).
set -euo pipefail
FD=/etc/fail2ban/filter.d; F="$FD/skypanel-webscan.conf"; J=/etc/fail2ban/jail.local
[[ -d "$FD" && -f "$J" ]] || exit 0
# Access-log lines start with a stray ' (logFormat artifact) — ^'? as in
# skypanel-weblogin. Lazy quantifiers only (fail2ban 1.0.x greedy-[...] quirk).
# The probe dir must END right after the name (.gitignore/.github/.envrc and
# /.well-known never match).
WANT='# installed by KarnakPanel (scan-jail.sh) — secret/VCS path probes on customer sites
[Definition]
failregex = ^'"'"'?<HOST> \S+ \S+ \[.*?\] "[A-Z]+ /(?:[^" ]*?/)?\.(?:env|git|svn|hg|aws|ssh|htpasswd|DS_Store|vscode|docker)(?:[./? ]|")
ignoreregex ='
# Repeat scanners: each new webscan ban of the same IP doubles (1 h, 2 h, 4 h
# ... capped at a week) — swiftnest 2026-11 had one scraper banned 6 times in
# 3 days, back every hour. webscan ONLY, port-scoped (ufw-port): never the
# stock all-jail/all-port recidive, which would turn an owner's mail-client
# exim ban into a week-long outage. fail2ban finds the history in its db, kept
# dbpurgeage (default 1 day) -> 8 days. Unban (Firewall page) deletes the IP's
# db rows, so a false positive starts from 1 h again; ignoreip still applies.
# jail.d/*.local is read after jail.local: overrides hand-tuned sections too.
INC=/etc/fail2ban/jail.d/karnakpanel-webscan.local
INCW='# installed by KarnakPanel (scan-jail.sh) — repeat scanners get longer webscan bans
[webscan]
bantime.increment = true
bantime.maxtime = 604800'
PUR=/etc/fail2ban/fail2ban.d/karnakpanel.local
PURW='# installed by KarnakPanel (scan-jail.sh) — ban history for bantime.increment
[Definition]
dbpurgeage = 8d'
CHANGED=0; ADDED=0; NEWINC=()
for P in "$INC|$INCW" "$PUR|$PURW"; do
  T="${P%%|*}"; W="${P#*|}"
  [[ -f "$T" && "$(cat "$T")" == "$W" ]] && continue
  [[ -f "$T" ]] || NEWINC+=("$T")
  install -d -m 755 "$(dirname "$T")"
  printf '%s\n' "$W" > "$T.tmp"; chmod 644 "$T.tmp"; chown root:root "$T.tmp"; mv -f "$T.tmp" "$T"
  CHANGED=1
done
if [[ ! -f "$F" || "$(cat "$F")" != "$WANT" ]]; then
  printf '%s\n' "$WANT" > "$F.tmp"; chmod 644 "$F.tmp"; chown root:root "$F.tmp"; mv -f "$F.tmp" "$F"
  CHANGED=1
fi
if ! grep -q '^\[webscan\]' "$J"; then
  cp -a "$J" "$J.pre-webscan"
  cat >> "$J" <<'EOF'

# secret/VCS probes (/.env*, /.git/ ...) on customer sites (scan-jail.sh).
# Real visitors never request these; scanners send hundreds in seconds.
[webscan]
enabled = true
backend = polling
banaction = ufw-port
port = 80,443
filter = skypanel-webscan
logpath = /var/log/karnakpanel/*.access.log
maxretry = 8
findtime = 600
bantime = 3600
EOF
  CHANGED=1; ADDED=1
fi
(( CHANGED )) || exit 0
# the glob must match something or fail2ban refuses to start (every jail)
install -d -m 755 /var/log/karnakpanel; touch /var/log/karnakpanel/placeholder.access.log
if systemctl is-active --quiet fail2ban; then
  # full reload: a NEW jail is only picked up that way (bans are kept).
  # stderr: the stock "allowipv6 not defined" warning on every reload
  if timeout 60 fail2ban-client reload >/dev/null 2>&1 && timeout 10 fail2ban-client status webscan >/dev/null 2>&1; then
    rm -f "$J.pre-webscan"
    # a reload doesn't always re-apply these on a running jail/server: set them live too
    timeout 10 fail2ban-client set webscan bantime.increment true >/dev/null 2>&1 || true
    timeout 10 fail2ban-client set webscan bantime.maxtime 604800 >/dev/null 2>&1 || true
    timeout 10 fail2ban-client set dbpurgeage 691200 >/dev/null 2>&1 || true
  else
    # never leave a NEW drop-in behind that may be what broke the reload
    (( ${#NEWINC[@]} )) && rm -f "${NEWINC[@]}"
    if (( ADDED )); then
      cat "$J.pre-webscan" > "$J"; rm -f "$J.pre-webscan"
      timeout 60 fail2ban-client reload >/dev/null 2>&1 || true
      echo "scan-jail: webscan jail did not start — previous jail.local restored"; exit 1
    fi
    echo "scan-jail: fail2ban reload failed after filter update"; exit 1
  fi
else
  rm -f "$J.pre-webscan"
fi
# legit writer of a watched file (jail.local): re-baseline the tripwire
"$(cd "$(dirname "$0")" && pwd)"/integrity-check.sh --baseline >/dev/null 2>&1 || true
echo "scan-jail: webscan jail bans .env/.git probers on 80/443 (repeat bans double, up to a week)"
