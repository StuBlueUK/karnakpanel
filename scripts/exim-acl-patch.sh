#!/usr/bin/env bash
# exim-acl-patch.sh — idempotently insert the weekend scan-skip block into the
# LIVE /etc/exim4/exim4.conf (which carries substituted secrets, so it is
# patched, never overwritten). Anchored on the stock spam-scan lines; a
# marker comment makes re-runs a no-op. Verifies config loads, then reloads.
set -euo pipefail
CONF="/etc/exim4/exim4.conf"
MARK="# KarnakPanel weekend-scan block (managed — do not hand-edit)"
grep -qF "$MARK" "$CONF" && { echo "already patched"; exit 0; }
grep -q '^  warn spam = nobody/defer_ok$' "$CONF" || { echo "anchor lines not found — refusing"; exit 1; }
cp -a "$CONF" "$CONF.bak-$(date +%Y%m%d)"
python3 - "$CONF" <<'PYEOF'
import sys
path = sys.argv[1]
old = """  warn spam = nobody/defer_ok
  warn add_header = X-Spam-Score: $spam_score ($spam_bar)
"""
new = """  # KarnakPanel weekend-scan block (managed — do not hand-edit)
  warn set acl_m_nospam = ${if and{{match{$tod_full}{Sat|Sun}}{eq{${lookup mysql{SELECT value FROM settings WHERE name='spam_weekend_skip'}{$value}{0}}}{1}}}{yes}{no}}
  warn condition = ${if eq{$acl_m_nospam}{yes}}
       add_header = X-Spam-Skipped: weekend-policy
  warn condition = ${if !eq{$acl_m_nospam}{yes}}
       spam = nobody/defer_ok
  warn condition = ${if !eq{$acl_m_nospam}{yes}}
       add_header = X-Spam-Score: $spam_score ($spam_bar)
"""
src = open(path).read()
assert src.count(old) == 1, "anchor not unique"
open(path, 'w').write(src.replace(old, new))
PYEOF
# router guard: weekend-skipped mail has no score — exclude it explicitly.
# Whole-line swap (exact match on stock line), not brace surgery.
python3 - "$CONF" <<'PYEOF'
import sys
path = sys.argv[1]
old = "  headers_add = ${if >{$spam_score_int}{${lookup mysql{SELECT e.spam_threshold FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE e.localpart='${quote_mysql:$local_part}' AND d.domain='${quote_mysql:$domain}'}{$value}{5.0}}}{X-Spam-Flag: YES}}"
new = "  headers_add = ${if and{{!eq{$acl_m_nospam}{yes}}{>{$spam_score_int}{${lookup mysql{SELECT e.spam_threshold FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE e.localpart='${quote_mysql:$local_part}' AND d.domain='${quote_mysql:$domain}'}{$value}{5.0}}}}}{X-Spam-Flag: YES}}"
src = open(path).read()
if new not in src:
    assert src.count(old) == 1, "router anchor not found/unique"
    open(path, 'w').write(src.replace(old, new))
PYEOF
exim -bV >/dev/null || { echo "config check FAILED"; exit 1; }
systemctl reload exim4 2>/dev/null || systemctl restart exim4
echo "patched + exim reloaded"

# known-legit change source: refresh the integrity baseline (feature: integrity-check)
"$(cd "$(dirname "$0")" && pwd)"/integrity-check.sh --baseline >/dev/null 2>&1 || true
