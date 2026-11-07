#!/usr/bin/env bash
# vhost-headers.sh <domain> <on|off> [--hsts|--hsts-subdomains] [--csp] [--no-reload]
# Security response headers for one site. OLS ignores Header in .htaccess, so
# they live in vhost.conf: one extraHeaders stanza in `context /`, marked
# HEADERS-START/END. The markers sit OUTSIDE the heredoc — a '#' line inside
# extraHeaders is sent to clients as a literal "#: ..." header (proven live).
# Flags give the FULL state: `on` without --hsts turns HSTS off, so re-runs
# converge and the panel passes the stored state every time. Idempotent.
#   always (on):  nosniff, X-Frame-Options SAMEORIGIN, Referrer-Policy
#   --hsts:       HSTS 2y — refused unless the vhost has a trusted cert for
#                 the domain. --hsts-subdomains adds includeSubDomains (every
#                 subdomain, mail.* included, must then serve a valid cert).
#   --csp:        enforced baseline (frame/object/base/form) + a report-only
#                 default-src 'self' policy. Both `add`ed: an app's own CSP
#                 still applies (browsers enforce every policy sent).
# `set` overrides an app's own value for the three safe headers + HSTS (OLS
# has no setifempty — it emits it as a header NAME). Headers reach static
# files, PHP, and OLS error pages ("always").
# `on` also ensures the force-HTTPS redirect (the same managed block
# ssl-issue.sh writes — never a second one) once a trusted cert is attached;
# without one, a redirect would land visitors on the shared default cert.
# `off` removes only the headers: the redirect belongs to the SSL flow.
# HSTS/CSP can lock out or break a site: per-domain opt-in only, never global.
set -euo pipefail
D="${1:?}"; ACT="${2:?}"; shift 2
HSTS=0; CSP=0; NORELOAD=0
for a in "$@"; do
  case "$a" in
    --hsts) [[ "$HSTS" == 2 ]] || HSTS=1 ;;
    --hsts-subdomains) HSTS=2 ;;
    --csp) CSP=1 ;;
    --no-reload) NORELOAD=1 ;;
    *) echo "unknown flag: $a"; exit 1 ;;
  esac
done
[[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ && "$D" != *..* ]] || { echo "bad domain"; exit 1; }
[[ "$ACT" == on || "$ACT" == off ]] || { echo "want on|off"; exit 1; }
[[ "$ACT" == on || ( "$HSTS" == 0 && "$CSP" == 0 ) ]] || { echo "--hsts/--csp need on"; exit 1; }
F="/usr/local/lsws/conf/vhosts/$D/vhost.conf"
[[ -f "$F" && ! -L "$F" ]] || { echo "no vhost.conf for $D"; exit 1; }
grep -q '^context / {' "$F" || { echo "REFUSED: no 'context / {' in $F"; exit 1; }

# trusted = attached vhssl cert exists, unexpired, names $D, chains to the
# system CA store (self-signed / snakeoil fail here)
trusted_cert() {
  local crt
  crt="$(awk '/^vhssl[[:space:]]*\{/,/^\}/' "$F" | awk '$1=="certFile"{print $2; exit}')"
  [[ -n "$crt" && -f "$crt" ]] || return 1
  openssl x509 -in "$crt" -noout -checkend 0 >/dev/null 2>&1 || return 1
  openssl x509 -in "$crt" -noout -checkhost "$D" 2>/dev/null | grep -q 'does match' || return 1
  openssl verify -CAfile /etc/ssl/certs/ca-certificates.crt -untrusted "$crt" "$crt" >/dev/null 2>&1
}
TRUSTED=0; trusted_cert && TRUSTED=1
if [[ "$HSTS" != 0 && "$TRUSTED" == 0 ]]; then
  echo "REFUSED: HSTS needs a valid certificate for $D (issue Let's Encrypt first) — browsers would lock visitors out"
  exit 1
fi

# a hand-set extraHeaders in context / (WebAdmin, owner edits) would be
# overridden by a second stanza — never silently clobber it
FOREIGN="$(awk '/^context \/ \{/{c=1} c&&/^  # HEADERS-START/{m=1} c&&!m&&/^[[:space:]]*extraHeaders[[:space:]]/{print} c&&/^  # HEADERS-END/{m=0} c&&/^\}/{c=0}' "$F")"
[[ -z "$FOREIGN" ]] || { echo "REFUSED: context / already has its own extraHeaders — merge them into the HEADERS block by hand"; exit 1; }

cp -a "$F" "$F.pre-headers"
# strip any existing block first (idempotent both directions)
awk '/^  # HEADERS-START/{skip=1} !skip{print} /^  # HEADERS-END/{skip=0}' "$F" > "$F.tmp" && cat "$F.tmp" > "$F" && rm -f "$F.tmp"

if [[ "$ACT" == on ]]; then
  # block via FILE (awk -v unescapes backslashes — see waf-shield.sh)
  {
    echo "  # HEADERS-START (KarnakPanel security headers — vhost-headers.sh off to remove)"
    echo "  extraHeaders            <<<END_extraHeaders"
    echo "Header always set X-Content-Type-Options nosniff"
    echo "Header always set X-Frame-Options SAMEORIGIN"
    echo "Header always set Referrer-Policy strict-origin-when-cross-origin"
    [[ "$HSTS" == 1 ]] && echo 'Header always set Strict-Transport-Security "max-age=63072000"'
    [[ "$HSTS" == 2 ]] && echo 'Header always set Strict-Transport-Security "max-age=63072000; includeSubDomains"'
    if [[ "$CSP" == 1 ]]; then
      echo "Header always add Content-Security-Policy \"frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'; upgrade-insecure-requests\""
      echo "Header always add Content-Security-Policy-Report-Only \"default-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'\""
    fi
    echo "  END_extraHeaders"
    echo "  # HEADERS-END"
  } > "$F.block"
  awk 'NR==FNR{b=b $0 "\n"; next} {print} /^context \/ \{/ && !done{printf "%s", b; done=1}' "$F.block" "$F" > "$F.tmp" && cat "$F.tmp" > "$F" && rm -f "$F.tmp" "$F.block"
  [[ "$(grep -c '^  # HEADERS-START' "$F")" == 1 ]] || { cp -a "$F.pre-headers" "$F"; echo "insert failed — previous config kept"; exit 1; }
  # force-HTTPS: byte-identical to ssl-issue.sh's block (vhost-add carries
  # it over re-renders; ssl-issue skips it when present). Same-host only;
  # .well-known stays plain-HTTP reachable for ACME HTTP-01 renewals.
  if [[ "$TRUSTED" == 1 ]] && ! grep -q '# karnakpanel-force-https BEGIN' "$F"; then
    if ! awk '/^context \/ \{/{c=1} c&&/rules[[:space:]]+<<<END_rules/{f=1} c&&/^\}/{c=0} END{exit !f}' "$F"; then
      # no rewrite block in context / yet: add a minimal one (merge, never a second)
      cat > "$F.block" <<'BLOCK'
  rewrite  {
    enable                1
    rules                 <<<END_rules
END_rules
  }
BLOCK
      awk 'NR==FNR{b=b $0 "\n"; next} /^\}/ && c{printf "%s", b; c=0} {print} /^context \/ \{/{c=1}' "$F.block" "$F" > "$F.tmp" && cat "$F.tmp" > "$F" && rm -f "$F.tmp" "$F.block"
    fi
    cat > "$F.block" <<'BLOCK'
# karnakpanel-force-https BEGIN (managed — plain HTTP always redirects)
RewriteCond %{REQUEST_URI} !^/\.well-known/
RewriteCond %{SERVER_PORT} ^80$
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
# karnakpanel-force-https END
BLOCK
    awk 'NR==FNR{b=b $0 "\n"; next} {print} /^context \/ \{/{c=1} c && !done && /rules[[:space:]]+<<<END_rules/{printf "%s", b; done=1}' "$F.block" "$F" > "$F.tmp" && cat "$F.tmp" > "$F" && rm -f "$F.tmp" "$F.block"
    grep -q 'RewriteCond %{REQUEST_URI} !^/\\.well-known/' "$F" || { cp -a "$F.pre-headers" "$F"; echo "redirect insert corrupted — previous config kept"; exit 1; }
    echo "force-HTTPS installed"
  elif [[ "$TRUSTED" == 0 ]]; then
    echo "note: no trusted certificate on $D — HTTP->HTTPS redirect skipped (issuing SSL adds it)"
  fi
fi
rm -f "$F.pre-headers"

# panel state follows the file (owner runs this by hand on existing boxes).
# Best-effort: pre-migration DBs lack the columns; $D is regex-validated.
PASS="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
if [[ -n "$PASS" ]]; then
  ON=0; [[ "$ACT" == on ]] && ON=1
  MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "UPDATE domains SET sec_headers=$ON, hsts=$HSTS, csp=$CSP WHERE domain='$D';" 2>/dev/null || true
fi
if [[ "$NORELOAD" == "0" ]]; then
  /usr/local/lsws/bin/lswsctrl reload 2>/dev/null || systemctl reload lsws 2>/dev/null || true
fi
echo "headers $ACT for $D (hsts=$HSTS csp=$CSP)"
