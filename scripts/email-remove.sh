#!/usr/bin/env bash
# email-remove.sh <address> — drop a mailbox: Exim map lines + its virtual Maildir.
# Idempotent. The domain itself (and other boxes) is untouched.
set -euo pipefail
A="${1:?}"
[[ "$A" =~ ^[a-z0-9._-]+@[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad address: $A"; exit 1; }
LOCAL="${A%%@*}"; D="${A##*@}"
ESC="${A//./\\.}"
# a running mailbox import must not write into the Maildir being removed
"$(dirname "$(readlink -f "$0")")/mail-import.sh" stop-addr "$A" >/dev/null 2>&1 || true
sed -i "/^$ESC: /d" /etc/exim4/virtual/aliases 2>/dev/null || true
sed -i "/^$ESC: /d" /etc/exim4/virtual/maildirs 2>/dev/null || true
sed -i "/^$ESC: /d" /etc/exim4/virtual/autoreplies 2>/dev/null || true
# the local part is a path component: a stored ".." row (older panels
# accepted it) would make this rm -rf the WHOLE mail store. Maps are gone
# above; an unsafe name's files are left for the admin.
if [[ "$LOCAL" =~ ^[a-z0-9_-]([a-z0-9._-]{0,62}[a-z0-9_-])?$ && "$LOCAL" != *..* ]]; then
  rm -rf "/var/mail/virtual/$D/$LOCAL" 2>/dev/null || true
  rm -f "/var/lib/skypanel/autoreply/$D/$LOCAL".{subj,msg,once} 2>/dev/null || true
  echo "mailbox $A removed"
else echo "mailbox $A unmapped; unsafe local part, its files were NOT removed (check /var/mail/virtual/$D by hand)"; fi
