#!/usr/bin/env bash
# maintenance.sh <domain> <on|off> — park a site behind a holding page by
# swapping the vhost docRoot between public_html and a maintenance/ dir
# (no rewrite interplay, no .htaccess dependency). Holding page is branded
# per domain and created on first use. Graceful restart applies it.
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/as-user.sh"   # customer-tree writes run as the customer
D="${1:?}"; A="${2:-on}"
[[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad domain: $D"; exit 1; }
[[ "$A" == on || "$A" == off ]] || { echo "want on|off"; exit 1; }
VH="/usr/local/lsws/conf/vhosts/$D/vhost.conf"
[[ -f "$VH" ]] || { echo "no vhost $D"; exit 1; }
DOC="$(grep -oE '/home/[a-z][a-z0-9_]{2,31}' "$VH" | head -1 | cut -d/ -f3)"
[[ -n "$DOC" ]] || { echo "cannot determine owner of $D"; exit 1; }
PUB="/home/$DOC/$D/public_html"
MNT="/home/$DOC/$D/maintenance"
if [[ "$A" == on ]]; then
  # all of it AS the owner: the domain dir is theirs, so a root mkdir/chown
  # here could be steered through a link they plant
  as_user "$DOC" mkdir -p -- "$MNT" || { echo "cannot create $MNT"; exit 1; }
  if ! as_user "$DOC" test -f "$MNT/index.html"; then
    user_put "$DOC" "$MNT/index.html" 644 <<EOF || { echo "holding page write failed"; exit 1; }
<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>$D — back soon</title></head>
<body style="font-family:sans-serif;display:flex;height:100vh;align-items:center;justify-content:center;background:#0b1220;color:#e2e8f0;margin:0">
<div style="text-align:center"><div style="font-size:48px">🏛️</div><h1>$D</h1><p>Down for maintenance — back soon.</p></div></body></html>
EOF
  fi
  as_user "$DOC" chmod 755 -- "$MNT"; as_user "$DOC" chmod 644 -- "$MNT/index.html" || true
  # an MTA-STS policy must stay fetchable while the site is parked (senders
  # re-fetch on id change; a 404 under enforce just keeps their cache, but
  # testing/none changes would be missed) — carry it into the holding root
  # (copied AS the owner: both trees are customer-writable, so a root cp
  # could be steered through planted symlinks)
  if [[ -f "$PUB/.well-known/mta-sts.txt" ]]; then
    as_user "$DOC" mkdir -p "$MNT/.well-known" 2>/dev/null || true
    as_user "$DOC" cp -f "$PUB/.well-known/mta-sts.txt" "$MNT/.well-known/mta-sts.txt" 2>/dev/null || true
  fi
  sed -i "s|^docRoot\(.*\)$PUB|docRoot\1$MNT|" "$VH"
  # wide ACL so the shared web worker reads the holding page like site files
  as_user "$DOC" setfacl -R -P -m "$(acl_web rX)" -- "$MNT" 2>/dev/null || true
else
  sed -i "s|^docRoot\(.*\)$MNT|docRoot\1$PUB|" "$VH"
fi
/usr/local/lsws/bin/lswsctrl restart 2>&1 | tail -1
grep -h "^docRoot" "$VH" | head -1
