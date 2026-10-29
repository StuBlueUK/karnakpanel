#!/usr/bin/env bash
# waf-shield.sh <domain> <on|off> [--no-reload] — toggle the bot-shield rewrite
# block in a vhost.conf (marked SHIELD-START/END, before the front controller).
# Blocks casual mass-scan traffic (traversal, sqli-in-query, shell paths,
# dotfile exposure, reflected-XSS probes); ACME challenges always exempt.
# Brute-force logins (wp-login/xmlrpc floods) are the fail2ban weblogin
# jail's job, not rewrite's. Idempotent both directions.
set -euo pipefail
D="${1:?}"; ACT="${2:?}"; NORELOAD=0
[[ "${3:-}" == "--no-reload" ]] && NORELOAD=1
[[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad domain"; exit 1; }
[[ "$ACT" == on || "$ACT" == off ]] || { echo "want on|off"; exit 1; }
VH="/usr/local/lsws/conf/vhosts/$D"; F="$VH/vhost.conf"
[[ -f "$F" ]] || { echo "no vhost.conf for $D"; exit 1; }
# strip any existing block first (idempotent) — awk so rule backslashes survive
awk '/    # SHIELD-START/{skip=1} !skip{print} /    # SHIELD-END/{skip=0}' "$F" > "$F.tmp" && mv "$F.tmp" "$F"
if [[ "$ACT" == on ]]; then
  # block via FILE (never -v: awk unescapes backslashes in -v assignments,
  # silently turning \. into . and \s into s — corrupted rules that still load)
  cat > "$F.block" <<'BLOCK'
    # SHIELD-START (KarnakPanel bot shield — waf-shield.sh off to remove)
    RewriteCond %{REQUEST_URI} !^/\.well-known/
    RewriteCond %{REQUEST_URI} \.(git|svn|env|sql|bak|swp|dist)$ [NC,OR]
    RewriteCond %{REQUEST_URI} (c99|r57|b374k|wso|alfa|webshell|backdoor|phpunit|eval-stdin|_wpeprivate) [NC,OR]
    RewriteCond %{QUERY_STRING} (\.\.(/|%2f)|%2e%2e|information_schema|union.*select.*from|into.*outfile|load_file\s*\() [NC,OR]
    RewriteCond %{QUERY_STRING} (<script|javascript:|onerror=|document\.cookie) [NC]
    RewriteRule ^ - [F,L]
    # SHIELD-END
BLOCK
  awk 'NR==FNR{b=b $0 "\n"; next} {print} /rules[[:space:]]+<<<END_rules/{printf "%s", b}' "$F.block" "$F" > "$F.tmp" && mv "$F.tmp" "$F"
  rm -f "$F.block"
  grep -q "SHIELD-START" "$F" || { echo "insert failed (rules heredoc moved?)"; exit 1; }
  grep -q 'RewriteCond %{QUERY_STRING} (<script' "$F" || { echo "insert corrupted (backslashes lost?)"; exit 1; }
fi
if [[ "$NORELOAD" == "0" ]]; then
  /usr/local/lsws/bin/lswsctrl reload 2>/dev/null || systemctl reload lsws 2>/dev/null || service litespeed reload 2>/dev/null || true
fi
echo "shield $ACT for $D"
