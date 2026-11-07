#!/usr/bin/env bash
# exim-moved-patch.sh — idempotently insert the moved-domain routers into the
# LIVE /etc/exim4/exim4.conf (it carries substituted secrets: patched, never
# overwritten). Used by Account Transfer cutover on the OLD server
# (transfer-serve.sh cut-hold); fresh installs render the same block from
# installer/configs/exim/exim4.conf. Map /etc/exim4/virtual/moved:
#   <domain>: hold          mail is kept in this server's queue (deferred)
#   <domain>: <IPv4>        mail is passed on to the new server at that IP
# Absent line = nothing changes. Patched on a copy, checked with exim -C -bV,
# backed up, swapped (as exim-root-patch.sh). Adds retry rules first.
set -euo pipefail
CONF="/etc/exim4/exim4.conf"
MARK="# KarnakPanel moved-route block (managed — do not hand-edit)"
mkdir -p /etc/exim4/virtual
touch /etc/exim4/virtual/moved; chmod 644 /etc/exim4/virtual/moved
# a hold must queue, never bounce: needs retry rules (old configs had none)
"$(cd "$(dirname "$0")" && pwd)"/exim-retry-patch.sh > /dev/null || { echo "retry patch failed — refusing"; exit 1; }
grep -qF "$MARK" "$CONF" 2>/dev/null && { echo "already patched"; exit 0; }
grep -q '^begin routers$' "$CONF" || { echo "anchor not found — refusing"; exit 1; }
[[ $(grep -c '^begin routers$' "$CONF") == 1 ]] || { echo "anchor not unique — refusing"; exit 1; }
TMP="$(mktemp "$CONF.moved-XXXXXX")"
trap 'rm -f "$TMP"' EXIT
cp -a "$CONF" "$TMP"
python3 - "$TMP" <<'PYEOF'
import sys
path = sys.argv[1]
block = r"""# KarnakPanel moved-route block (managed — do not hand-edit)
# Account Transfer cutover (OLD server): domains listed in the moved map are
# held in the queue while the last copy runs, then passed on to the new
# server by IP (not by MX: DNS may still point here). Map RESULTS only.
moved_hold:
  driver = redirect
  domains = +local_domains
  condition = ${if eq{${if exists{/etc/exim4/virtual/moved}{${lookup{$domain}lsearch{/etc/exim4/virtual/moved}{$value}{}}}{}}}{hold}}
  data = :defer: mailbox is moving to a new server
  allow_defer
moved_route:
  driver = manualroute
  domains = +local_domains
  condition = ${if match{${if exists{/etc/exim4/virtual/moved}{${lookup{$domain}lsearch{/etc/exim4/virtual/moved}{$value}{}}}{}}}{\N^[0-9]{1,3}(\.[0-9]{1,3}){3}$\N}}
  route_data = ${lookup{$domain}lsearch{/etc/exim4/virtual/moved}{$value}fail}
  transport = remote_smtp
  self = send
  no_more
"""
src = open(path).read()
anchor = "begin routers\n"
assert src.count(anchor) == 1, "anchor not unique"
open(path, 'w').write(src.replace(anchor, anchor + block, 1))
PYEOF
exim -C "$TMP" -bV >/dev/null 2>&1 || { echo "config check FAILED — live config untouched"; exit 1; }
cp -a "$CONF" "$CONF.bak-moved-$(date +%Y%m%d%H%M%S)"
cp -a "$TMP" "$CONF"
systemctl reload exim4 2>/dev/null || systemctl restart exim4
echo "patched + exim reloaded"
"$(cd "$(dirname "$0")" && pwd)"/integrity-check.sh --baseline >/dev/null 2>&1 || true
