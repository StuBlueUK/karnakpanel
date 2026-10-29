#!/usr/bin/env bash
# exim-rcpt-patch.sh — idempotently add two RCPT ACL rules to the LIVE exim
# conf (fresh installs render them from installer/configs):
#   - mail-guard hold: a mailbox flagged by cli/mail-guard.php (suspected
#     compromise) may not send until its password is reset / admin release;
#   - rcpt verify: unknown hosted recipients are refused at RCPT instead of
#     accepted then bounced to (often forged) senders — backscatter.
# Same discipline as exim-localuser-patch.sh: the live conf carries
# substituted secrets, so it is patched on a copy, checked with exim -C -bV,
# backed up, then swapped. Re-runs are no-ops; a hand-edited ACL is refused.
set -euo pipefail
CONF="/etc/exim4/exim4.conf"
[[ -f "$CONF" ]] || { echo "no $CONF"; exit 1; }
H=0; V=0
grep -q '^  # KarnakPanel mail-guard hold' "$CONF" && H=1
grep -q '^  # KarnakPanel rcpt verify' "$CONF" && V=1
(( H && V )) && { echo "already patched"; exit 0; }
(( H || V )) && { echo "half-patched ACL (hold=$H verify=$V) — refusing, fix by hand"; exit 1; }
# the hold rule looks up email_accounts.send_hold_at per authenticated RCPT:
# without migration 049's column the lookup errors and EVERY authenticated
# send defers (4xx). Never patch ahead of the schema.
mysql -uroot skypanel -e "SELECT send_hold_at FROM email_accounts LIMIT 0" >/dev/null 2>&1 \
  || { echo "email_accounts.send_hold_at missing (migration 049 not applied) — refusing"; exit 1; }
TMP="$(mktemp "$CONF.rcpt-XXXXXX")"
trap 'rm -f "$TMP"' EXIT
cp -a "$CONF" "$TMP"
python3 - "$TMP" <<'PYEOF'
import sys
path = sys.argv[1]
anchor = "  accept domains = +local_domains\n  accept authenticated = *\n"
rules = r"""  # KarnakPanel mail-guard hold (managed — do not hand-edit)
  # compromised-mailbox hold (cli/mail-guard.php sets it, Email page clears
  # it on password reset or admin release): a held mailbox sends nothing.
  deny authenticated = *
    condition = ${lookup mysql{SELECT 1 FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE CONCAT(e.localpart,'@',d.domain)='${quote_mysql:$authenticated_id}' AND e.send_hold_at IS NOT NULL}{yes}{no}}
    message = sending suspended for this mailbox (suspected compromise) — reset its password in the panel
    log_message = mail-guard hold: $authenticated_id
  # KarnakPanel rcpt verify (managed — do not hand-edit)
  # unknown hosted recipients refused at RCPT: accept-then-bounce mailed NDRs
  # to forged senders (backscatter -> blocklists). The routers in verify mode
  # are the existence check, so mailboxes/plus/forwarders/lists/catch-alls/
  # reports/remote-handled domains stay exact; DNS trouble -> 4xx, never 5xx.
  # log_message deliberately puts our own text right after the address: the
  # fail2ban exim filter bans on "rejected RCPT <a>: Unrouteable address"
  # (Exim still appends the verify detail after ours — that line is not
  # matched), and big senders hitting a stale address must never get banned.
  # Emergency off: touch /etc/exim4/virtual/rcpt_verify_off (no reload).
  deny domains = +local_domains
    condition = ${if exists{/etc/exim4/virtual/rcpt_verify_off}{no}{yes}}
    !verify = recipient
    message = ${if def:acl_verify_message{$acl_verify_message}{no such recipient}}
    log_message = hosted recipient refused (verify: $acl_verify_message)
"""
src = open(path).read()
if src.count(anchor) != 1:
    sys.exit("RCPT ACL anchor not found/unique — refusing")
open(path, 'w').write(src.replace(anchor, rules + anchor))
PYEOF
exim -C "$TMP" -bV >/dev/null 2>&1 || { echo "config check FAILED — live config untouched"; exit 1; }
cp -a "$CONF" "$CONF.bak-rcpt-$(date +%Y%m%d%H%M%S)"
cp -a "$TMP" "$CONF"
systemctl reload exim4 2>/dev/null || systemctl restart exim4
echo "patched + exim reloaded"
"$(cd "$(dirname "$0")" && pwd)"/integrity-check.sh --baseline >/dev/null 2>&1 || true
