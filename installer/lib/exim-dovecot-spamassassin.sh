#!/usr/bin/env bash
# Exim4 + Dovecot + SpamAssassin (spamd)
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
# NOTE: -heavy (not -light): the panel's SA ACLs ('warn spam = ...') need content-scanning support
# dovecot-lmtpd is a SEPARATE package (sieve-filtered mail arrives via LMTP —
# without it dovecot refuses to start once protocols include lmtp)
apt-get install -y exim4-daemon-heavy dovecot-imapd dovecot-lmtpd dovecot-mysql dovecot-sieve spamassassin spamd spamc
# Exim: internet site, Maildir delivery
debconf-set-selections <<< "exim4-config exim4/dc_eximconfig_configtype select internet site; mail is sent and received directly using SMTP"
cp -f "$(cd "$(dirname "$0")/../.." && pwd)/installer/configs/exim/exim4.conf" /etc/exim4/exim4.conf 2>/dev/null || true
# Dovecot 2.4 (Debian 13+) has a new config syntax — 2.3 files are fatal
# errors there, so pick the template pair by the installed version.
DVC="$(cd "$(dirname "$0")/../.." && pwd)/installer/configs/dovecot"
if [[ "$(dovecot --version 2>/dev/null)" =~ ^2\.[0-3]\. ]]; then DV_CONF=dovecot.conf; DV_SQL=dovecot-sql.conf.ext
else DV_CONF=dovecot24.conf; DV_SQL=dovecot24-sql.conf.ext; fi
cp -f "$DVC/$DV_CONF" /etc/dovecot/dovecot.conf
# virtual mail user (fixed ids — referenced by dovecot-sql user_query + exim transport)
getent group vmail >/dev/null || groupadd -g 5000 vmail
id vmail >/dev/null 2>&1 || useradd -r -u 5000 -g 5000 -d /var/mail/virtual -s /bin/false -M vmail
mkdir -p /var/mail/virtual && chown vmail:vmail /var/mail/virtual && chmod 755 /var/mail/virtual
# Dovecot SQL reader (SELECT-only on the two mail tables) + rendered sql conf.
# Password persists across re-runs (regenerating while the user exists desyncs
# credentials — same trap as the panel DB users); ALTER converges either way.
if [[ -s /root/.dovecot-dbpass ]]; then DOVE_PASS="$(cat /root/.dovecot-dbpass)"; else DOVE_PASS="$(openssl rand -base64 12 | tr -dc 'a-zA-Z0-9' | head -c 18)"; fi
mysql -uroot <<SQL
CREATE USER IF NOT EXISTS 'dovecot'@'localhost' IDENTIFIED BY '${DOVE_PASS}';
ALTER USER 'dovecot'@'localhost' IDENTIFIED BY '${DOVE_PASS}';
GRANT SELECT ON skypanel.email_accounts TO 'dovecot'@'localhost';
GRANT SELECT ON skypanel.domains TO 'dovecot'@'localhost';
GRANT SELECT ON skypanel.settings TO 'dovecot'@'localhost';
FLUSH PRIVILEGES;
SQL
sed "s/__DOVECOT_DBPASS__/${DOVE_PASS}/" "$DVC/$DV_SQL" > /etc/dovecot/dovecot-sql.conf.ext
chmod 640 /etc/dovecot/dovecot-sql.conf.ext
chgrp dovecot /etc/dovecot/dovecot-sql.conf.ext 2>/dev/null || chgrp mail /etc/dovecot/dovecot-sql.conf.ext 2>/dev/null || true
echo "$DOVE_PASS" > /root/.dovecot-dbpass; chmod 600 /root/.dovecot-dbpass
# render the same DB pass into the Exim quota lookup (single-file config, no update-exim4.conf mangling)
# + the HELO name (MAILNAME from install.sh, else mail.<fqdn>)
MAILNAME="${MAILNAME:-mail.$(hostname -f 2>/dev/null || echo localhost)}"
sed -e "s/__DOVECOT_DBPASS__/${DOVE_PASS}/" -e "s/__MAILNAME__/${MAILNAME}/" "$(cd "$(dirname "$0")/../.." && pwd)/installer/configs/exim/exim4.conf" > /etc/exim4/exim4.conf
chown root:Debian-exim /etc/exim4/exim4.conf; chmod 640 /etc/exim4/exim4.conf
# the rendered config must parse — a typo here ships a dead mail server and
# the install "succeeds" anyway (lived: fused authenticator name, 1.5.11).
exim -bV >/dev/null 2>&1 \
  || { echo "FATAL: rendered /etc/exim4/exim4.conf does not parse — run 'exim -bV' for the line"; exit 1; }
# use our single-file exim config (not Debian split-config) + virtual domain dir referenced by it
mkdir -p /etc/exim4/virtual
touch /etc/exim4/virtual/domains /etc/exim4/virtual/aliases /etc/exim4/virtual/maildirs /etc/exim4/virtual/forwarders /etc/exim4/virtual/remote /etc/exim4/virtual/dkim /etc/exim4/virtual/autoreplies /etc/exim4/virtual/filtered /etc/exim4/virtual/lists /etc/exim4/virtual/listtargets /etc/exim4/virtual/reports
mkdir -p /etc/exim4/virtual/listmembers /var/lib/skypanel/autoreply /var/lib/skypanel/sieve
chown Debian-exim:Debian-exim /var/lib/skypanel/autoreply 2>/dev/null || true
# vacation-reply store (autoreply-sync.sh): Exim writes .once files here
mkdir -p /var/lib/skypanel/autoreply
chown Debian-exim:Debian-exim /var/lib/skypanel/autoreply 2>/dev/null || true
grep -q "dc_use_split_config='false'" /etc/exim4/update-exim4.conf.conf 2>/dev/null || echo "dc_use_split_config='false'" >> /etc/exim4/update-exim4.conf.conf
update-exim4.conf 2>/dev/null || true
# spamd unit renamed across Ubuntu releases (spamassassin.service on 22.04,
# spamd.service on 24.04+) — resolve, never assume (a 22.04 install died on a
# hardcoded `spamd` here).
if systemctl cat spamd >/dev/null 2>&1; then SPAMD_UNIT=spamd; else SPAMD_UNIT=spamassassin; fi
if [[ -f /etc/default/spamassassin ]]; then
  sed -i 's/^ENABLED=.*/ENABLED=1/' /etc/default/spamassassin
  sed -i 's/^CRON=.*/CRON=1/' /etc/default/spamassassin
fi
mkdir -p /var/lib/spamassassin; chown debian-spamd:debian-spamd /var/lib/spamassassin 2>/dev/null || true
# spamd EXITS without downloaded rules ("no rules were found") — fresh boxes
# would flap until the daily cron fires, so seed rules at install time.
sa-update 2>&1 | tail -2 || true
# TLS cert must exist BEFORE dovecot starts (template is ssl=yes + 993).
# Full ssl-selfsigned.sh still runs at end of install (vhosts, vsftpd, restarts).
SSLDIR=/etc/ssl/skypanel; mkdir -p "$SSLDIR"
if [[ ! -f "$SSLDIR/cert.pem" ]]; then
  CN="$(hostname -I | awk '{print $1}')"
  openssl req -x509 -newkey rsa:2048 -nodes -days 825 \
    -keyout "$SSLDIR/key.pem" -out "$SSLDIR/cert.pem" \
    -subj "/CN=$CN" -addext "subjectAltName=IP:$CN,DNS:localhost" 2>/dev/null
  chmod 600 "$SSLDIR/key.pem"
fi
chgrp ssl-cert "$SSLDIR/key.pem" 2>/dev/null || true
chmod 640 "$SSLDIR/key.pem"
# Exim reads the key as Debian-exim via ssl-cert. Join the group BEFORE the
# restart below: a running daemon never picks up new groups, and a fresh
# 24.04 install had 587/465 answering "TLS currently unavailable".
id -nG Debian-exim 2>/dev/null | grep -qw ssl-cert || adduser Debian-exim ssl-cert 2>/dev/null || true
systemctl enable exim4 dovecot "$SPAMD_UNIT"; systemctl restart exim4 dovecot "$SPAMD_UNIT"
# IMAP only: POP3 (110/995) is deliberately not served (dovecot protocols =
# imap lmtp), so its ports stay closed.
ufw allow 25,587,465,143,993/tcp || true
