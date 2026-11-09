#!/usr/bin/env bash
# dovecot-dh-patch.sh — idempotently point the LIVE Dovecot 2.3 config at the
# distro DH parameters. Our dovecot.conf replaces the stock one (no conf.d
# include), so the stock 10-ssl.conf `ssl_dh` line never applied: every
# client offering a DHE cipher got "Diffie-Hellman key exchange requested,
# but no DH parameters provided" and its TLS handshake failed. Dovecot 2.4
# (dovecot24.conf) has no ssl_dh setting — skipped. The live conf may carry
# per-box edits (cert paths), so it is patched on a copy, checked with
# doveconf, never overwritten from the template. Re-runs are no-ops.
set -euo pipefail
CONF=/etc/dovecot/dovecot.conf
DH=/usr/share/dovecot/dh.pem
[[ -f "$CONF" && -s "$DH" ]] || exit 0
doveconf ssl_dh >/dev/null 2>&1 || exit 0          # 2.4: setting doesn't exist
grep -qE '^[[:space:]]*ssl_dh[[:space:]]*=' "$CONF" && exit 0
grep -q '^ssl_min_protocol' "$CONF" || { echo "dovecot-dh-patch: no ssl_min_protocol line — refusing"; exit 1; }
TMP="$(mktemp "$CONF.dh-XXXXXX")"
trap 'rm -f "$TMP"' EXIT
sed "/^ssl_min_protocol/a ssl_dh = <$DH" "$CONF" > "$TMP"
doveconf -c "$TMP" -n >/dev/null 2>&1 || { echo "dovecot-dh-patch: config check FAILED — live config untouched"; exit 1; }
cp -a "$CONF" "$CONF.bak-dh-$(date +%Y%m%d%H%M%S)"
cat "$TMP" > "$CONF"
systemctl reload dovecot 2>/dev/null || systemctl restart dovecot
echo "dovecot-dh-patch: ssl_dh set + dovecot reloaded"
"$(cd "$(dirname "$0")" && pwd)"/integrity-check.sh --baseline >/dev/null 2>&1 || true
