#!/usr/bin/env bash
# mail-sni.sh [rebuild] — per-domain TLS for mail (Dovecot + Exim SNI).
# Thunderbird et al. connect to mail.<domain> but the daemons served only
# the box cert (CN mismatch). This maps every live LE cert:
#   Dovecot: local_name blocks in /etc/dovecot/skypanel-sni.conf (one per
#     SAN — served cert always covers the requested name, no guessing).
#   Exim: allowlist map /etc/exim4/virtual/mailcerts (SNI name -> cert path;
#     lookups sanitize the tainted $tls_sni — direct paths would fail
#     Exim's taint check; the key is derived by suffix swap, result trusted).
# Keys are COPIED to /etc/exim4/certs/<domain>/ (Debian-exim-readable):
# LE privkeys are 600 root and renewals mint new files (ACLs wouldn't
# survive) — copies converge every rebuild. Run after every issuance and
# as certbot --deploy-hook (renewals), plus nightly reconcile via cron.
# Idempotent: no changes -> no daemon reloads.
set -euo pipefail
DAVEC="/etc/dovecot/skypanel-sni.conf"
EMAP="/etc/exim4/virtual/mailcerts"
EKEYMAP="/etc/exim4/virtual/mailkeys"
CDIR="/etc/exim4/certs"
LE_LIVE="${LE_LIVE:-/etc/letsencrypt/live}"
need() { command -v "$1" >/dev/null 2>&1 || { echo "missing $1"; exit 1; }; }
need openssl
mkdir -p "$CDIR" /etc/exim4/virtual /etc/dovecot
# Dovecot 2.4 (Debian 13+) renamed the cert settings and takes plain paths
# (no "<" file-read prefix); 2.3 syntax there is a config error.
if [[ "$(dovecot --version 2>/dev/null)" =~ ^2\.[0-3]\. ]]; then
  DV_CRT='ssl_cert = <'; DV_KEY='ssl_key = <'
else
  DV_CRT='ssl_server_cert_file = '; DV_KEY='ssl_server_key_file = '
fi
TDAV="$(mktemp)"; TEMAP="$(mktemp)"; TEKEY="$(mktemp)"; trap 'rm -f "$TDAV" "$TEMAP" "$TEKEY"' EXIT
echo "# mail-sni: per-domain mail TLS (managed — mail-sni.sh rebuild)" > "$TDAV"
echo "# $(date -Is)" >> "$TDAV"
N=0
KEEP=()
  for live in "$LE_LIVE"/*/; do
    D="$(basename "$live")"
    [[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || continue
    CRT="$live/fullchain.pem"; KEY="$live/privkey.pem"
    [[ -f "$CRT" && -f "$KEY" ]] || continue
    openssl x509 -in "$CRT" -noout -checkend 86400 >/dev/null 2>&1 || { echo "mail-sni: $D cert expires <24h, skipped" >&2; continue; }
    mkdir -p "$CDIR/$D"; KEEP+=("$D")
    cp -f "$CRT" "$CDIR/$D/fullchain.pem"; cp -f "$KEY" "$CDIR/$D/privkey.pem"
    chown -R root:Debian-exim "$CDIR/$D" 2>/dev/null || chown -R root:root "$CDIR/$D"
    chmod 640 "$CDIR/$D/privkey.pem"; chmod 644 "$CDIR/$D/fullchain.pem"
    # SANs only: never serve a cert for a name it doesn't cover.
    while IFS= read -r san; do
      san="${san#DNS:}"; san="$(echo "$san" | tr -d ' ')"
      [[ "$san" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || continue
      if [[ "$san" == "$D" || "$san" == *".$D" ]]; then
        printf 'local_name %s {\n  %s/etc/exim4/certs/%s/fullchain.pem\n  %s/etc/exim4/certs/%s/privkey.pem\n}\n' "$san" "$DV_CRT" "$D" "$DV_KEY" "$D" >> "$TDAV"
        printf '%s: /etc/exim4/certs/%s/fullchain.pem\n' "$san" "$D" >> "$TEMAP"
        printf '%s: /etc/exim4/certs/%s/privkey.pem\n' "$san" "$D" >> "$TEKEY"
        N=$((N+1))
      fi
    done < <(openssl x509 -in "$CRT" -noout -ext subjectAltName 2>/dev/null | grep -o 'DNS:[^,]*' || true)
  done
  echo "# $N SNI name(s)" >> "$TDAV"
same() { # same <tmpfile> <livefile>: compare ignoring managed header lines
  [[ -f "$2" ]] && diff -q <(grep -v '^# ' "$1" || true) <(grep -v '^# ' "$2" || true) >/dev/null 2>&1
}
if same "$TDAV" "$DAVEC"; then
  echo "dovecot SNI map unchanged"
else
  cp -a "$TDAV" "$DAVEC"; chmod 644 "$DAVEC"
  echo "dovecot SNI map updated"
  doveadm reload 2>&1 | tail -1 || systemctl reload dovecot 2>&1 | tail -1 || true
fi
if same "$TEMAP" "$EMAP"; then
  echo "exim SNI map unchanged"
else
  cp -a "$TEMAP" "$EMAP"
  chown root:Debian-exim "$EMAP" 2>/dev/null || true; chmod 640 "$EMAP"
  echo "exim SNI map updated (live immediately, no reload needed)"
fi
if same "$TEKEY" "$EKEYMAP"; then
  echo "exim SNI key map unchanged"
else
  cp -a "$TEKEY" "$EKEYMAP"
  chown root:Debian-exim "$EKEYMAP" 2>/dev/null || true; chmod 640 "$EKEYMAP"
  echo "exim SNI key map updated (live immediately, no reload needed)"
fi
# prune cert copies with no live LE cert (deleted domains) — only AFTER the
# maps above stopped naming them (dovecot refuses a missing ssl file)
for c in "$CDIR"/*/; do
  [[ -d "$c" ]] || continue
  C="$(basename "$c")"
  [[ "$C" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || continue
  [[ " ${KEEP[*]} " == *" $C "* ]] && continue
  grep -qsF "/etc/exim4/certs/$C/" "$DAVEC" "$EMAP" "$EKEYMAP" && continue
  rm -f "$c/fullchain.pem" "$c/privkey.pem" && rmdir "$c" 2>/dev/null && echo "mail-sni: pruned $C"
done
echo "mail-sni done"
