#!/usr/bin/env bash
# egress-watch.sh [--seed] — webshell canary: sample established outbound TCP,
# attribute to system users, alert ONCE per new (user, destination).
# Sampling (not tracing): short-lived connections between ticks are missed —
# this catches beacons and tunnels, not single curls. Loopback, inbound
# server ports and system users (uid<1000) are ignored; hosted PHP runs as
# the account user, so attribution works. First run: --seed baselines
# silently (else day one is a noise storm of wordpress.org etc.).
# State: /var/lib/karnakpanel/egress-seen ("user|ip:port" per line).
set -euo pipefail
PASS="$(cat /root/.skypanel-dbpass 2>/dev/null || true)"
STATE_DIR="/var/lib/karnakpanel"
SEEN="$STATE_DIR/egress-seen"
mkdir -p "$STATE_DIR"; touch "$SEEN"
SEED=0; [[ "${1:-}" == "--seed" ]] && SEED=1
OWN="$(hostname -I 2>/dev/null || true)"
# listening ports: ss shows BOTH directions — without this, every inbound
# visitor looks like "outbound to <visitor-ip>:<their-ephemeral-port>".
# True egress has an ephemeral LOCAL port; anything local-bound is inbound.
LISTEN="$(ss -tlnH 2>/dev/null | awk '{print $(NF-1)}' | grep -oE ':[0-9]+$' | tr -d ':' | sort -u || true)"
notify() { # notify <uid> <title>
  local uid="$1" msg="$2"
  [[ -n "$PASS" && "$uid" =~ ^([0-9]+|NULL)$ ]] || return 0
  # title carries a tenant's cmdline: hex-encoded so it can't touch the SQL
  local h; h="$(printf '%s' "${msg:0:250}" | od -An -v -tx1 | tr -d ' \n')"
  [[ "$h" =~ ^[0-9a-f]+$ ]] || return 0
  msg="_utf8mb4 X'$h'"
  MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "INSERT INTO notifications(user_id,type,title) SELECT $uid,'egress',$msg FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM notifications WHERE user_id<=>$uid AND type='egress' AND title=$msg AND created_at > (NOW() - INTERVAL 24 HOUR));" >>/var/log/karnakpanel/panel.log 2>&1 || echo "$(date -Is) egress-watch: notify insert failed" >>/var/log/karnakpanel/panel.log
}
NEW=0
while IFS= read -r line; do
  # peer = second-to-last field: ss column layout varies (with/without
  # Netid+State the peer shifts between $4/$6 — NF-1 is stable either way)
  remote="$(echo "$line" | awk '{print $(NF-1)}')"
  local="$(echo "$line" | awk '{print $(NF-2)}')"
  lport="${local##*:}"
  echo "$LISTEN" | grep -qxF "$lport" 2>/dev/null && continue # inbound: we serve this port
  rip="${remote%:*}"; rip="${rip#[}"; rip="${rip%]}"
  [[ "$rip" =~ ^(127\.|::1|10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.) ]] && continue
  echo " $OWN " | grep -qF " $rip " && continue
  pid="$(echo "$line" | grep -oP 'pid=\K[0-9]+' | head -1 || true)"
  [[ -n "$pid" && -d "/proc/$pid" ]] || continue
  uid="$(awk '/^Uid:/{print $2}' "/proc/$pid/status" 2>/dev/null || true)"
  [[ "$uid" =~ ^[0-9]+$ ]] && (( uid >= 1000 )) || continue
  user="$(getent passwd "$uid" | cut -d: -f1 || true)"
  [[ "$user" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || continue
  key="$user|$remote"
  grep -qxF "$key" "$SEEN" 2>/dev/null && continue
  echo "$key" >> "$SEEN"
  if (( SEED == 0 )); then
    uidn="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT id FROM users WHERE username='$user' LIMIT 1;" 2>/dev/null || true)"
    proc="$(tr '\0' ' ' < "/proc/$pid/cmdline" 2>/dev/null | cut -c1-120 || true)"
    # cmdline can contain quotes (php -r '...') — strip them, the INSERT below
    # is a plain string and a stray quote would silently void the alert
    title="New outbound connection: $user -> $remote ($proc)"
    title="${title//\'/}"
    if [[ -n "$uidn" ]]; then
      notify "$uidn" "$title"
    else
      notify "NULL" "$title"
    fi
    echo "new: $key ($proc)"
    NEW=$((NEW+1))
  fi
done < <(ss -tnpH state established 2>/dev/null || true)
(( SEED == 1 )) && echo "baseline seeded ($(wc -l < "$SEEN") destinations)"
(( SEED == 0 )) && echo "$NEW new destination(s)"
