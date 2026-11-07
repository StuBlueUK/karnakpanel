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
#   killuser  the same for every process of one customer (fork storms). Their
#             daemons are stopped first (Restart=always would respawn them;
#             autostart stays — they come back at boot or on Start).
#   snap also carries the cron/daemon side: each account's karnakcron- and
#             karnakapp- slice (and every daemon unit) sampled over the same
#             1 s window against its caps, the daemon states (app-status.sh),
#             abuse-events.sh lines of the last 24 h, and spool crontabs of
#             users missing from /etc/cron.allow (made outside the panel).
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
    "$(dirname "$(readlink -f "$0")")/app-daemon.sh" halt "$U" >/dev/null 2>&1 || true
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
DAEMONS="$("$(dirname "$(readlink -f "$0")")/app-status.sh" show --all 2>/dev/null || true)"
EV=/var/lib/skypanel/abuse-events.log
# nosemgrep: bash.lang.correctness.unquoted-expansion.unquoted-command-substitution-in-command -- arithmetic context, no word splitting
EVENTS="$( [[ -f "$EV" ]] && tail -n 3000 "$EV" | awk -F'\t' -v t="$(( $(date +%s) - 86400 ))" '$1 >= t' | tail -n 1000 || true)"
OUTSIDE=""
if [[ -f /etc/cron.allow ]]; then
  for f in /var/spool/cron/crontabs/*; do
    [[ -f "$f" ]] || continue
    grep -qxF -- "${f##*/}" /etc/cron.allow || OUTSIDE+="${f##*/} "
  done
fi
CUST="$CUST" MYQ="$MYQ" ALERTS="$ALERTS" DAEMONS="$DAEMONS" EVENTS="$EVENTS" OUTSIDE="$OUTSIDE" exec python3 -I - <<'PYEOF'
import glob, json, os, pwd, re, time
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
# cron + daemon cgroups: account slices and each daemon unit
CGP = ['/sys/fs/cgroup/karnakcron.slice/karnakcron-*.slice', '/sys/fs/cgroup/karnakapp.slice/karnakapp-*.slice',
       '/sys/fs/cgroup/karnakapp.slice/karnakapp-*.slice/karnak-*.service']
def cg_sample():
    out = {}
    for pat in CGP:
        for d in glob.glob(pat):
            try:
                for l in open(d + '/cpu.stat'):
                    if l.startswith('usage_usec '): out[d] = int(l.split()[1]); break
            except (OSError, ValueError): pass
    return out
def rd(path):
    try: return open(path).read().strip()
    except OSError: return ''
ca = cg_sample()
a = sample(); t0 = time.monotonic(); time.sleep(1.0); b = sample(); dt = time.monotonic() - t0
cb = cg_sample()
groups = []
for d, use in cb.items():
    base = os.path.basename(d)
    m = re.match(r'^karnak(cron|app)-([a-z][a-z0-9_]{2,31})\.slice$', base)
    n = re.match(r'^karnak-([a-z][a-z0-9_]{2,31})-([a-z0-9_-]{1,32})\.service$', base)
    if not (m or n): continue
    cpu = round(max(0, use - ca.get(d, use)) * 100.0 / (dt * 1e6), 1)
    cm = rd(d + '/cpu.max').split(); cap = None
    if len(cm) == 2 and cm[0].isdigit() and cm[1].isdigit() and int(cm[1]) > 0: cap = round(int(cm[0]) * 100.0 / int(cm[1]), 1)
    mx = rd(d + '/memory.max'); cur = rd(d + '/memory.current'); pc = rd(d + '/pids.current')
    g = {'kind': ('cron' if m.group(1) == 'cron' else 'app') if m else 'unit', 'user': (m or n).group(2) if m else n.group(1),
         'name': n.group(2) if n else '', 'cpu': cpu, 'cpu_cap': cap,
         'mem': int(cur) if cur.isdigit() else 0, 'mem_max': int(mx) if mx.isdigit() else None,
         'tasks': int(pc) if pc.isdigit() else 0}
    if m and m.group(1) == 'cron':
        try: g['runs'] = sum(1 for x in os.listdir(d) if x.endswith('.scope'))
        except OSError: g['runs'] = 0
    groups.append(g)
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
def kind_of(pid):
    c = rd('/proc/%d/cgroup' % pid)
    if '/karnakcron.slice/' in c: return 'cron'
    if '/karnakapp.slice/' in c: return 'daemon'
    if '/karnakfpm' in c: return 'php'
    return ''
for x in procs: x['kind'] = kind_of(x['pid']) if x['user'] in cust else ''
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
daemons = []
for l in os.environ.get('DAEMONS', '').splitlines():
    c = l.split('\t')
    if len(c) >= 10:
        daemons.append({'user': c[0], 'name': c[1], 'state': c[2], 'sub': c[3], 'enabled': c[4] == 'enabled', 'result': c[5],
                        'restarts': int(c[6]) if c[6].isdigit() else 0, 'since': int(c[9]) if c[9].isdigit() else 0})
events = []
for l in os.environ.get('EVENTS', '').splitlines():
    c = l.split('\t')
    if len(c) >= 3 and c[0].isdigit(): events.append({'ts': int(c[0]), 'user': c[1], 'kind': c[2], 'detail': c[3] if len(c) > 3 else ''})
la = open('/proc/loadavg').read().split()
print(json.dumps({
    'host': {'load': [float(x) for x in la[:3]], 'cpus': os.cpu_count() or 1, 'uptime': int(up),
             'mem_total': memt, 'mem_avail': mi.get('MemAvailable', 0),
             'swap_total': mi.get('SwapTotal', 0), 'swap_free': mi.get('SwapFree', 0),
             'procs': len(procs), 'busy': round(sum(x['cpu'] for x in procs), 1)},
    'customers': sorted(cust), 'users': users, 'procs': keep, 'mysql': mysql, 'alerts': alerts,
    'groups': groups, 'daemons': daemons, 'events': events, 'outside': os.environ.get('OUTSIDE', '').split(), 'now': int(time.time()),
}))
PYEOF
