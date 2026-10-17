#!/usr/bin/env bash
# exim-role-patch.sh — idempotently add the postmaster@/abuse@ fallback
# router to the LIVE exim conf (fresh installs render it from
# installer/configs). With RCPT verify on, a hosted domain without its own
# postmaster@/abuse@ would refuse them; RFC 5321/2142 want them reachable,
# so they go to the domain's first-created mailbox until real ones exist.
# Same discipline as exim-localuser-patch.sh: patched on a copy, checked
# with exim -C -bV, backed up, swapped. Re-runs are no-ops.
set -euo pipefail
CONF="/etc/exim4/exim4.conf"
[[ -f "$CONF" ]] || { echo "no $CONF"; exit 1; }
grep -q '^# KarnakPanel role fallback' "$CONF" && { echo "already patched"; exit 0; }
grep -q '^virtual_role_fallback:' "$CONF" && { echo "hand-made virtual_role_fallback router — refusing"; exit 1; }
TMP="$(mktemp "$CONF.role-XXXXXX")"
trap 'rm -f "$TMP"' EXIT
cp -a "$CONF" "$TMP"
python3 - "$TMP" <<'PYEOF'
import sys
path = sys.argv[1]
anchor = "  data = ${lookup{*@$domain}lsearch{/etc/exim4/virtual/forwarders}}\ndnslookup:\n"
router = r"""# KarnakPanel role fallback (managed — do not hand-edit)
# postmaster@/abuse@ must reach someone (RFC 5321/2142): a hosted domain with
# no mailbox, forwarder or catch-all of its own for them gets them in its
# first-created mailbox. Creating postmaster@/abuse@ takes over (earlier
# routers win); a domain with no mailbox at all still refuses them.
virtual_role_fallback:
  driver = redirect
  domains = +local_domains
  local_parts = postmaster : abuse
  data = ${lookup mysql{SELECT CONCAT(e.localpart,'@',d.domain) FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE d.domain='${quote_mysql:$domain}' ORDER BY e.id LIMIT 1}}
"""
src = open(path).read()
if src.count(anchor) != 1:
    sys.exit("catch-all router anchor not found/unique — refusing")
head = "  data = ${lookup{*@$domain}lsearch{/etc/exim4/virtual/forwarders}}\n"
open(path, 'w').write(src.replace(anchor, head + router + "dnslookup:\n"))
PYEOF
exim -C "$TMP" -bV >/dev/null 2>&1 || { echo "config check FAILED — live config untouched"; exit 1; }
cp -a "$CONF" "$CONF.bak-role-$(date +%Y%m%d%H%M%S)"
cp -a "$TMP" "$CONF"
systemctl reload exim4 2>/dev/null || systemctl restart exim4
echo "patched + exim reloaded"
"$(cd "$(dirname "$0")" && pwd)"/integrity-check.sh --baseline >/dev/null 2>&1 || true
