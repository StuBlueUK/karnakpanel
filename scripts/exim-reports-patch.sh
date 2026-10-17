#!/usr/bin/env bash
# exim-reports-patch.sh — idempotently insert the virtual_reports router +
# reports_drop transport into the LIVE /etc/exim4/exim4.conf (which carries
# substituted secrets, so it is patched, never overwritten). Fresh installs
# render both from installer/configs; upgraders get them on the first
# "Collect reports" / MTA-STS enable. Re-runs are no-ops. Verifies the config
# loads, then reloads (restores the backup on failure). Same conventions as
# exim-remote-patch.sh.
set -euo pipefail
CONF="/etc/exim4/exim4.conf"
RMARK="# KarnakPanel reports router (managed — do not hand-edit)"
TMARK="# KarnakPanel reports transport (managed — do not hand-edit)"
mkdir -p /etc/exim4/virtual
touch /etc/exim4/virtual/reports
chmod 644 /etc/exim4/virtual/reports 2>/dev/null || true
if grep -qF "$RMARK" "$CONF" 2>/dev/null && grep -qF "$TMARK" "$CONF" 2>/dev/null; then
  echo "already patched"; exit 0
fi
[[ $(grep -c '^begin routers$' "$CONF") == 1 ]] || { echo "router anchor not unique — refusing"; exit 1; }
[[ $(grep -c '^begin transports$' "$CONF") == 1 ]] || { echo "transport anchor not unique — refusing"; exit 1; }
BAK="$CONF.bak-reports-$(date +%Y%m%d%H%M%S)"
cp -a "$CONF" "$BAK"
python3 - "$CONF" "$RMARK" "$TMARK" <<'PYEOF'
import sys, re
path, rmark, tmark = sys.argv[1], sys.argv[2], sys.argv[3]
src = open(path).read()
router = rmark + """
# DMARC/TLS-RPT aggregate intake (panel Email page): opted-in report
# addresses land in a spool maildir parsed by the root ingest cron.
virtual_reports:
  driver = accept
  domains = +local_domains
  require_files = /etc/exim4/virtual/reports
  condition = ${lookup{$local_part@$domain}lsearch{/etc/exim4/virtual/reports}{yes}{no}}
  transport = reports_drop
"""
transport = tmark + """
reports_drop:
  driver = appendfile
  directory = ${lookup{$local_part@$domain}lsearch{/etc/exim4/virtual/reports}{$value}fail}
  maildir_format
  quota = 100M
  create_directory
  envelope_to_add
  user = vmail
  group = vmail
  mode = 0600
  directory_mode = 0700
"""
if rmark not in src:
    # before the first local-delivery router: virtual_forward when present
    # (stock layout), else straight after any remote_route block / the anchor
    m = re.search(r'^virtual_forward:\n', src, re.M)
    if m:
        src = src[:m.start()] + router + src[m.start():]
    else:
        a = "begin routers\n"
        assert src.count(a) == 1
        i = src.index(a) + len(a)
        r = re.compile(r'^remote_route:\n(?:[ \t].*\n)*', re.M).match(src, i)
        if r is None:
            # remote_route may follow comment lines / relay routers: find it
            r = re.compile(r'^remote_route:\n(?:[ \t].*\n)*', re.M).search(src, i)
        j = r.end() if r else i
        src = src[:j] + router + src[j:]
if tmark not in src:
    a = "begin transports\n"
    assert src.count(a) == 1
    src = src.replace(a, a + transport, 1)
open(path, 'w').write(src)
PYEOF
if exim -bV >/dev/null 2>&1; then
  systemctl reload exim4 2>/dev/null || systemctl restart exim4
  echo "patched + exim reloaded"
else
  cp -a "$BAK" "$CONF"
  echo "config check FAILED — restored backup"; exit 1
fi
"$(cd "$(dirname "$0")" && pwd)"/integrity-check.sh --baseline >/dev/null 2>&1 || true
