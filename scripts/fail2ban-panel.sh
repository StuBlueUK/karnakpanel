#!/usr/bin/env bash
# fail2ban-panel.sh [filter-src] — keep the [panel] jail current on this box.
# The filter matches the lines Helpers::log() writes to panel.log: failed
# login, login throttled and — since the 2026-11-08 sweep (M1) — API auth
# failed. API token failures used to be silent, so a token-guessing client
# could never trip the jail; they now log "API auth failed from <ip> (reason)"
# (fixed reason text, never client input — see ApiController::apiAuthFailed).
#
# Writes the filter when its content differs (the installer ships the same
# file; tests/run.php pins the two equal), appends the jail when missing, pins
# the file jails to polling (fail2ban 1.x defaults to the journal and silently
# watches nothing), reloads, and proves the jail is live — restoring
# jail.local and failing loudly if it is not. Idempotent; quiet when nothing
# changes. Called by the installer and by host-converge.sh, so existing boxes
# pick up filter changes on the next update/converge.
# LIMIT: behind a proxy without real-IP logging this bans edge IPs.
set -euo pipefail
FD=/etc/fail2ban/filter.d; F="$FD/skypanel-panel.conf"; J=/etc/fail2ban/jail.local
[[ -d "$FD" ]] || exit 0
WANT="$(cat <<'PJFEOF'
# KarnakPanel fail2ban filter for the panel itself (:8090). Matches the lines
# Helpers::log() writes to panel.log, e.g.:
#   <ts> failed login for 'admin' from 192.0.2.10
#   <ts> login throttled for 192.0.2.10 (8 recent failures)
#   <ts> API auth failed from 192.0.2.10 (unknown or expired token)
#   <ts> failed totp for uid 75 from 192.0.2.10
# The API line made token guessing visible to the firewall (it used to be
# silent, so the jail could never trigger — security sweep 2026-11-08 M1).
# Second-factor failures (totp/passkey) count too: hammering a code is always
# hostile, and the in-app bucket alone was invisible to the firewall.
[Definition]
failregex = ^.* failed login for .* from <HOST>$
            ^.* login throttled for <HOST>\s.*$
            ^.* API auth failed from <HOST>\s.*$
            ^.* failed (?:totp|passkey) for uid [0-9]+ from <HOST>$
ignoreregex =
PJFEOF
)"
# an explicit source wins (the installer passes its own copy); the two must
# stay identical — tests/run.php compares them.
SRC="${1:-}"
if [[ -n "$SRC" ]]; then
  [[ -f "$SRC" ]] || { echo "no such filter: $SRC"; exit 1; }
  WANT="$(cat "$SRC")"
fi
CHANGED=0; ADDED=0; JAILCHANGED=0
if [[ "$(cat "$F" 2>/dev/null || true)" != "$WANT" ]]; then
  printf '%s\n' "$WANT" > "$F.tmp"; chmod 644 "$F.tmp"; chown root:root "$F.tmp"; mv -f "$F.tmp" "$F"
  CHANGED=1
fi
[[ -f "$J" ]] || exit 0
if ! grep -q '^\[panel\]' "$J"; then
  cp -a "$J" "$J.pre-panel"
  cat >> "$J" <<'EOF'

# the panel itself (:8090) — installer-managed section, do not hand-edit
[panel]
enabled = true
backend = polling
port = 8090
filter = skypanel-panel
logpath = /var/log/karnakpanel/panel.log
maxretry = 10
EOF
  CHANGED=1; ADDED=1; JAILCHANGED=1
fi
# fail2ban 1.x defaults to the systemd journal, which silently blinds every
# file-log jail (sshd survives via journal; exim/lsws/vsftpd/dovecot/panel do
# not). Pin the file jails to polling so their logpaths are actually watched.
# Rewrite only when a jail really lacks the line — an unconditional write would
# churn jail.local's mtime and trip the integrity tripwire on every converge.
PIN="$(python3 - "$J" <<'PYEOF'
import re, sys
p = sys.argv[1]
src = open(p).read()
out = src
for jail in ['lsws', 'panel', 'phpmyadmin', 'exim', 'dovecot', 'vsftpd']:
    m = re.search(r'(\[%s\]\n(?:.*\n)*?)(?=^\[|\Z)' % jail, out, re.M)
    if m and 'backend' not in m.group(1):
        out = out.replace(m.group(1), m.group(1).replace('enabled = true\n', 'enabled = true\nbackend = polling\n', 1), 1)
if out != src:
    open(p, 'w').write(out)
    print('changed')
else:
    print('same')
PYEOF
)"
[[ "$PIN" == changed ]] && { CHANGED=1; JAILCHANGED=1; } || true
(( CHANGED )) || exit 0
if systemctl is-active --quiet fail2ban; then
  # full reload: a NEW jail is only picked up that way (bans are kept)
  if timeout 60 fail2ban-client reload >/dev/null 2>&1 && timeout 10 fail2ban-client status panel >/dev/null 2>&1; then
    rm -f "$J.pre-panel"
  else
    if (( ADDED )); then
      cat "$J.pre-panel" > "$J"; rm -f "$J.pre-panel"
      timeout 60 fail2ban-client reload >/dev/null 2>&1 || true
      echo "fail2ban-panel: [panel] jail did not start — previous jail.local restored"; exit 1
    fi
    echo "fail2ban-panel: fail2ban reload failed after filter update"; exit 1
  fi
else
  rm -f "$J.pre-panel"
fi
# legit writer of a watched file (jail.local): re-baseline the tripwire
if (( JAILCHANGED )); then
  "$(cd "$(dirname "$0")" && pwd)"/integrity-check.sh --baseline >/dev/null 2>&1 || true
fi
echo "fail2ban-panel: [panel] jail bans login + API auth guessing on 8090"
