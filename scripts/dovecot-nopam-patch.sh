#!/usr/bin/env bash
# dovecot-nopam-patch.sh — idempotently drop the PAM passdb from the LIVE
# dovecot.conf. Shipped configs before this fix let any unix account (hosting
# accounts with their panel password, root) log in to IMAP and — through
# Exim's dovecot authenticator — relay mail over SMTP AUTH. Mailboxes are
# virtual (SQL passdb); nothing in the panel uses unix-account mail logins.
# Box-local mail (root, cron) still lands in ~/Maildir via Exim; it is just
# not readable over IMAP any more. The live conf may carry per-box edits
# (cert paths), so it is patched on a copy, checked with doveconf, never
# overwritten from the template. Re-runs are no-ops.
set -euo pipefail
CONF=/etc/dovecot/dovecot.conf
[[ -f "$CONF" ]] || { echo "no $CONF"; exit 0; }
TMP="$(mktemp "$CONF.nopam-XXXXXX")"
trap 'rm -f "$TMP"' EXIT
# 2.3: "passdb {\n  driver = pam\n}"   2.4: "passdb pam {\n}"
r=0; python3 - "$CONF" "$TMP" <<'PYEOF' || r=$?
import re, sys
src = open(sys.argv[1]).read()
out = re.sub(r'(?m)^passdb \{\n[ \t]*driver = pam\n\}\n', '', src)
out = re.sub(r'(?m)^passdb pam \{\n\}\n', '', out)
out = out.replace('# KarnakPanel Dovecot — virtual mailboxes (panel DB) + system users (PAM fallback)',
                  '# KarnakPanel Dovecot — virtual mailboxes (panel DB); no unix-account logins')
out = out.replace('# SQL first: most logins are virtual (full address); system users fall through to pam/passwd.',
                  '# Logins are SQL only (full address); userdb passwd stays for box-local ~/Maildir.')
open(sys.argv[2], 'w').write(out)
sys.exit(0 if out != src else 3)
PYEOF
(( r == 3 )) && { echo "already patched"; exit 0; }
(( r == 0 )) || { echo "patch failed"; exit 1; }
grep -qE '^passdb([[:space:]]+pam)?[[:space:]]*\{' "$TMP" || { echo "no passdb left — refusing"; exit 1; }
grep -q 'driver = pam' "$TMP" && { echo "pam passdb in an unexpected shape — refusing"; exit 1; }
doveconf -c "$TMP" -n >/dev/null 2>&1 || { echo "config check FAILED — live config untouched"; exit 1; }
cp -a "$CONF" "$CONF.bak-nopam-$(date +%Y%m%d%H%M%S)"
cat "$TMP" > "$CONF"
systemctl reload dovecot 2>/dev/null || systemctl restart dovecot
echo "patched + dovecot reloaded"
"$(cd "$(dirname "$0")" && pwd)"/integrity-check.sh --baseline >/dev/null 2>&1 || true
