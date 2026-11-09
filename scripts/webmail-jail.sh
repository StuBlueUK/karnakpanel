#!/usr/bin/env bash
# webmail-jail.sh — fail2ban [webmail] jail: bans IPs password-guessing against
# Roundcube webmail (:8091). Webmail login had NO brute-force cover before: the
# [dovecot] jail only ever sees these logins as 127.0.0.1 (Roundcube connects
# to Dovecot locally, and 127.0.0.1 is fail2ban's ignoreip) and the [lsws]
# jail on 8091 watches OLS error.log, not Roundcube's application log.
# Roundcube's own errors.log carries the real client IP (log_driver=file,
# log_logins=false -> rcube_imap.php logs "Login failed for %s against %s
# from %s"), verified against the pinned upstream 1.7.4 source. Port-scoped
# like weblogin/webscan (ufw-port 8091 only): a webmail ban can never lock
# out SSH, panel or mail.
# Installs the filter + appends the jail to jail.local when missing (a
# hand-tuned section is left alone), reloads, proves the jail is live or
# restores jail.local. Called by the installer (via host-converge) and
# host-converge. Idempotent; quiet when nothing changes.
# LIMIT: behind a proxy without real-IP logging this would ban edge IPs.
set -euo pipefail
FD=/etc/fail2ban/filter.d; F="$FD/skypanel-webmail.conf"; J=/etc/fail2ban/jail.local
[[ -d "$FD" && -f "$J" ]] || exit 0
# the message is "Login failed for <user> against <host> from <ip>. <error>";
# lazy .*? for the timestamp gap and <[^>]+> for the session id only —
# fail2ban 1.0.x misses greedy quantifiers around [...] (see skypanel-weblogin).
WANT='# installed by KarnakPanel (webmail-jail.sh) — Roundcube (:8091) password guessing; the real client IP is in errors.log (dovecot only ever sees 127.0.0.1)
[Definition]
failregex = ^\[.*?\]: <[^>]+> IMAP Error: Login failed for \S+ against \S+ from <HOST>
ignoreregex ='
CHANGED=0; ADDED=0
if [[ ! -f "$F" || "$(cat "$F")" != "$WANT" ]]; then
  printf '%s\n' "$WANT" > "$F.tmp"; chmod 644 "$F.tmp"; chown root:root "$F.tmp"; mv -f "$F.tmp" "$F"
  CHANGED=1
fi
if ! grep -q '^\[webmail\]' "$J"; then
  cp -a "$J" "$J.pre-webmail"
  cat >> "$J" <<'EOF'

# webmail password guessing (webmail-jail.sh): nothing throttled Roundcube
# login before. 8 guesses / 10 min is far past any human typo run.
[webmail]
enabled = true
backend = polling
banaction = ufw-port
port = 8091
filter = skypanel-webmail
logpath = /var/log/roundcube/errors.log
maxretry = 8
findtime = 600
bantime = 3600
EOF
  CHANGED=1; ADDED=1
fi
(( CHANGED )) || exit 0
# fail2ban refuses to start a jail whose logpath matches nothing; the webmail
# user (karnak-webmail) normally creates errors.log on its first error. The
# dir has a default ACL for that user, so a root-touched file is still theirs
# to append — chown anyway when the account exists.
install -d -m 755 /var/log/roundcube
if [[ ! -e /var/log/roundcube/errors.log ]]; then
  touch /var/log/roundcube/errors.log
  if getent passwd karnak-webmail >/dev/null; then chown karnak-webmail: /var/log/roundcube/errors.log; fi
  chmod 640 /var/log/roundcube/errors.log
fi
if systemctl is-active --quiet fail2ban; then
  # full reload: a NEW jail is only picked up that way (bans are kept)
  if timeout 60 fail2ban-client reload >/dev/null 2>&1 && timeout 10 fail2ban-client status webmail >/dev/null 2>&1; then
    rm -f "$J.pre-webmail"
  else
    if (( ADDED )); then
      cat "$J.pre-webmail" > "$J"; rm -f "$J.pre-webmail"
      timeout 60 fail2ban-client reload >/dev/null 2>&1 || true
      echo "webmail-jail: webmail jail did not start — previous jail.local restored"; exit 1
    fi
    echo "webmail-jail: fail2ban reload failed after filter update"; exit 1
  fi
else
  rm -f "$J.pre-webmail"
fi
# legit writer of a watched file (jail.local): re-baseline the tripwire
"$(cd "$(dirname "$0")" && pwd)"/integrity-check.sh --baseline >/dev/null 2>&1 || true
echo "webmail-jail: webmail jail bans Roundcube password guessing on 8091"
