#!/usr/bin/env bash
# exim-lmtp-patch.sh — idempotently add the `dovecot_lmtp` transport to the
# LIVE exim config. The virtual_lmtp_filter router (boxes with active mail
# filters) has always named it, but shipped configs before 1.5.32 never
# defined it: mail to any filtered box deferred ("transport dovecot_lmtp not
# found") and finally bounced. exim -bV does not catch an undefined transport.
# Fresh installs render it from installer/configs; upgraders get this via
# host-converge.sh. The live conf carries substituted secrets, so it is
# patched (on a copy, checked with exim -C -bV), never overwritten.
# Re-runs are no-ops; a hand-made dovecot_lmtp is left alone.
set -euo pipefail
CONF="/etc/exim4/exim4.conf"
[[ -f "$CONF" ]] || { echo "no $CONF"; exit 1; }
grep -q '^dovecot_lmtp:$' "$CONF" && { echo "already patched"; exit 0; }
grep -q '^  transport = dovecot_lmtp$' "$CONF" || { echo "no router uses dovecot_lmtp — nothing to patch"; exit 0; }
TMP="$(mktemp "$CONF.lmtp-XXXXXX")"
trap 'rm -f "$TMP"' EXIT
cp -a "$CONF" "$TMP"
python3 - "$TMP" <<'PYEOF'
import sys
path = sys.argv[1]
old = "\nbegin transports\n"
new = ("\nbegin transports\n"
       "# LMTP handoff for sieve-filtered mail (see virtual_lmtp_filter router).\n"
       "# Dovecot delivers as vmail (userdb) and runs the box's sieve script.\n"
       "dovecot_lmtp:\n  driver = lmtp\n  socket = /var/run/dovecot/lmtp\n  batch_max = 20\n")
src = open(path).read()
if src.count(old) != 1:
    sys.exit("transports anchor not found/unique — refusing")
open(path, 'w').write(src.replace(old, new))
PYEOF
exim -C "$TMP" -bV >/dev/null 2>&1 || { echo "config check FAILED — live config untouched"; exit 1; }
cp -a "$CONF" "$CONF.bak-lmtp-$(date +%Y%m%d%H%M%S)"
cp -a "$TMP" "$CONF"
systemctl reload exim4 2>/dev/null || systemctl restart exim4
echo "patched + exim reloaded"
"$(cd "$(dirname "$0")" && pwd)"/integrity-check.sh --baseline >/dev/null 2>&1 || true
