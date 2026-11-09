#!/usr/bin/env bash
# fail2ban-bans.sh list | unban <ip> | allow-list | allow-add <ip|cidr> [label]
#   | allow-del <ip|cidr> — Firewall page "Recent bans" + "Never ban" cards.
#   list   JSON: every ban fail2ban recorded in its db (last 24 h — its own
#          purge age) with the log lines that triggered it, flagged active
#          from the live jails (the db keeps expired bans too). Read-only.
#   unban  lifts the IP from EVERY jail (false positive: a user locked out of
#          mail is usually locked out of FTP too — the stock ufw action
#          firewalls all ports). Bans fail2ban didn't make (Block IP) are
#          UFW rules and stay; delete those in the rules table.
#   allow-*  the never-ban list (fail2ban ignoreip): source of truth is
#          $LIST ("net<TAB>label"), rendered into jail.d/ so it survives
#          restarts, and pushed into every running jail at once (no reload).
#          allow-add also unbans the address.
set -euo pipefail
LIST=/etc/fail2ban/karnakpanel-allow.list
CONF=/etc/fail2ban/jail.d/karnakpanel-allow.local
jails(){ timeout 5 fail2ban-client status 2>/dev/null | sed -n 's/.*Jail list:[[:space:]]*//p' | tr -d ' ' | tr ',' '\n' | grep -E '^[A-Za-z0-9_.-]+$' || true; }
# normalise an IP/CIDR (python's ipaddress: 10.0.0.5/24 -> 10.0.0.0/24, single hosts bare)
net_ok(){
  [[ "$1" =~ ^[0-9a-fA-F:.]{2,45}(/[0-9]{1,3})?$ ]] || return 1
  python3 -c 'import ipaddress,sys
n=ipaddress.ip_network(sys.argv[1],strict=False)
if n.prefixlen < (16 if n.version==4 else 32): sys.exit(1)
print(n.network_address if n.num_addresses==1 else n)' "$1" 2>/dev/null
}
render(){
  local tmp; tmp="$(mktemp "$CONF.XXXX")"
  { echo "# KarnakPanel never-ban list — generated from $LIST by the Firewall page; edit there"
    echo "[DEFAULT]"
    printf 'ignoreip = 127.0.0.1/8 ::1'; [[ -s "$LIST" ]] && cut -f1 "$LIST" | tr '\n' ' ' | sed 's/^/ /; s/ *$//'; echo
  } > "$tmp"
  chmod 644 "$tmp"; mv -f "$tmp" "$CONF"
}
M0="${1:-}"
case "$M0" in
  allow-list)
    [[ -f "$LIST" ]] && cat "$LIST"; exit 0 ;;
  allow-add|allow-del)
    N="$(net_ok "${2:-}")" || { echo "bad address (IP, or a network no wider than /16 v4, /32 v6)"; exit 1; }
    LB="${3:-}"
    [[ "$LB" =~ ^[A-Za-z0-9\ ._@-]{0,40}$ ]] || { echo "label: letters, digits, space . _ @ - (40 max)"; exit 1; }
    install -d -m 755 /etc/fail2ban/jail.d; touch "$LIST"; chmod 644 "$LIST"
    tmp="$(mktemp "$LIST.XXXX")"; awk -F'\t' -v n="$N" '$1!=n' "$LIST" > "$tmp"
    [[ "$M0" == allow-add ]] && printf '%s\t%s\n' "$N" "$LB" >> "$tmp"
    chmod 644 "$tmp"; mv -f "$tmp" "$LIST"; render
    for j in $(jails); do
      if [[ "$M0" == allow-add ]]; then timeout 5 fail2ban-client set "$j" addignoreip "$N" >/dev/null 2>&1 || true
      else timeout 5 fail2ban-client set "$j" delignoreip "$N" >/dev/null 2>&1 || true; fi
    done
    if [[ "$M0" == allow-add ]]; then
      [[ "$N" == */* ]] || timeout 10 fail2ban-client unban "$N" >/dev/null 2>&1 || true
      echo "$N will never be banned"
    else echo "$N removed from the never-ban list"; fi
    exit 0 ;;
esac

command -v fail2ban-client >/dev/null || { echo "fail2ban not installed"; exit 1; }
M="${1:-}"
if [[ "$M" == unban ]]; then
  IP="${2:-}"
  [[ "$IP" =~ ^[0-9a-fA-F:.]{2,45}$ ]] && python3 -c 'import ipaddress,sys; ipaddress.ip_address(sys.argv[1])' "$IP" 2>/dev/null \
    || { echo "bad IP"; exit 1; }
  N="$(timeout 10 fail2ban-client unban "$IP" 2>&1)" || { echo "unban failed: $N"; exit 1; }
  [[ "$N" =~ ^[0-9]+$ ]] || { echo "unban failed: $N"; exit 1; }
  (( N > 0 )) && echo "unbanned $IP" || echo "$IP was not banned"
  exit 0
fi
[[ "$M" == list ]] || { echo "usage: $0 list | unban <ip> | allow-list | allow-add <net> [label] | allow-del <net>"; exit 1; }
STATUS="$(timeout 5 fail2ban-client status 2>&1)" || { echo "$STATUS"; exit 1; }
JAILS="$(sed -n 's/.*Jail list:[[:space:]]*//p' <<<"$STATUS" | tr -d ' ' | tr ',' ' ')"
# live bans as "jail ip" lines — the authority on what is still blocked
ACTIVE="$(for j in $JAILS; do
  [[ "$j" =~ ^[A-Za-z0-9_.-]+$ ]] || continue
  for ip in $(timeout 5 fail2ban-client get "$j" banip 2>/dev/null || true); do printf '%s %s\n' "$j" "$ip"; done
done)"
DB="$(timeout 5 fail2ban-client get dbfile 2>/dev/null | sed -n 's/^`- //p')"
JAILS="$JAILS" ACTIVE="$ACTIVE" python3 - "${DB:-/var/lib/fail2ban/fail2ban.sqlite3}" <<'PYEOF'
import json, os, sqlite3, sys, time
active = set(tuple(l.split(' ', 1)) for l in os.environ.get('ACTIVE', '').splitlines() if ' ' in l)
rows, seen = [], set()
try:
    c = sqlite3.connect('file:%s?mode=ro' % sys.argv[1], uri=True, timeout=3)
    q = c.execute("SELECT jail, ip, timeofban, bantime, bancount, data FROM bans "
                  "WHERE timeofban > ? ORDER BY timeofban DESC LIMIT 300", (int(time.time()) - 86400,))
    for jail, ip, tob, bt, cnt, data in q:
        try: d = json.loads(data or '{}')
        except ValueError: d = {}
        m = [str(x)[:400] for x in (d.get('matches') or [])][-10:]
        rows.append({'jail': jail, 'ip': ip, 'at': tob, 'for': bt, 'count': cnt,
                     'fails': d.get('failures') or len(m), 'matches': m,
                     'active': (jail, ip) in active and (jail, ip) not in seen})
        seen.add((jail, ip))
except sqlite3.Error as e:
    print('fail2ban db unreadable: %s' % e, file=sys.stderr)
# a live ban the db no longer holds (restored after a restart, purged)
for jail, ip in sorted(active - seen):
    rows.append({'jail': jail, 'ip': ip, 'at': 0, 'for': 0, 'count': 1, 'fails': 0, 'matches': [], 'active': True})
print(json.dumps({'jails': os.environ.get('JAILS', '').split(), 'bans': rows}))
PYEOF
