#!/usr/bin/env bash
# exim-phpmail-patch.sh — idempotently put the per-account PHP mail policy
# into the LIVE exim conf's local-submission ACL (acl_check_not_smtp).
# Before: locally injected mail was capped per ENVELOPE DOMAIN, and every
# hosting account's mail() has the same one (<account>@<hostname>) — the
# whole box shared a single hourly bucket, so one abused contact form could
# starve every other site's password resets. Null senders skipped the cap,
# and any untrusted caller may set -f <> — an uncapped path.
# After: an account listed in /etc/exim4/virtual/phpmail (mail-owners-sync.sh)
# is refused when "off" (admin switch, or Mail Guard after a flood), else
# capped per ACCOUNT in recipients/hour (per_rcpt: one mail() to 500 Bcc
# counts 500), null senders included; "none" = no cap. System senders (root,
# cron daemon, sieve as vmail, bounces as Debian-exim) keep the old rule.
# Plus a router that drops Exim's "rejected by local scanning code" bounce for
# those refusals: PHP already got the error, and while a bot keeps posting a
# switched-off form each refusal would drop another file into ~/Maildir.
# Fresh installs render the same from installer/configs. Patched on a copy,
# checked with exim -C -bV, backed up, swapped. Each part is applied only
# when missing (older builds get the newer parts); re-runs are no-ops.
set -euo pipefail
CONF="/etc/exim4/exim4.conf"
[[ -f "$CONF" ]] || { echo "no $CONF"; exit 1; }
touch /etc/exim4/virtual/phpmail
TMP="$(mktemp "$CONF.phpmail-XXXXXX")"
trap 'rm -f "$TMP"' EXIT
cp -a "$CONF" "$TMP"
RC=0
python3 - "$TMP" <<'PYEOF' || RC=$?
import sys
path = sys.argv[1]
src = open(path).read()
orig = src
# 1. ACL policy
if "  # KarnakPanel PHP mail policy" not in src:
    old = """acl_check_not_smtp:
  # same brake for locally injected mail (PHP mail() is the classic
  # hacked-WordPress path): unattributable to a mailbox, so capped per sender
  # domain at the global default. Null senders (bounces, vacation replies)
  # are exempt.
  deny condition = ${if !eq{$sender_address}{}}
"""
    new = """acl_check_not_smtp:
  # KarnakPanel PHP mail policy (managed — do not hand-edit). Locally injected
  # mail (PHP mail()/sendmail, cron output) from a hosting account is looked up
  # in the phpmail map (mail-owners-sync.sh): "off" = refused (admin switch or
  # Mail Guard after a flood), a number = recipients/hour for that ACCOUNT
  # (all accounts' mail() share the envelope domain <account>@<hostname>, so a
  # per-domain cap was one bucket for the whole box), "none" = no cap. Null
  # senders count too: untrusted callers may always set -f <>.
  deny condition = ${if eq{${lookup{$sender_ident}lsearch{/etc/exim4/virtual/phpmail}{$value}{}}}{off}}
    message = PHP mail is switched off for this account — send through one of your mailboxes over SMTP (port 587) instead
    log_message = php-mail off: $sender_ident
  deny condition = ${if match{${lookup{$sender_ident}lsearch{/etc/exim4/virtual/phpmail}{$value}{}}}{\\N^[0-9]+$\\N}}
    ratelimit = ${lookup{$sender_ident}lsearch{/etc/exim4/virtual/phpmail}{$value}{200}} / 1h / per_rcpt / acct:$sender_ident
    message = sending rate exceeded (hourly cap) — retry later or ask your admin
    log_message = php-mail cap: $sender_ident
  accept condition = ${if !eq{${lookup{$sender_ident}lsearch{/etc/exim4/virtual/phpmail}{$value}{}}}{}}
  # system senders (root, cron daemon, sieve as vmail, bounces): capped per
  # sender domain at the global default; null senders (bounces, vacation
  # replies) are exempt.
  deny condition = ${if !eq{$sender_address}{}}
"""
    if src.count(old) != 1:
        sys.exit("acl_check_not_smtp block not as shipped — refusing")
    src = src.replace(old, new)
# 2. heal a pre-release option order ("per_rcpt is not a time value" = every
#    listed account's mail refused)
src = src.replace("/ per_rcpt / 1h / acct:$sender_ident", "/ 1h / per_rcpt / acct:$sender_ident")
# 3. drop the moan for a refused mail()
if "\nphpmail_moan_drop:\n" not in src:
    anchor = "localuser:\n  driver = accept\n"
    if src.count(anchor) != 1:
        sys.exit("localuser router anchor not found/unique — refusing")
    router = """# KarnakPanel PHP mail moan drop (managed — do not hand-edit). A mail()
# refused by acl_check_not_smtp (PHP mail off, or over the hourly cap) already
# returned the error to PHP; Exim's extra bounce to <account>@<hostname> would
# only pile up in ~/Maildir while a bot keeps posting the form.
phpmail_moan_drop:
  driver = redirect
  domains = @ : localhost
  senders = :
  condition = ${if and{{eq{$received_protocol}{local}}{eq{$h_subject:}{Mail failure - rejected by local scanning code}}{!eq{${lookup{$local_part}lsearch{/etc/exim4/virtual/phpmail}{$value}{}}}{}}}}
  data = :blackhole:
"""
    src = src.replace(anchor, router + anchor)
if src == orig:
    sys.exit(3)
open(path, 'w').write(src)
PYEOF
[[ $RC -eq 3 ]] && { echo "already patched"; exit 0; }
[[ $RC -eq 0 ]] || exit 1
exim -C "$TMP" -bV >/dev/null 2>&1 || { echo "config check FAILED — live config untouched"; exit 1; }
cp -a "$CONF" "$CONF.bak-phpmail-$(date +%Y%m%d%H%M%S)"
cat "$TMP" > "$CONF"
systemctl reload exim4 2>/dev/null || systemctl restart exim4
echo "patched + exim reloaded"
"$(cd "$(dirname "$0")" && pwd)"/integrity-check.sh --baseline >/dev/null 2>&1 || true
