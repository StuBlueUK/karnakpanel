#!/usr/bin/env bash
# smtp-relay.sh <set|clear|test> — upstream SMTP relay (smarthost) for boxes
# whose port 25 is blocked (most clouds). set writes the relay map + enables
# (flag file), clear removes both. test sends one message through the LIVE
# relay path and prints the transcript tail (proves auth + delivery).
#
# Secrets: the password arrives via a 600 staging file (panel writes it, this
# script moves it root-only and shreds the stage — never argv, because the
# panel logs full commands). The map is root:Debian-exim 640 (Exim's delivery
# user must read it; nobody else can). Nothing secret is ever echoed.
#
# Config stanzas (routers/transports/authenticator) are ensured here,
# idempotently with markers — panel updates never touch exim.conf, so
# upgraders get them on first save (same pattern as exim-remote-patch.sh).
# Fresh installs render them from installer/configs. IDEMPOTENT, fails loudly.
set -euo pipefail
CMD="${1:?usage: smtp-relay.sh <set|clear|test> [args]}"
MAP=/etc/exim4/virtual/relay
FLAG=/etc/exim4/virtual/relay_on
CONF=/etc/exim4/exim4.conf
ensure_stanzas() {
  local MARK="# KarnakPanel relay block (managed — do not hand-edit)"
  # fresh installs render the same blocks from the installer template (no
  # marker there) — either form counts as present, never duplicate
  if grep -qF "$MARK" "$CONF" 2>/dev/null || grep -q '^relay_route:' "$CONF" 2>/dev/null; then echo "stanzas present"; return 0; fi
  grep -q '^begin routers$' "$CONF" || { echo "exim anchor missing — refusing"; exit 1; }
  cp -a "$CONF" "$CONF.bak-$(date +%Y%m%d)"
  python3 - "$CONF" <<'PYEOF'
import sys
path = sys.argv[1]
routers = """# KarnakPanel relay block (managed — do not hand-edit)
# Upstream relay (panel Email page): flag file on -> all non-local outbound
# via smarthost. Port 465 takes the smtps transport, anything else STARTTLS.
relay_tls:
  driver = manualroute
  condition = ${if and{{exists{/etc/exim4/virtual/relay_on}}{eq{${lookup{port}lsearch{/etc/exim4/virtual/relay}{$value}{}}}{465}}}{yes}{no}}
  transport = relay_smtps
  route_list = * ${lookup{host}lsearch{/etc/exim4/virtual/relay}{$value}fail}::465 byname
  self = send
  no_more
relay_route:
  driver = manualroute
  condition = ${if and{{exists{/etc/exim4/virtual/relay_on}}{!eq{${lookup{port}lsearch{/etc/exim4/virtual/relay}{$value}{}}}{465}}}{yes}{no}}
  transport = relay_smtp
  route_list = * ${lookup{host}lsearch{/etc/exim4/virtual/relay}{$value}fail}::${lookup{port}lsearch{/etc/exim4/virtual/relay}{$value}{587}} byname
  self = send
  no_more
"""
auth = """# KarnakPanel relay block (managed — do not hand-edit)
relay_login:
  driver = plaintext
  public_name = LOGIN
  client_send = : ${lookup{user}lsearch{/etc/exim4/virtual/relay}{$value}fail} : ${lookup{pass}lsearch{/etc/exim4/virtual/relay}{$value}fail}
"""
tp = """# KarnakPanel relay block (managed — do not hand-edit)
relay_smtp:
  driver = smtp
  port = ${lookup{port}lsearch{/etc/exim4/virtual/relay}{$value}{587}}
  hosts_require_auth = *
  hosts_require_tls = *
  dkim_domain = ${lookup{$sender_address_domain}lsearch{/etc/exim4/virtual/dkim}{$sender_address_domain}{}}
  dkim_selector = default
  dkim_private_key = ${lookup{$sender_address_domain}lsearch{/etc/exim4/virtual/dkim}{$value}{}}
relay_smtps:
  driver = smtp
  protocol = smtps
  port = 465
  hosts_require_auth = *
  dkim_domain = ${lookup{$sender_address_domain}lsearch{/etc/exim4/virtual/dkim}{$sender_address_domain}{}}
  dkim_selector = default
  dkim_private_key = ${lookup{$sender_address_domain}lsearch{/etc/exim4/virtual/dkim}{$value}{}}
"""
src = open(path).read()
assert src.count("begin routers\n") == 1, "router anchor not unique"
src = src.replace("begin routers\n", "begin routers\n" + routers, 1)
assert "begin authenticators\n" in src, "auth anchor missing"
src = src.replace("begin authenticators\n", "begin authenticators\n" + auth, 1)
assert "begin transports\n" in src, "transport anchor missing"
src = src.replace("begin transports\n", "begin transports\n" + tp, 1)
open(path, "w").write(src)
print("stanzas inserted")
PYEOF
  if exim -bV >/dev/null 2>&1; then
    systemctl reload exim4 2>/dev/null || systemctl restart exim4
  else
    LATEST="$(ls -t "$CONF".bak-* 2>/dev/null | head -1)"
    [[ -n "$LATEST" ]] && cp -a "$LATEST" "$CONF"
    echo "config check FAILED — restored backup"; exit 1
  fi
  "$(cd "$(dirname "$0")" && pwd)"/integrity-check.sh --baseline >/dev/null 2>&1 || true
}
case "$CMD" in
  set)
    H="${2:?}"; P="${3:?}"; U="${4:?}"; PWF="${5:?}"
    if [[ "$H" =~ ^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$ ]]; then :;
    elif [[ "$H" =~ ^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}$ ]]; then :;
    else echo "bad host"; exit 1; fi
    [[ "$P" =~ ^[0-9]{2,5}$ && "$P" -ge 1 && "$P" -le 65535 ]] || { echo "bad port"; exit 1; }
    [[ "$U" =~ ^[^[:space:]:]{1,190}$ ]] || { echo "bad user"; exit 1; }
    [[ -f "$PWF" ]] || { echo "missing password file"; exit 1; }
    PW="$(cat "$PWF")"
    shred -u "$PWF" 2>/dev/null || rm -f "$PWF"
    [[ "${#PW}" -ge 1 && "${#PW}" -le 500 && "$PW" != *$'\n'* ]] || { echo "bad password"; exit 1; }
    # LOGIN auth splits client_send on ':' — a colon password can never
    # authenticate; refuse with guidance instead of silent 535s
    [[ "$PW" != *":"* ]] || { echo "password must not contain ':' (Exim LOGIN framing) — regenerate without it"; exit 1; }
    ensure_stanzas
    mkdir -p /etc/exim4/virtual
    { echo "host: $H"; echo "port: $P"; echo "user: $U"; echo "pass: $PW"; } > "$MAP.tmp"
    unset PW
    chmod 600 "$MAP.tmp"
    chown root:Debian-exim "$MAP.tmp" 2>/dev/null || chown root:root "$MAP.tmp"
    chmod 640 "$MAP.tmp"
    mv "$MAP.tmp" "$MAP"
    touch "$FLAG"
    echo "relay on: $H:$P as $U"
    ;;
  clear)
    rm -f "$MAP" "$FLAG"
    echo "relay off — direct delivery restored"
    ;;
  status)
    # machine-readable for the panel (host/user only — the password is never
    # printed by any subcommand)
    if [[ -f "$FLAG" && -f "$MAP" ]]; then
      echo "on=1"
      grep -E '^(host|port|user): ' "$MAP" 2>/dev/null | sed 's/: /=/' || true
    else
      echo "on=0"
    fi
    ;;
  test)
    TO="${2:?}"
    [[ "$TO" =~ ^[^[:space:]@]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$ ]] || { echo "bad address"; exit 1; }
    [[ -f "$FLAG" && -f "$MAP" ]] || { echo "relay not configured — save first"; exit 1; }
    MSG="$(mktemp)"
    printf 'From: relay-test@%s\nTo: %s\nSubject: relay test %s\n\nRelay path test.\n' "$(hostname -f 2>/dev/null || hostname)" "$TO" "$(date +%s)" > "$MSG"
    OUT="$(timeout 60 /usr/sbin/exim -v -odf "$TO" < "$MSG" 2>&1 || true)"
    rm -f "$MSG"
    # exit codes lie here (Exim exits 0 even when it gives up after deferring),
    # so judge by the transcript: auth/rejection markers fail, a 250
    # completion marker passes, anything else fails safe with the transcript
    SIG="$(echo "$OUT" | grep -E "SMTP>>|SMTP<<|authenticator failed|=> |\*\* | 535 |rejected|refused|Completed" | tail -8 || true)"
    echo "$SIG"
    if echo "$OUT" | grep -qE "authenticator failed| 535 |rejected|refused"; then exit 1; fi
    if echo "$OUT" | grep -qE "=> .* C=\"250"; then exit 0; fi
    exit 1
    ;;
  *) echo "usage: smtp-relay.sh <set|clear|test> [args]"; exit 1;;
esac
