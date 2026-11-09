#!/usr/bin/env bash
# shield-xss-patch.sh — swap the bot shield's old XSS line for the one that
# also matches URL-encoded probes (OLS tests %{QUERY_STRING} raw, so the old
# `<script` alternative never matched a browser-sent %3Cscript). Only vhosts
# that still carry the exact old line change: shields switched off have no
# line, patched ones no longer match. One reload if anything changed.
# Idempotent; quiet when there is nothing to do. Called by host-converge.sh.
set -euo pipefail
PAIR="$(mktemp)"; trap 'rm -f "$PAIR"' EXIT
# old/new via FILE, compared literally (sed/awk -v would eat the backslash)
cat > "$PAIR" <<'LINES'
    RewriteCond %{QUERY_STRING} (<script|javascript:|onerror=|document\.cookie) [NC]
    RewriteCond %{QUERY_STRING} (<script|%3Cscript|javascript:|javascript%3A|onerror=|onerror%3D|document\.cookie) [NC]
LINES
OLD="$(sed -n 1p "$PAIR")"
n=0
for F in /usr/local/lsws/conf/vhosts/*/vhost.conf; do
  [[ -f "$F" && ! -L "$F" ]] || continue
  grep -qxF -- "$OLD" "$F" || continue
  awk 'NR==FNR{if(FNR==1)o=$0; else n=$0; next} $0==o{print n; next} {print}' "$PAIR" "$F" > "$F.tmp"
  if grep -qF '%3Cscript' "$F.tmp" && [[ "$(wc -l < "$F.tmp")" == "$(wc -l < "$F")" ]]; then
    cat "$F.tmp" > "$F"; n=$((n+1))
  else
    echo "shield-xss-patch: $F left unchanged (rewrite check failed)"
  fi
  rm -f "$F.tmp"
done
if [[ "$n" -gt 0 ]]; then
  /usr/local/lsws/bin/lswsctrl reload >/dev/null 2>&1 || systemctl reload lsws 2>/dev/null || true
  echo "shield-xss-patch: $n vhost(s) patched"
fi
