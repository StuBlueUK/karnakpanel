#!/usr/bin/env bash
# panel-domain.sh <domain> [admin-email] — onboard the PANEL's own domain at
# install time: DB rows + zone + vhost + autoconfig (mirrors
# DomainController::create for the admin user), then LE + services cert ONLY
# if the domain already resolves here (ACME http-01 can't pass otherwise).
# Prints either success or the exact registrar steps. Idempotent-ish: skips
# parts that already exist (re-runs converge, like the installer).
set -euo pipefail
D="${1:?}"; EMAIL="${2:-admin@localhost}"
[[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad domain (want a real FQDN, not an IP)"; exit 1; }
PASS="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
[[ -n "$PASS" ]] || { echo "no panel db pass"; exit 1; }
HERE="$(cd "$(dirname "$0")" && pwd)"
IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
AID="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1;" || true)"
[[ -n "$AID" ]] || { echo "no admin user yet"; exit 1; }
AUSER="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT username FROM users WHERE id=$AID;")"
# admin account email: Issue-SSL reads the ACME contact from the users table,
# so a real address must land there (seed is admin@localhost, which LE rejects).
# Re-run safe: only replaces placeholder/empty, never an owner-set address.
if [[ "$EMAIL" =~ ^[^@]+@[^@]+\.[a-z]{2,}$ && "$EMAIL" != *@localhost ]]; then
  _E="${EMAIL//\'/}"
  MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "UPDATE users SET email='$_E' WHERE id=$AID AND (email='' OR email LIKE '%@localhost');" 2>/dev/null || true
  unset _E
fi
. "$(dirname "$(readlink -f "$0")")/php-versions.sh"
PHPV="$PHP_DEFAULT"
# docroot follows the fleet layout convention (/home/<user>/<domain>/public_html
# — the vhost serves exactly this path). An early revision stored the bare
# domain name here, which is what the Domains UI displayed verbatim.
DOC="/home/$AUSER/$D/public_html"
# server nameservers = ns1/ns2.<apex> (same apex rule as dns-glue-check.sh
# and DnsController::panelApex). The usual (cPanel/DirectAdmin/Virtualmin)
# setup is a HOSTNAME like panel.example.com: it gets the site + cert but NO
# zone of its own — its A records belong in example.com's zone, wherever
# that is hosted (a lone panel.example.com zone is never queried). When
# example.com is added here later, DnsController::defaultRows seeds them.
# A bare example.com host keeps today's path: one zone carrying the glue.
APEX="$D"
if [[ "$D" =~ ^[^.]+\.([^.]+\.[^.]+)$ ]]; then APEX="${BASH_REMATCH[1]}"; fi
LABEL="${D%."$APEX"}"
EXIST="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT id FROM domains WHERE domain='$D' LIMIT 1;" || true)"
if [[ -z "$EXIST" ]]; then
  MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "INSERT INTO domains(user_id,domain,docroot,php_version,waf) VALUES($AID,'$D','$DOC','$PHPV',1);" || { echo "domain insert failed"; exit 1; }
  DID="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT id FROM domains WHERE domain='$D' LIMIT 1;")"
  if [[ "$D" == "$APEX" ]]; then
    for rec in "A @ $IP" "A www $IP" "A mail $IP" "A ns1 $IP" "A ns2 $IP"; do
      set -- $rec
      MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "INSERT INTO dns_records(domain_id,type,host,value,priority,ttl) VALUES($DID,'$1','$2','$3',10,3600);" 2>/dev/null || true
    done
    MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "INSERT INTO dns_records(domain_id,type,host,value,priority,ttl) VALUES($DID,'MX','@','mail.$D',10,3600);" 2>/dev/null || true
    MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "INSERT INTO dns_records(domain_id,type,host,value,priority,ttl) VALUES($DID,'TXT','@','v=spf1 a mx ~all',10,3600);" 2>/dev/null || true
    echo "domain row + records for $D"
  else
    echo "domain row for $D (hostname — its DNS lives in the $APEX zone)"
  fi
else
  echo "domain $D already in panel — reusing"
  # converge: early installs stored the bare domain name as docroot — heal to
  # the layout path on re-run (username is DB-sourced, so re-validate it
  # before interpolating into SQL; $D is regex-validated above).
  if [[ "$AUSER" =~ ^[a-z][a-z0-9_]{2,31}$ ]]; then
    MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "UPDATE domains SET docroot='/home/$AUSER/$D/public_html' WHERE domain='$D' AND docroot!='/home/$AUSER/$D/public_html';" 2>/dev/null || true
  else
    echo "warning: admin username '$AUSER' fails validation — docroot left as-is"
  fi
fi
# record the panel's own hostname: the setup checklist excludes it from the
# "add your first domain" step (it isn't a hosted site), and the glue check
# derives the apex from it. Set-once: a changed panel hostname is a migration,
# not something to silently overwrite.
MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "INSERT IGNORE INTO settings(name,value) VALUES('panel_domain','$D');" 2>/dev/null || true
"$HERE/create-account.sh" --repair "$AUSER" "$PHPV" 2>&1 | tail -1 || true
if [[ "$D" == "$APEX" ]]; then
  "$HERE/dns-add-zone.sh" "$D" "ns1.$APEX" "ns2.$APEX" 2>&1 | tail -1 || true
fi
"$HERE/vhost-add.sh" "$D" "$AUSER" "$PHPV" 2>&1 | tail -1 || true
"$HERE/mail-autoconfig.sh" "$D" "$AUSER" >/dev/null 2>&1 || true
# LE only when DNS already points here — otherwise print registrar homework.
# Interactive shells (a human at the keyboard) get retry/continue/abort;
# pipes/bootstrap run straight through to the homework.
# NOTE: callers must NOT redirect this script's stdout to a log file — the
# r/c/a prompt would be hidden and the install looks frozen. As a backstop,
# the prompt below is also duplicated to /dev/tty when one exists.
tty_echo() { echo "$@"; { echo "$@" > /dev/tty; } 2>/dev/null || true; }
dns_ok() {
  local r
  r="$(dig +short +time=2 +tries=2 "$D" @8.8.8.8 2>/dev/null | head -1 || true)"
  [[ "$r" == "$IP" ]]
}
RESOLVES="$(dig +short +time=2 +tries=2 "$D" @8.8.8.8 2>/dev/null | head -1 || true)"
if [[ "$RESOLVES" != "$IP" && -t 0 ]]; then
  while true; do
    tty_echo "panel domain $D resolves to '${RESOLVES:-nothing}' (need $IP)."
    tty_echo "Set the A record, wait for propagation, then choose:"
    tty_echo "  [r] re-check now   [c] continue without SSL (issue later)   [a] abort install"
    { printf '> ' > /dev/tty; } 2>/dev/null || true
    read -r ans < /dev/tty 2>/dev/null || read -r -p "> " ans || { echo "continuing without SSL"; break; }
    case "$ans" in
      [Rr]*) RESOLVES="$(dig +short +time=2 +tries=2 "$D" @8.8.8.8 2>/dev/null | head -1 || true)"
             [[ "$RESOLVES" == "$IP" ]] && break || continue;;
      [Cc]*) echo "continuing without SSL"; break;;
      [Aa]*) echo "aborted by operator (panel installed, domain left unencrypted)"; exit 1;;
      *) echo "answer r, c or a";;
    esac
  done
fi
if [[ "$RESOLVES" == "$IP" ]]; then
  "$HERE/ssl-issue.sh" "$D" "$EMAIL" services 2>&1 | tail -2 || echo "LE issue failed — retry from Domains once DNS settles"
  MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "UPDATE domains SET ssl_enabled=1 WHERE domain='$D';" 2>/dev/null || true
  echo "panel domain $D live with LE (services cert applied)"
else
  cat <<EOF
panel domain $D added WITHOUT ssl — it resolves to '${RESOLVES:-nothing}', not this box ($IP).
At your registrar: A $D (and www) -> $IP, then Domains -> Issue SSL, or re-run: panel-domain.sh $D $EMAIL
EOF
fi
if [[ "$D" != "$APEX" ]]; then
  APEX_HERE="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT id FROM domains WHERE domain='$APEX' LIMIT 1;" 2>/dev/null || true)"
  WHERE="wherever $APEX's DNS is managed today"
  [[ -n "$APEX_HERE" ]] && WHERE="hosted here: DNS page -> $APEX"
  cat <<EOF

DNS for the panel hostname $D: it has no zone on this server; these
records belong in the $APEX zone ($WHERE):
  $LABEL        A  $IP
  www.$LABEL    A  $IP
  mail.$LABEL   A  $IP   (mail server name; ask your host for reverse DNS: $IP -> mail.$D)
To use this server as nameserver for hosted domains (ns1/ns2.$APEX):
  ns1          A  $IP
  ns2          A  $IP
  plus host/glue records ns1.$APEX + ns2.$APEX -> $IP at $APEX's registrar.
Or add $APEX as a domain in the panel later: these records are seeded automatically.
EOF
fi
