#!/usr/bin/env bash
# exim-ipv4-prefer-patch.sh — idempotently make outbound mail try the remote
# MX's IPv4 address first (dnslookup routers: `ipv4_prefer = true`).
# Why: VPS IPv6 addresses mostly keep the provider's generic reverse DNS
# (vmi….contaboserver.net), and Gmail/Outlook are strict about PTR on IPv6 —
# prod sent ~half its remote mail over IPv6 and Gmail answered "550-5.7.26
# sender is unauthenticated" there. IPv6 stays the fallback (an MX with no
# IPv4 still gets mail); inbound IPv6 is untouched. SPF keeps listing the
# IPv6 (spf-default.sh) for that fallback.
# Both dnslookup routers (dnslookup + remote_route). Fresh installs render the
# same from installer/configs. Patched on a copy, checked with exim -C -bV,
# backed up, swapped; an Exim too old for the option (< 4.95) is skipped with
# a note, never a failed update. Re-runs are no-ops.
set -euo pipefail
CONF="/etc/exim4/exim4.conf"
[[ -f "$CONF" ]] || { echo "no $CONF"; exit 1; }
exim -C "$CONF" -bV >/dev/null 2>&1 || { echo "live exim config does not check — not touching it"; exit 1; }
TMP="$(mktemp "$CONF.ipv4-XXXXXX")"
trap 'rm -f "$TMP"' EXIT
cp -a "$CONF" "$TMP"
RC=0
python3 - "$TMP" <<'PYEOF' || RC=$?
import re, sys
path = sys.argv[1]
src = open(path).read()
orig = src
for name in ("dnslookup", "remote_route"):
    m = re.search(r"(?m)^" + name + r":\n  driver = dnslookup\n((?:  [^\n]*\n)*)", src)
    if not m:
        print(f"router {name} not found — left alone")
        continue
    body = m.group(1)
    if re.search(r"(?m)^  ipv4_(prefer|only)\b", body):
        continue
    if body.count("  no_more\n") != 1:
        print(f"router {name}: no single no_more line — left alone")
        continue
    nb = body.replace("  no_more\n", "  # try the MX's IPv4 first (generic IPv6 PTRs get rejected)\n  ipv4_prefer = true\n  no_more\n")
    src = src[:m.start(1)] + nb + src[m.end(1):]
if src == orig:
    sys.exit(3)
open(path, 'w').write(src)
PYEOF
[[ $RC -eq 3 ]] && exit 0
[[ $RC -eq 0 ]] || exit 1
exim -C "$TMP" -bV >/dev/null 2>&1 || { echo "this Exim does not take ipv4_prefer (needs 4.95+) — skipped, mail unchanged"; exit 0; }
cp -a "$CONF" "$CONF.bak-ipv4-$(date +%Y%m%d%H%M%S)"
cat "$TMP" > "$CONF"
systemctl reload exim4 2>/dev/null || systemctl restart exim4
echo "outbound mail tries IPv4 first + exim reloaded"
"$(cd "$(dirname "$0")" && pwd)"/integrity-check.sh --baseline >/dev/null 2>&1 || true
