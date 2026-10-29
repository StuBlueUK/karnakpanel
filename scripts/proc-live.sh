#!/usr/bin/env bash
# proc-live.sh snap | kill <pid> <starttime> | killuser <user> — the live
# Abuse board. Read-only apart from the two kill modes; the 5-min
# user-abuse.sh (abuse.json + alert state) stays the alerting path.
#   snap      JSON: host load/mem, per-user totals and the top processes with
#             CPU measured over a 1 s window (two /proc reads — ps pcpu is a
#             lifetime average, so a worker that just started spinning reads
#             ~0), the alert state user-abuse.sh keeps, and customers' running
#             MariaDB queries (mapped to the owner through mysql_dbs).
#   kill      TERM, then KILL after 3 s, ONE process of a panel customer. The
#             start time (stat field 22) must still match what the page saw,
#             so a reused PID is never hit.
#   killuser  the same for every process of one customer (fork storms).
# Kills refuse anything not owned by a uid >= 1000 that is in the users
# table (root, nobody = the panel, mysql, daemons are never touched).
set -euo pipefail
LOG=/var/log/karnakpanel/panel.log
STATE=/var/lib/skypanel/abuse
q(){ MYSQL_PWD="$(cat /root/.skypanel-dbpass)" mysql -uskypanel skypanel -N -e "$1"; }
customer(){ # <user>: a panel account backed by a real uid >= 1000
  [[ "$1" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || return 1
  local id; id="$(id -u "$1" 2>/dev/null)" || return 1
  (( id >= 1000 && id != 65534 )) || return 1
  [[ "$(q "SELECT COUNT(*) FROM users WHERE username='$1'" 2>/dev/null)" == 1 ]]
}
log(){ echo "$(date -u +%FT%T+00:00) proc-live: $*" >> "$LOG" 2>/dev/null || true; }
M="${1:-}"
case "$M" in
  kill)
    P="${2:-}"; S="${3:-}"
    [[ "$P" =~ ^[0-9]{1,9}$ && "$S" =~ ^[0-9]{1,20}$ ]] || { echo "usage: $0 kill <pid> <starttime>"; exit 1; }
    stat_of(){ local s; s="$(cat "/proc/$1/stat" 2>/dev/null)" || return 1; s="${s##*) }"; awk '{print $20}' <<<"$s"; }
    [[ "$(stat_of "$P" || true)" == "$S" ]] || { echo "process $P already ended (or the PID was reused) — nothing killed"; exit 1; }
    U="$(stat -c %U "/proc/$P" 2>/dev/null)" || { echo "process $P already ended"; exit 1; }
    customer "$U" || { echo "refused: process $P belongs to $U, not a customer account"; exit 1; }
    kill -TERM "$P" 2>/dev/null || true
    for _ in 1 2 3 4 5 6; do [[ "$(stat_of "$P" || true)" == "$S" ]] || break; sleep 0.5; done
    HOW=stopped
    if [[ "$(stat_of "$P" || true)" == "$S" ]]; then kill -KILL "$P" 2>/dev/null || true; HOW="force-killed"; fi
    log "kill $P ($U) $HOW"
    echo "process $P ($U) $HOW"; exit 0 ;;
  killuser)
    U="${2:-}"
    customer "$U" || { echo "refused: $U is not a customer account"; exit 1; }
    ID="$(id -u "$U")"
    N="$(pgrep -u "$ID" -c || true)"
    (( ${N:-0} > 0 )) || { echo "$U has no running processes"; exit 0; }
    pkill -TERM -u "$ID" || true; sleep 3; pkill -KILL -u "$ID" || true
    log "killuser $U ($N processes)"
    echo "stopped $N process$( (( N == 1 )) || echo es) of $U"; exit 0 ;;
  snap) ;;
  *) echo "usage: $0 snap | kill <pid> <starttime> | killuser <user>"; exit 1 ;;
esac
CUST="$(q "SELECT username FROM users" 2>/dev/null | grep -E '^[a-z][a-z0-9_]{2,31}$' || true)"
MYQ="$(mysql -N -B -e "SELECT p.ID, m.username, p.USER, IFNULL(p.DB,''), p.TIME, IFNULL(p.STATE,''), REPLACE(REPLACE(LEFT(IFNULL(p.INFO,''),300),'\t',' '),'\n',' ')
  FROM information_schema.PROCESSLIST p JOIN (SELECT DISTINCT d.db_user, u.username FROM skypanel.mysql_dbs d JOIN skypanel.users u ON u.id=d.user_id) m ON m.db_user=p.USER
  WHERE p.COMMAND NOT IN ('Sleep','Daemon') ORDER BY p.TIME DESC LIMIT 50" 2>/dev/null || true)"
ALERTS="$( [[ -d "$STATE" ]] && for f in "$STATE"/*; do [[ -f "$f" ]] && printf '%s %s\n' "$(basename "$f")" "$(head -c 10 "$f")"; done || true)"
CUST="$CUST" MYQ="$MYQ" ALERTS="$ALERTS" exec python3 -I - <<'PYEOF'
import json, os, pwd, time
HZ = os.sysconf('SC_CLK_TCK'); PAGE = os.sysconf('SC_PAGE_SIZE')
# the sampler itself and its callers up to sudo (bash, sudo) are not load;
# above sudo is the panel's lsphp/litespeed, which must stay visible
me, skip = os.getpid(), set()
p = me
for _ in range(8):
    skip.add(p)
    try: s = open('/proc/%d/stat' % p).read()
    except OSError: break
    if s[s.index('(') + 1:s.rindex(')')] == 'sudo': break
    try: p = int(s.rsplit(')', 1)[1].split()[1])
    except (ValueError, IndexError): break
    if p <= 1: break
def sample():
    out = {}
    for d in os.listdir('/proc'):
        if not d.isdigit() or int(d) in skip: continue
        try:
            s = open('/proc/%s/stat' % d).read()
            comm = s[s.index('(') + 1:s.rindex(')')]
            f = s.rsplit(')', 1)[1].split()
            out[int(d)] = (int(f[11]) + int(f[12]), f, comm)
        except (OSError, ValueError, IndexError):
            pass
    return out
a = sample(); t0 = time.monotonic(); time.sleep(1.0); b = sample(); dt = time.monotonic() - t0
up = float(open('/proc/uptime').read().split()[0])
mi = {}
for l in open('/proc/meminfo'):
    k, v = l.split(':', 1); mi[k] = int(v.split()[0])
memt = mi.get('MemTotal', 1)
names, procs, users = {}, [], {}
for pid, (ticks, f, comm) in b.items():
    try:
        st = os.stat('/proc/%d' % pid)
        cmd = open('/proc/%d/cmdline' % pid, 'rb').read(400).replace(b'\0', b' ').decode('utf-8', 'replace').strip()
        rss = int(open('/proc/%d/statm' % pid).read().split()[1]) * PAGE // 1024
    except (OSError, ValueError, IndexError):
        continue
    if not cmd: continue  # kernel thread
    uid = st.st_uid
    if uid not in names:
        try: names[uid] = pwd.getpwuid(uid).pw_name
        except KeyError: names[uid] = str(uid)
    prev = a.get(pid)
    start = int(f[19])
    if prev and prev[1][19] == f[19]: d = ticks - prev[0]
    else: d = ticks if (up - start / HZ) <= dt + 0.2 else 0  # born inside the window
    cpu = round(d * 100.0 / (HZ * dt), 1)
    u = users.setdefault(names[uid], {'cpu': 0.0, 'rss': 0, 'procs': 0, 'uid': uid})
    u['cpu'] += cpu; u['rss'] += rss; u['procs'] += 1
    procs.append({'pid': pid, 'user': names[uid], 'uid': uid, 'cpu': cpu, 'rss': rss,
                  'state': f[0], 'age': max(0, int(up - start / HZ)), 'start': start,
                  'threads': int(f[17]), 'comm': comm[:32], 'args': cmd[:300]})
for u in users.values(): u['cpu'] = round(u['cpu'], 1)
cust = set(os.environ.get('CUST', '').split())
# every customer process plus the busiest/biggest of the rest
procs.sort(key=lambda x: (-x['cpu'], -x['rss']))
keep = [x for x in procs if x['user'] in cust][:300]
rest = [x for x in procs if x['user'] not in cust]
keep += rest[:40] + sorted(rest[40:], key=lambda x: -x['rss'])[:20]
mysql = []
for l in os.environ.get('MYQ', '').splitlines():
    c = l.split('\t')
    if len(c) >= 7 and c[0].isdigit():
        mysql.append({'id': int(c[0]), 'user': c[1], 'dbuser': c[2], 'db': c[3], 'time': int(c[4] or 0), 'state': c[5], 'query': c[6]})
alerts = dict(l.split(' ', 1) for l in os.environ.get('ALERTS', '').splitlines() if ' ' in l)
la = open('/proc/loadavg').read().split()
print(json.dumps({
    'host': {'load': [float(x) for x in la[:3]], 'cpus': os.cpu_count() or 1, 'uptime': int(up),
             'mem_total': memt, 'mem_avail': mi.get('MemAvailable', 0),
             'swap_total': mi.get('SwapTotal', 0), 'swap_free': mi.get('SwapFree', 0),
             'procs': len(procs), 'busy': round(sum(x['cpu'] for x in procs), 1)},
    'customers': sorted(cust), 'users': users, 'procs': keep, 'mysql': mysql, 'alerts': alerts,
}))
PYEOF
