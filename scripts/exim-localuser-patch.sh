#!/usr/bin/env bash
# exim-localuser-patch.sh — idempotently restrict the LIVE exim `localuser`
# router to box-local domains. Shipped configs before 1.5.31 had no domains=
# on it, so <unixuser>@<any hosted domain> without a mailbox/catch-all landed
# in that unix account's ~/Maildir: a customer named "sales" received
# sales@ for every other tenant's domain (cross-tenant mail capture).
# Fresh installs render the fixed router from installer/configs; upgraders
# get this via host-converge.sh. The live conf carries substituted secrets,
# so it is patched (on a copy, checked with exim -C -bV), never overwritten.
# Re-runs are no-ops; a hand-edited router is refused loudly.
set -euo pipefail
CONF="/etc/exim4/exim4.conf"
DLINE="  domains = ! lsearch;/etc/exim4/virtual/domains : @ : localhost"
[[ -f "$CONF" ]] || { echo "no $CONF"; exit 1; }
BLOCK="$(awk '/^localuser:$/{f=1;print;next} f&&/^[^ \t]/{exit} f{print}' "$CONF")"
[[ -n "$BLOCK" ]] || { echo "no localuser router — nothing to patch"; exit 0; }
grep -qF -- "$DLINE" <<<"$BLOCK" && { echo "already patched"; exit 0; }
grep -q '^  domains' <<<"$BLOCK" && { echo "localuser router has a custom domains= line — refusing"; exit 1; }
TMP="$(mktemp "$CONF.localuser-XXXXXX")"
trap 'rm -f "$TMP"' EXIT
cp -a "$CONF" "$TMP"
python3 - "$TMP" "$DLINE" <<'PYEOF'
import sys
path, dline = sys.argv[1], sys.argv[2]
old = "localuser:\n  driver = accept\n  check_local_user\n"
new = ("localuser:\n  driver = accept\n"
       "  # box-local only (root, cron, unqualified): a hosted domain must never\n"
       "  # reach a unix account's ~/Maildir (cross-tenant capture)\n"
       + dline + "\n  check_local_user\n")
src = open(path).read()
if src.count(old) != 1:
    sys.exit("localuser anchor not found/unique — refusing")
open(path, 'w').write(src.replace(old, new))
PYEOF
exim -C "$TMP" -bV >/dev/null 2>&1 || { echo "config check FAILED — live config untouched"; exit 1; }
cp -a "$CONF" "$CONF.bak-localuser-$(date +%Y%m%d%H%M%S)"
cp -a "$TMP" "$CONF"
systemctl reload exim4 2>/dev/null || systemctl restart exim4
echo "patched + exim reloaded"
"$(cd "$(dirname "$0")" && pwd)"/integrity-check.sh --baseline >/dev/null 2>&1 || true
