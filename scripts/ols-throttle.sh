#!/usr/bin/env bash
# ols-throttle.sh — per-IP request-rate caps in the global perClientConnLimit
# block (fresh installs via the openlitespeed lib, existing boxes by hand).
# Connection COUNT was already capped (10000); request RATE was unlimited, so
# an RPS flood from a few IPs hurt while staying under the connection cap.
# dyn 50/s + static 200/s: far above legit bursts (admin-ajax dozens/s), far
# below flood rates (thousands/s). Idempotent; graceful restart, verifies OLS.
set -euo pipefail
CONF="/usr/local/lsws/conf/httpd_config.conf"
[[ -f "$CONF" ]] || { echo "no OLS config"; exit 1; }
# stock configs carry the lines at 0 (unlimited) — only a nonzero value counts
if grep -Eq "dynReqPerSec\s+[1-9]" "$CONF"; then
  echo "throttle already present"
else
  cp -a "$CONF" "$CONF.bak-$(date +%Y%m%d)"
  python3 - "$CONF" <<'PYEOF'
import re, sys
p = sys.argv[1]
src = open(p).read()
# set nonzero (add if somehow missing entirely)
if re.search(r'dynReqPerSec', src):
    src = re.sub(r'dynReqPerSec\s+\d+', 'dynReqPerSec  50', src)
    src = re.sub(r'staticReqPerSec\s+\d+', 'staticReqPerSec  200', src)
else:
    pat = re.compile(r'(perClientConnLimit\{\s*\n)')
    assert pat.search(src), "perClient block not found"
    src = pat.sub(r'\1  dynReqPerSec  50\n  staticReqPerSec  200\n', src, count=1)
open(p, 'w').write(src)
PYEOF
  echo "throttle added"
fi
/usr/local/lsws/bin/lswsctrl restart 2>&1 | tail -1 || true
sleep 3
curl -sk -o /dev/null -w "panel:%{http_code}\n" --max-time 15 https://127.0.0.1:8090/login
