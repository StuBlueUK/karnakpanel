#!/usr/bin/env bash
# exim-retry-patch.sh — idempotently add a retry section to the LIVE exim conf
# (fresh installs render it from installer/configs). The installer's config
# had none, and with no retry rules Exim treats EVERY temporary error as
# permanent: mail to a greylisting or briefly-down server bounced at once
# ("retry timeout exceeded"), and a deferring router (Account Transfer's
# mail hold) bounced instead of queuing. Standard Debian rule: every 15 min
# for 2 h, then growing gaps up to 16 h, then every 6 h, giving up after 4 days.
# Same discipline as exim-root-patch.sh: patched on a copy, checked with
# exim -C -bV, backed up, swapped. Re-runs are no-ops.
set -euo pipefail
CONF="/etc/exim4/exim4.conf"
[[ -f "$CONF" ]] || { echo "no $CONF"; exit 1; }
grep -q '^begin retry' "$CONF" && { echo "already patched"; exit 0; }
TMP="$(mktemp "$CONF.retry-XXXXXX")"
trap 'rm -f "$TMP"' EXIT
cp -a "$CONF" "$TMP"
cat >> "$TMP" <<'EOF'

# KarnakPanel retry rules (managed — do not hand-edit). Without a retry
# section every temporary error is treated as permanent (instant bounce).
begin retry

*                      *           F,2h,15m; G,16h,1h,1.5; F,4d,6h
EOF
exim -C "$TMP" -bV >/dev/null 2>&1 || { echo "config check FAILED — live config untouched"; exit 1; }
cp -a "$CONF" "$CONF.bak-retry-$(date +%Y%m%d%H%M%S)"
cp -a "$TMP" "$CONF"
systemctl reload exim4 2>/dev/null || systemctl restart exim4
echo "patched + exim reloaded"
"$(cd "$(dirname "$0")" && pwd)"/integrity-check.sh --baseline >/dev/null 2>&1 || true
