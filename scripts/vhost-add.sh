#!/usr/bin/env bash
# vhost-add.sh <domain> <username> [php_version]
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/php-versions.sh"
D="${1:?}"; U="${2:?}"; PHPV="${3:-$PHP_DEFAULT}"
[[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ && "$D" != *..* ]] || { echo "bad domain: $D"; exit 1; }
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad user: $U"; exit 1; }
DOC="/home/$U/$D/public_html"
# root chowns/writes below follow symlinks: a planted link (domain dir ->
# /etc, index.html -> /etc/sudoers.d/x) would hand the target to the customer
for p in "/home/$U" "/home/$U/$D" "$DOC" "$DOC/index.html" "$DOC/.well-known" "$DOC/.well-known/acme-challenge"; do
  [[ ! -L "$p" ]] || { echo "REFUSED: $p is a symlink"; exit 1; }
done
mkdir -p "$DOC"; chown "$U:" "$DOC"
# the domain dir itself: owner + traverse-only for others (pool user must
# traverse it; root-owned 750 here 403s/404s ALL PHP for the domain)
chown "$U:" "/home/$U/$D"; chmod 711 "/home/$U/$D"
if command -v setfacl >/dev/null 2>&1; then
  setfacl -m u:nobody:rX "/home/$U/$D" 2>/dev/null || true
  # owning-group list rights: suffix FTP logins share the home group
  # (ftp-add.sh) and must see inside the domain dir (group has nothing at 711)
  setfacl -m g::rX "/home/$U/$D" 2>/dev/null || true
fi
# (re)apply web-worker ACL — mkdir -p does NOT retro-apply default ACLs to
# pre-existing dirs (e.g. half-provisioned domains), which 403s the site
if command -v setfacl >/dev/null 2>&1; then
  setfacl -d -m u:nobody:rX -m u::rwx -m g::rwX -m o::--- "$DOC" 2>/dev/null || true
  setfacl -m u:nobody:rX "$DOC" 2>/dev/null || true
  # owning-group read/write on the docroot: FTP uploads + sibling logins
  setfacl -R -m g::rwX "$DOC" 2>/dev/null || true
fi
# coming-soon page: create-only (re-renders for rewrite syncs must never
# clobber a customer's index file — nor re-seed one beside an index.php the
# owner kept after deleting the placeholder; imports land the same way)
if [[ ! -f "$DOC/index.html" && ! -f "$DOC/index.php" && ! -f "$DOC/index.htm" ]]; then
cat > "$DOC/index.html" <<EOF
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>$D — coming soon</title></head>
<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#0b1220,#0ea5e9);font-family:Segoe UI,Arial,sans-serif;color:#e2e8f0">
<div style="text-align:center;background:rgba(11,18,32,.72);border:1px solid #1e3a5f;border-radius:18px;padding:44px 56px;box-shadow:0 20px 60px rgba(0,0,0,.45)">
<svg width="72" height="72" viewBox="0 0 64 64"><rect width="64" height="64" rx="14" fill="#0b3b66"/><circle cx="32" cy="17" r="7" fill="#fbbf24"/><path d="M12 48 L32 24 L52 48 Z" fill="#d4a437"/><path d="M32 24 L52 48 L32 48 Z" fill="#92400e" opacity=".45"/><rect x="10" y="48" width="44" height="4" rx="2" fill="#f6d365" opacity=".85"/></svg>
<h1 style="margin:18px 0 6px">$D</h1>
<p style="color:#94a3b8;margin:0">A new website is on its way.</p>
<p style="margin:22px 0 0;font-size:13px;color:#38bdf8">Hosted with 🏛️ KarnakPanel</p>
</div></body></html>
EOF
chown "$U:" "$DOC/index.html"; chmod 640 "$DOC/index.html"  # nobody reads via dir default ACL
fi
# register domain as Exim-local (lsearch file)
mkdir -p /etc/exim4/virtual
touch /etc/exim4/virtual/domains /etc/exim4/virtual/aliases
grep -qxF "$D: ok" /etc/exim4/virtual/domains 2>/dev/null || echo "$D: ok" >> /etc/exim4/virtual/domains
VH="/usr/local/lsws/conf/vhosts/$D"
mkdir -p "$VH"
# Per-user PHP-FPM pool (isolated UID + open_basedir). OLS talks FastCGI to it.
# Preserve-only (same rule as create-account.sh): pools are per-USER while the
# version arg is per-domain — rebuilding here would silently downgrade every
# other domain of this user. Deliberate moves belong to php-switch.sh.
if ls /etc/php/8.*/fpm/pool.d/skypanel-"$U".conf >/dev/null 2>&1; then
  echo "pool for $U exists — keeping version (move it from the PHP page)"
else
  "$(dirname "$0")/php-pool.sh" "$U" "${PHPV/./}"
fi
# NOTE: OLS config is effectively line-oriented — every block MUST be multi-line.
# Single-line blocks (e.g. `scripthandler { add ... }`) are silently IGNORED.
# Handler names here are per-USER (fpm-$U), never per-version: switching PHP
# versions moves the FPM pool, the OLS side stays untouched.
# per-domain PHP error log must exist and be writable by the POOL user (FPM
# runs as $U, not the web worker) or PHP silently drops the errors
touch "/var/log/karnakpanel/$D.error.log" "/var/log/karnakpanel/$D.access.log"
chown "$U:" "/var/log/karnakpanel/$D.error.log" 2>/dev/null || true
# optional HTTP lock (App Toolkit staging): STAGING_REALM=1 emits a vhost-level
# realm DEFINITION plus a context reference, expecting $VH/htpasswd with
# APR1 ($apr1$) entries — crypt-DES never verifies on Ubuntu 24.04 (libcrypt
# dropped DES; OLS logs "Failed in ENCRYPT_CRYPT"). Definition MUST sit at
# vhost level (nested in context = silently ignored -> deny-all). Multi-line
# only (gotcha: single-line blocks are silently ignored). $VH_ROOT survives
# single-pass expansion. htpasswd must be nobody-readable (docs).
REALM_REF=""
REALM_DEF=""
if [[ -n "${STAGING_REALM:-}" ]]; then
  REALM_REF="  realm                 Staging
  authName              Staging
"
  REALM_DEF="realm Staging {
  userDB  {
    cacheTimeout          60
    maxCacheSize          200
    location              \$VH_ROOT/htpasswd
  }
}

"
fi
# per-domain custom rewrite rules (Domains page / .htaccess import): the panel
# stores them in domains.rewrite_rules; rewrite-sync.sh stages the exact
# bytes in $REWRITE_FILE and they are spliced in with sed 'r' (byte-verbatim,
# NO shell expansion — an ${...//} escape dance here once shipped every $
# as \$ and silently killed all custom rules).
CUSTOM_RULES=""
if [[ -n "${REWRITE_FILE:-}" && -f "$REWRITE_FILE" ]]; then
  CUSTOM_RULES="$REWRITE_FILE"
fi
# SSL/force-https preservation: re-renders (rewrite syncs, re-runs) must NEVER
# strip a live certificate — lived on prod 2026-10-14 (site fell back to the
# shared cert + plain HTTP after a rewrite render). Carry both stanzas over.
KEPT_SSL=""; KEPT_FORCE=""
if [[ -f "$VH/vhost.conf" ]]; then
  KEPT_SSL="$(awk '/^vhssl \{/,/^\}/' "$VH/vhost.conf" || true)"
  KEPT_FORCE="$(awk '/# karnakpanel-force-https BEGIN/,/# karnakpanel-force-https END/' "$VH/vhost.conf" || true)"
fi
cat > "$VH/vhost.conf" <<EOF
docRoot                   $DOC
vhDomain                  $D
vhAliases                 www.$D mail.$D
adminEmails               admin@localhost
enableGzip                1

errorlog /var/log/karnakpanel/$D.error.log {
  useServer               0
  logLevel                WARN
  rollingSize             10M
}

accesslog /var/log/karnakpanel/$D.access.log {
  useServer               0
  logFormat               '%h %l %u %t "%r" %>s %b "%{Referer}i" "%{User-agent}i"'
  rollingSize             10M
}

index  {
  useServer               0
  indexFiles              index.php, index.html
}

context / {
  location                \$DOC_ROOT/
  allowBrowse             1
  indexFiles              index.php, index.html
$REALM_REF  rewrite  {
    enable                1
    rules                 <<<END_rules
    # SHIELD-START (KarnakPanel bot shield — waf-shield.sh off to remove)
    RewriteCond %{REQUEST_URI} !^/\.well-known/
    RewriteCond %{REQUEST_URI} \.(git|svn|env|sql|bak|swp|dist)$ [NC,OR]
    RewriteCond %{REQUEST_URI} (c99|r57|b374k|wso|alfa|webshell|backdoor|phpunit|eval-stdin|_wpeprivate) [NC,OR]
    RewriteCond %{QUERY_STRING} (\.\.(/|%2f)|%2e%2e|information_schema|union.*select.*from|into.*outfile|load_file\s*\() [NC,OR]
    RewriteCond %{QUERY_STRING} (<script|javascript:|onerror=|document\.cookie) [NC]
    RewriteRule ^ - [F,L]
    # SHIELD-END
    # CUSTOM-START (Domains page rewrite rules / .htaccess import — rewrite-sync.sh manages)
    # CUSTOM-END
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php [L]
END_rules
  }
}

${REALM_DEF}scripthandler  {
  add                     fcgi:fpm-$U php
}

extprocessor fpm-$U {
  type                    fcgi
  address                 uds:///run/php/skypanel-$U.sock
  maxConns                10
  initTimeout             330
  retryTimeout            5
  # persistConn OFF: persistent backend conns wedge workers forever when
  # clients abort mid-flood (half-open keepalive, no read timeout serverside
  # — max_children=2 pools died permanently). Per-request conns cost
  # microseconds on unix sockets; correctness beats the micro-optimization.
  persistConn             0
  respBuffer              0
  autoStart               0
  backlog                 16
  instances               1
}
EOF
# splice staged custom rules after the CUSTOM-START marker (byte-verbatim).
if [[ -n "$CUSTOM_RULES" ]]; then
  sed -i "/# CUSTOM-START/r $CUSTOM_RULES" "$VH/vhost.conf"
fi
# re-attach preserved SSL + force-https (see above — never render these away).
# The cert files must still exist (a removed cert re-attached would break OLS).
if [[ -n "$KEPT_SSL" ]]; then
  KKEY="$(printf '%s' "$KEPT_SSL" | awk '/keyFile/{print $2}')"
  KCRT="$(printf '%s' "$KEPT_SSL" | awk '/certFile/{print $2}')"
  if [[ -n "$KKEY" && -n "$KCRT" && -f "$KKEY" && -f "$KCRT" ]]; then
    printf '\n%s\n' "$KEPT_SSL" >> "$VH/vhost.conf"
  else
    echo "vhost-add: kept SSL block dropped (cert files gone)" >&2
  fi
fi
if [[ -n "$KEPT_FORCE" ]]; then
  FORCE_TMP="$(mktemp)"; printf '%s\n' "$KEPT_FORCE" > "$FORCE_TMP"
  sed -i "/rules                 <<<END_rules/r $FORCE_TMP" "$VH/vhost.conf"
  rm -f "$FORCE_TMP"
fi
HTTPD=/usr/local/lsws/conf/httpd_config.conf
# idempotent: one virtualhost block per domain, one map line inside the existing Default listener
if ! grep -q "virtualhost $D {" "$HTTPD" 2>/dev/null; then
cat >> "$HTTPD" <<EOF
virtualhost $D {
  vhRoot $VH
  configFile \$VH_ROOT/vhost.conf
  allowSymbolLink 2
  enableScript 1
  restrained 0
}
EOF
fi
if ! grep -q "map $D $D" "$HTTPD" 2>/dev/null; then
  # NOTE: stock OLS writes 'listener Default{' with NO space — match the prefix only
  if grep -q "^listener Default" "$HTTPD" 2>/dev/null; then
    sed -i "/^listener Default/a\\  map $D $D" "$HTTPD"
  else
    printf 'listener Default {\n  address *:80\n  secure 0\n  map %s %s\n}\n' "$D" "$D" >> "$HTTPD"
  fi
fi
# every served name gets an EXPLICIT map (apex + www + mail): vhAliases-only
# routing proved unreliable for new names on prod (mail.* fell through to
# the default vhost despite a correct alias + reloads). Maps are vhost-first.
for H in "www.$D" "mail.$D"; do
  if grep -q "^listener Default" "$HTTPD" 2>/dev/null && ! grep -q "map $D $H\$" "$HTTPD" 2>/dev/null; then
    sed -i "/^listener Default/a\\  map $D $H" "$HTTPD"
  fi
done
# same mapping on the :443 listener so HTTPS (SNI) reaches the vhost.
# Scoped check: only a map line INSIDE the SSL443 block counts.
if grep -q "^listener SSL443" "$HTTPD" 2>/dev/null; then
  if ! sed -n "/^listener SSL443/,/^}/p" "$HTTPD" | grep -q "map $D $D"; then
    sed -i "/^listener SSL443/a\\  map $D $D" "$HTTPD"
  fi
  for H in "www.$D" "mail.$D"; do
    if ! sed -n "/^listener SSL443/,/^}/p" "$HTTPD" | grep -q "map $D $H\$"; then
      sed -i "/^listener SSL443/a\\  map $D $H" "$HTTPD"
    fi
  done
fi
# ACME challenge dir with web-worker traversal from day one (certbot-made
# dirs land root-only and 404 challenges until fixed — lived on prod).
mkdir -p "$DOC/.well-known/acme-challenge"
chown "$U:" "$DOC/.well-known/acme-challenge" 2>/dev/null || true
if command -v setfacl >/dev/null 2>&1; then
  setfacl -m u:nobody:rX "$DOC/.well-known" "$DOC/.well-known/acme-challenge" 2>/dev/null || true
fi
# vhost.conf is in place: nobody keeps the new docroot, others lose the domain dir
"$(dirname "$(readlink -f "$0")")/home-perms.sh" "$U" >/dev/null || true
/usr/local/lsws/bin/lswsctrl restart >/dev/null 2>&1 || true
echo "vhost $D -> $DOC php=$PHPV"
