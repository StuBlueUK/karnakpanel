#!/usr/bin/env bash
# dns-secondary.sh <domain> <ip1>[,ip2] — allow zone transfers to (and notify)
# secondary nameserver(s) for one zone. Empty ip list clears back to
# primary-only. Edits only that zone's stanza in zones.skypanel (never other
# zones), validates with named-checkconf, reloads. The secondary box itself
# (BIND secondary config + glue) is documented, not managed — out of scope.
set -euo pipefail
D="${1:?}"; IPS="${2:-}"
[[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad domain"; exit 1; }
CONF="/etc/bind/zones.skypanel"
[[ -f "$CONF" ]] || { echo "no zone registry"; exit 1; }
ACL=""
if [[ -n "$IPS" ]]; then
  for ip in ${IPS//,/ }; do
    [[ "$ip" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ || "$ip" =~ ^[0-9a-fA-F:]+$ ]] || { echo "bad IP: $ip"; exit 1; }
    ACL="$ACL $ip;"
  done
  [[ -n "$ACL" ]] || { echo "no valid IPs"; exit 1; }
fi
cp -a "$CONF" "$CONF.bak-$(date +%Y%m%d)"
python3 - "$CONF" "$D" "$ACL" <<'PYEOF'
import re, sys
path, dom, acl = sys.argv[1], sys.argv[2], sys.argv[3]
src = open(path).read()
# stanza ends with '};' AND inner allow-transfer/also-notify blocks also end
# with '};' (by our own template) — so match one nesting level of braces,
# not "up to the first };", which amputates mid-stanza and orphans config
pat = re.compile(r'zone "%s" \{(?:[^{}]|\{[^{}]*\})*\};' % re.escape(dom))
m = pat.search(src)
if not m:
    print("zone not registered: %s" % dom); sys.exit(1)
if acl:
    new = 'zone "%s" { type master; file "/etc/bind/zones/db.%s"; allow-transfer { %s }; also-notify { %s }; };' % (dom, dom, acl, acl)
else:
    new = 'zone "%s" { type master; file "/etc/bind/zones/db.%s"; };' % (dom, dom)
open(path, 'w').write(src[:m.start()] + new + src[m.end():])
print("secondaries %s for %s" % ("set:" + acl if acl else "cleared", dom))
PYEOF
named-checkconf || { cp -a "$CONF.bak-$(date +%Y%m%d)" "$CONF"; echo "config INVALID — restored backup"; exit 1; }
rndc reconfig 2>/dev/null || systemctl reload bind9
rndc reload "$D" 2>/dev/null || true
echo "done"
