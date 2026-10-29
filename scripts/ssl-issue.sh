#!/usr/bin/env bash
# ssl-issue.sh <domain> <email>
set -euo pipefail
D="${1:?}"; E="${2:-}"
[[ "$E" =~ ^[^@]+@[^@]+\.[a-z]{2,}$ ]] || { echo "refusing: ACME needs a real email (not $E)"; exit 1; }
# challenge webroot = the domain's own docroot (Example/html only serves
# unhosted names). No vhost -> refuse with guidance instead of a cryptic 404.
VH="/usr/local/lsws/conf/vhosts/$D/vhost.conf"
[[ -f "$VH" ]] || { echo "refusing: $D has no site here — add the domain first, then issue"; exit 1; }
WROOT="$(grep -E '^docRoot' "$VH" | awk '{print $2}' | head -1)"
[[ -n "$WROOT" && -d "$WROOT" ]] || { echo "refusing: docroot for $D not found"; exit 1; }
# www is covered only when it actually points here (public DNS): LE fails the
# WHOLE issuance if any requested name doesn't resolve, so apex-only setups
# must not request www. Re-issue after adding www DNS to extend coverage.
DOMAINS=(-d "$D")
IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
WRES="$(dig +short +time=3 +tries=2 "www.$D" @8.8.8.8 2>/dev/null | head -1 || true)"
if [[ "$WRES" == "$IP" ]]; then
  DOMAINS+=(-d "www.$D")
else
  echo "note: www.$D resolves to '${WRES:-nothing}' (not this box) — issuing apex-only; re-issue after adding its A record"
fi
# mail.<domain> gets the same treatment (Thunderbird et al. connect there —
# without the SAN the mail daemons can't present a matching cert for it).
# Challenge must reach this vhost: ensure the mail alias exists first.
MRES="$(dig +short +time=3 +tries=2 "mail.$D" @8.8.8.8 2>/dev/null | head -1 || true)"
if [[ "$MRES" == "$IP" ]]; then
  DOMAINS+=(-d "mail.$D")
  if ! grep -q "mail\.$D" "$VH" 2>/dev/null; then
    sed -i "s/^vhAliases\(.*\)$/vhAliases\1 mail.$D/" "$VH" 2>/dev/null || true
    echo "added mail.$D to vhost aliases for the challenge"
  fi
else
  echo "note: mail.$D resolves to '${MRES:-nothing}' (not this box) — skipping its SAN; re-issue after adding its A record"
fi
# mta-sts.<domain> (MTA-STS policy host, Email page): senders fetch the
# policy over HTTPS and require a cert valid for that name. Requested only
# when it resolves here AND mta-sts.sh already joined it to this vhost —
# otherwise the challenge hits the default vhost and LE fails the WHOLE order.
SRES="$(dig +short +time=3 +tries=2 "mta-sts.$D" @8.8.8.8 2>/dev/null | head -1 || true)"
if [[ "$SRES" == "$IP" ]] && grep -qE "^vhAliases.*mta-sts\.${D//./\\.}" "$VH" 2>/dev/null; then
  DOMAINS+=(-d "mta-sts.$D")
elif grep -qE "^vhAliases.*mta-sts\.${D//./\\.}" "$VH" 2>/dev/null; then
  echo "note: mta-sts.$D resolves to '${SRES:-nothing}' (not this box) — skipping its SAN; re-issue once its A record is public"
fi
# every requested name needs an EXPLICIT listener map (apex + www + mail):
# alias-only routing proved unreliable for new names on prod. vhost-add
# writes these at creation; older domains need them ensured here.
HTTPD=/usr/local/lsws/conf/httpd_config.conf
for H in "$D" "www.$D" "mail.$D"; do
  if grep -q "^listener Default" "$HTTPD" 2>/dev/null && ! grep -q "map $D $H\$" "$HTTPD" 2>/dev/null; then
    sed -i "/^listener Default/a\\  map $D $H" "$HTTPD" 2>/dev/null || true
  fi
  if grep -q "^listener SSL443" "$HTTPD" 2>/dev/null && ! sed -n "/^listener SSL443/,/^}/p" "$HTTPD" 2>/dev/null | grep -q "map $D $H\$"; then
    sed -i "/^listener SSL443/a\\  map $D $H" "$HTTPD" 2>/dev/null || true
  fi
done
# certbot gets its own bound: the panel's `timeout` kills sudo/this script
# but not certbot (sudo use_pty = new session), so the request kept waiting
# and the cert landed with no vhssl/ssl_enabled. Callers allow 200 s.
timeout 150 certbot certonly --expand --non-interactive --agree-tos -m "$E" --webroot -w "$WROOT" --deploy-hook "/usr/local/skypanel/scripts/mail-sni.sh rebuild" "${DOMAINS[@]}"
# Hook certs into OLS vhost (replacing any self-signed block from ssl-selfsigned.sh)
if [[ -f "$VH" ]]; then
python3 - "$VH" "$D" <<'PYEOF'
import re, sys
p, d = sys.argv[1], sys.argv[2]
s = open(p).read()
s = re.sub(r"# skypanel-selfsigned BEGIN.*?# skypanel-selfsigned END\n?", "", s, flags=re.S)
if "keyFile" not in s:
    s = s.rstrip() + f"""

vhssl {{
  keyFile /etc/letsencrypt/live/{d}/privkey.pem
  certFile /etc/letsencrypt/live/{d}/fullchain.pem
  certChain 1
}}
"""
    open(p, "w").write(s)
    print(f"LE vhssl installed for {d}")
else:
    print(f"{d} already has keyFile — left alone")
PYEOF
fi
# force-HTTPS: plain HTTP must never serve the site once a cert exists.
# Port-based (works behind the :80 listener regardless of env quirks),
# ahead of the front-controller catch-all, with .well-known exempt so
# renewals keep validating over plain HTTP. Idempotent via markers.
python3 - "$VH" <<'PYEOF'
import sys
p = sys.argv[1]
s = open(p).read()
if "# karnakpanel-force-https BEGIN" not in s:
    block = """# karnakpanel-force-https BEGIN (managed — plain HTTP always redirects)
RewriteCond %{REQUEST_URI} !^/\\.well-known/
RewriteCond %{SERVER_PORT} ^80$
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
# karnakpanel-force-https END
"""
    anchor = "rules                 <<<END_rules"
    assert anchor in s, "rewrite anchor missing"
    s = s.replace(anchor, anchor + "\n" + block, 1)
    open(p, "w").write(s)
    print("force-HTTPS installed")
else:
    print("force-HTTPS already present")
PYEOF
/usr/local/lsws/bin/lswsctrl restart >/dev/null 2>&1 || true
# optional: serve this cert on service listeners too (3rd arg "services").
# Maps service -> OLS listener + vhost, rewrites both cert paths, restarts.
if [[ "${3:-}" == "services" ]]; then
  declare -A SVC=( [panel8090]="SkyPanel" [webmail8091]="Webmail" [pma8092]="PhpMyAdmin" )
  LEKEY="/etc/letsencrypt/live/$D/privkey.pem"
  LECRT="/etc/letsencrypt/live/$D/fullchain.pem"
  for L in "${!SVC[@]}"; do
    VH="${SVC[$L]}"
    python3 - "$L" "$VH" "$LEKEY" "$LECRT" <<'PYEOF'
import re, sys
lis, vh, key, crt = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4]
p = "/usr/local/lsws/conf/httpd_config.conf"
s = open(p).read()
s = re.sub(r"(listener " + lis + r" \{.*?keyFile ).*?\n", r"\1" + key + "\n", s, count=1, flags=re.S)
s = re.sub(r"(listener " + lis + r" \{.*?certFile ).*?\n", r"\1" + crt + "\n", s, count=1, flags=re.S)
open(p, "w").write(s)
v = f"/usr/local/lsws/conf/vhosts/{vh}/vhost.conf"
if __name__ == "__main__" and __import__("os").path.isfile(v):
    vs = open(v).read()
    vs = re.sub(r"keyFile .*", f"keyFile {key}", vs)
    vs = re.sub(r"certFile .*", f"certFile {crt}", vs)
    open(v, "w").write(vs)
    print(f"services: {lis}/{vh} now on {key}")
PYEOF
  done
  # vsftpd on the same LE cert (else FTP keeps serving the self-signed
  # fallback — clients prompt to trust on every fresh login). Set-or-replace:
  # the template ships rsa_* lines only after ssl-selfsigned ran, and stale
  # paths must move, not duplicate.
  for KV in "rsa_cert_file=$LECRT" "rsa_private_key_file=$LEKEY"; do
    K="${KV%%=*}"
    if grep -q "^$K=" /etc/vsftpd.conf 2>/dev/null; then
      sed -i "s|^$K=.*|$KV|" /etc/vsftpd.conf
    else
      echo "$KV" >> /etc/vsftpd.conf
    fi
  done
  systemctl restart vsftpd 2>/dev/null || true
  echo "vsftpd now on $LECRT"
  /usr/local/lsws/bin/lswsctrl restart >/dev/null 2>&1 || true
fi
echo "Congratulations — SSL active for $D"
# new/renewed cert -> refresh per-domain mail TLS maps now (renewals also
# fire this via the certbot --deploy-hook persisted above).
"$(cd "$(dirname "$0")" && pwd)"/mail-sni.sh rebuild 2>&1 | tail -2 || true
# apps store their own base URL and loop against force-HTTPS until flipped
# (WordPress siteurl, PrestaShop flags, Flarum baseUrl, phpBB protocol).
# Best-effort: never fails the issuance.
"$(cd "$(dirname "$0")" && pwd)"/ssl-app-sync.sh "$D" 2>&1 || true

# known-legit change source: refresh the integrity baseline (feature: integrity-check)
"$(cd "$(dirname "$0")" && pwd)"/integrity-check.sh --baseline >/dev/null 2>&1 || true
