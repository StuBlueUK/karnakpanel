#!/usr/bin/env bash
# exim-root-patch.sh — idempotently add the system_root router to the LIVE
# exim conf (fresh installs render it from installer/configs). Mail to
# root@localhost skipped the installer's root@<mailname> forwarder, fell to
# local_delivery (uid 0 refused) and left a paniclog line per message: the
# nightly "paniclog has non-zero size" ALERT. The router sends it to
# root@<qualify_domain>, i.e. through that forwarder.
# Same discipline as exim-localuser-patch.sh: patched on a copy, checked
# with exim -C -bV, backed up, swapped. Re-runs are no-ops.
set -euo pipefail
CONF="/etc/exim4/exim4.conf"
[[ -f "$CONF" ]] || { echo "no $CONF"; exit 1; }
grep -q '^# KarnakPanel system root' "$CONF" && { echo "already patched"; exit 0; }
grep -q '^system_root:' "$CONF" && { echo "hand-made system_root router — refusing"; exit 1; }
TMP="$(mktemp "$CONF.root-XXXXXX")"
trap 'rm -f "$TMP"' EXIT
cp -a "$CONF" "$TMP"
python3 - "$TMP" <<'PYEOF'
import sys
path = sys.argv[1]
anchor = "dnslookup:\n  driver = dnslookup\n"
router = r"""# KarnakPanel system root (managed — do not hand-edit)
# box-local mail for root (root@localhost) takes the installer's
# root@<mailname> forwarder: without this it falls to local_delivery, which
# refuses uid 0, and each message writes to the paniclog (the nightly
# "mail system possibly broken" ALERT). No forwarder = router declines.
system_root:
  driver = redirect
  domains = localhost : localhost.localdomain
  local_parts = root
  condition = ${lookup{root@$qualify_domain}lsearch{/etc/exim4/virtual/forwarders}{yes}{no}}
  data = root@$qualify_domain
"""
src = open(path).read()
if src.count(anchor) != 1:
    sys.exit("dnslookup router anchor not found/unique — refusing")
open(path, 'w').write(src.replace(anchor, router + anchor))
PYEOF
exim -C "$TMP" -bV >/dev/null 2>&1 || { echo "config check FAILED — live config untouched"; exit 1; }
cp -a "$CONF" "$CONF.bak-root-$(date +%Y%m%d%H%M%S)"
cp -a "$TMP" "$CONF"
systemctl reload exim4 2>/dev/null || systemctl restart exim4
echo "patched + exim reloaded"
"$(cd "$(dirname "$0")" && pwd)"/integrity-check.sh --baseline >/dev/null 2>&1 || true
