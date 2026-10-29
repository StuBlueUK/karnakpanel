#!/usr/bin/env bash
# exim-remote-patch.sh — idempotently insert the remote_route router into the
# LIVE /etc/exim4/exim4.conf (which carries substituted secrets, so it is
# patched, never overwritten). Upgraders from <=1.5.8 lack the block that
# fresh 1.5.9 installs render from installer/configs — without it, toggling a
# domain to remote writes a map line no router reads (silent no-op). Runs on
# demand from the mail-routing toggle; re-runs are no-ops. Verifies config
# loads, then reloads. Same conventions as exim-acl-patch.sh.
set -euo pipefail
CONF="/etc/exim4/exim4.conf"
MARK="# KarnakPanel remote-route block (managed — do not hand-edit)"
grep -qF "$MARK" "$CONF" 2>/dev/null && { echo "already patched"; exit 0; }
grep -q '^begin routers$' "$CONF" || { echo "anchor not found — refusing"; exit 1; }
[[ $(grep -c '^begin routers$' "$CONF") == 1 ]] || { echo "anchor not unique — refusing"; exit 1; }
cp -a "$CONF" "$CONF.bak-$(date +%Y%m%d)"
python3 - "$CONF" <<'PYEOF'
import sys
path = sys.argv[1]
block = """# KarnakPanel remote-route block (managed — do not hand-edit)
# Per-domain remote mail handling (panel Domains page): listed domains route
# by PUBLIC MX before any local delivery (see installer/configs/exim/exim4.conf).
remote_route:
  driver = dnslookup
  domains = +local_domains
  condition = ${lookup{$domain}lsearch{/etc/exim4/virtual/remote}{yes}{no}}
  transport = remote_smtp
  no_more
"""
src = open(path).read()
anchor = "begin routers\n"
assert src.count(anchor) == 1, "anchor not unique"
open(path, 'w').write(src.replace(anchor, anchor + block, 1))
PYEOF
mkdir -p /etc/exim4/virtual
touch /etc/exim4/virtual/remote
chmod 644 /etc/exim4/virtual/remote 2>/dev/null || true
if exim -bV >/dev/null 2>&1; then
  systemctl reload exim4 2>/dev/null || systemctl restart exim4
  echo "patched + exim reloaded"
else
  LATEST="$(ls -t "$CONF".bak-* 2>/dev/null | head -1)"
  [[ -n "$LATEST" ]] && cp -a "$LATEST" "$CONF"
  echo "config check FAILED — restored backup"; exit 1
fi
# known-legit change source: refresh the integrity baseline (feature: integrity-check)
"$(cd "$(dirname "$0")" && pwd)"/integrity-check.sh --baseline >/dev/null 2>&1 || true
