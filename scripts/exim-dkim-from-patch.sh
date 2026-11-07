#!/usr/bin/env bash
# exim-dkim-from-patch.sh — idempotently make the LIVE exim conf DKIM-sign
# locally submitted mail with its From: domain. PHP mail()/sendmail from a
# hosting account gets the envelope sender <account>@<hostname> (untrusted
# callers can't set -f), so signing by envelope domain left every mail()
# message — WordPress, contact and signup forms — unsigned, and DMARC failed
# for the site's domain. Now: when the mail came in locally, the From: domain
# has a DKIM key AND the domainowners map (mail-owners-sync.sh) says the
# submitting unix account owns it, sign as the From: domain. Otherwise the old
# envelope-domain rule applies — a tenant can never get another tenant's
# domain signed. The key is looked up by $dkim_domain (the domain actually
# signed), so a From:-signed message never uses the envelope domain's key.
# Fresh installs render the same from installer/configs. Patched on a copy,
# checked with exim -C -bV, backed up, swapped. Re-runs are no-ops.
set -euo pipefail
CONF="/etc/exim4/exim4.conf"
[[ -f "$CONF" ]] || { echo "no $CONF"; exit 1; }
touch /etc/exim4/virtual/domainowners
grep -q '^KARNAK_DKIM_DOM = ' "$CONF" && { echo "already patched"; exit 0; }
TMP="$(mktemp "$CONF.dkimfrom-XXXXXX")"
trap 'rm -f "$TMP"' EXIT
cp -a "$CONF" "$TMP"
python3 - "$TMP" <<'PYEOF'
import sys
path = sys.argv[1]
src = open(path).read()
old_dom = "  dkim_domain = ${lookup{$sender_address_domain}lsearch{/etc/exim4/virtual/dkim}{$sender_address_domain}{}}\n"
old_key = "  dkim_private_key = ${lookup{$sender_address_domain}lsearch{/etc/exim4/virtual/dkim}{$value}{}}\n"
new_dom = "  dkim_domain = KARNAK_DKIM_DOM\n"
new_key = "  dkim_private_key = ${lookup{$dkim_domain}lsearch{/etc/exim4/virtual/dkim}{$value}{}}\n"
nd, nk = src.count(old_dom), src.count(old_key)
if nd == 0 or nd != nk:
    sys.exit(f"dkim transport lines not as shipped (domain x{nd}, key x{nk}) — refusing")
anchor = "acl_smtp_rcpt = acl_check_rcpt\n"
if src.count(anchor) != 1:
    sys.exit("acl_smtp_rcpt anchor not found/unique — refusing")
macros = r"""# KarnakPanel DKIM-by-From (managed — do not hand-edit). Locally submitted
# mail (PHP mail()/sendmail; envelope = <account>@<hostname>) is signed as its
# From: domain when that domain has a key AND belongs to the submitting account
# (domainowners map). Everything else: the envelope domain, as before.
KARNAK_FROM_DOM = ${lc:${domain:${address:$h_from:}}}
KARNAK_DKIM_DOM = ${if and{{match{$received_protocol}{\N^local\N}}{!eq{$sender_ident}{}}{!eq{KARNAK_FROM_DOM}{}}{eq{${lookup{KARNAK_FROM_DOM}lsearch{/etc/exim4/virtual/domainowners}{$value}{}}}{$sender_ident}}{!eq{${lookup{KARNAK_FROM_DOM}lsearch{/etc/exim4/virtual/dkim}{$value}{}}}{}}}{KARNAK_FROM_DOM}{${lookup{$sender_address_domain}lsearch{/etc/exim4/virtual/dkim}{$sender_address_domain}{}}}}
"""
src = src.replace(anchor, macros + anchor).replace(old_dom, new_dom).replace(old_key, new_key)
open(path, 'w').write(src)
PYEOF
exim -C "$TMP" -bV >/dev/null 2>&1 || { echo "config check FAILED — live config untouched"; exit 1; }
cp -a "$CONF" "$CONF.bak-dkimfrom-$(date +%Y%m%d%H%M%S)"
cp -a "$TMP" "$CONF"
systemctl reload exim4 2>/dev/null || systemctl restart exim4
echo "patched + exim reloaded"
"$(cd "$(dirname "$0")" && pwd)"/integrity-check.sh --baseline >/dev/null 2>&1 || true
