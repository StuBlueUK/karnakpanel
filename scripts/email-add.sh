#!/usr/bin/env bash
# email-add.sh <address> <owner> — register a virtual mailbox (Dovecot SQL + Exim virtual delivery).
# No system user involved: auth comes from the panel DB, mail lands in /var/mail/virtual.
set -euo pipefail
A="${1:?}"; U="${2:?}"; shift 2 || true
LOCAL="${A%%@*}"; D="${A##*@}"
[[ "$A" =~ ^[a-z0-9._-]+@[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad address: $A"; exit 1; }
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad owner: $U"; exit 1; }
mkdir -p "/var/mail/virtual/$D/$LOCAL"
# Maildir needs new/cur/tmp or Exim appendfile defers ("temporary internal
# error") until first IMAP login auto-creates them — new mailboxes must work
# immediately, not after a login + queue retry.
mkdir -p "/var/mail/virtual/$D/$LOCAL"/{new,cur,tmp}
chown -R vmail:vmail "/var/mail/virtual/$D/$LOCAL"
chmod 700 "/var/mail/virtual/$D/$LOCAL"
# belt & braces: domain listed as local + mailbox present in the aliases map
mkdir -p /etc/exim4/virtual
touch /etc/exim4/virtual/domains /etc/exim4/virtual/aliases /etc/exim4/virtual/maildirs
grep -qxF "$D: ok" /etc/exim4/virtual/domains 2>/dev/null || echo "$D: ok" >> /etc/exim4/virtual/domains
grep -qxF "$A: virtual" /etc/exim4/virtual/aliases 2>/dev/null || echo "$A: virtual" >> /etc/exim4/virtual/aliases
grep -qxF "$A: /var/mail/virtual/$D/$LOCAL" /etc/exim4/virtual/maildirs 2>/dev/null || echo "$A: /var/mail/virtual/$D/$LOCAL" >> /etc/exim4/virtual/maildirs
echo "mailbox $A ready (virtual, vmail)"
