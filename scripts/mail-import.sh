#!/usr/bin/env bash
# mail-import.sh — pull mail from a remote IMAP account into a local mailbox.
# One-way (dsync -1 -R): the source is never written, local-only mail is
# kept, a re-run skips what is already here. Spec: docs/mail-import-spec.md.
#   start <job> <address> <host> <port> <remoteuser> <password>
#   cancel <job> | stop-addr <address> | supported
# start checks the login synchronously, then DETACHES; progress lands in
# mail_imports. The password arrives on stdin (Secret, never argv) and lives
# only in a 600 file under /run for the job's life — Dovecot reads it with
# `imapc_password = <file` (verbatim, no % expansion).
# Dovecot 2.3 only: 2.4 (Debian 13) changed the config format — untested.
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/secret-args.sh"; secret_argv "$@"; set -- "${SECRET_ARGV[@]}"  # @stdin secrets
MODE="${1:?}"; shift
RUN=/run/skypanel-mail-import
LOG=/var/log/karnakpanel/mail-import.log
SLOTS=3                      # imports running at once, server-wide
LIMIT_SECS=21600             # hard stop; a re-run continues where it ended
ADDR_RE='^[a-z0-9._-]+@[a-z0-9.-]+\.[a-z]{2,}$'
mkdir -p "$RUN" /var/log/karnakpanel; chmod 700 "$RUN"

supported() { [[ "$(dovecot --version 2>/dev/null)" =~ ^2\.3\. ]]; }

# Stop one job. The pid file is ours (root-only dir) but the pid could be
# reused after a crash: only signal a process that is our own doveadm run.
stop_job() {  # <job> <reason: cancel|gone>
  local f="$RUN/$1.pid" pid a i
  [[ -f "$f" && ! -L "$f" ]] || return 0
  read -r pid a < "$f" || return 0
  [[ "$pid" =~ ^[0-9]+$ ]] || return 0
  tr '\0' ' ' < "/proc/$pid/cmdline" 2>/dev/null | grep -qF "doveadm -c $RUN/j." || return 0
  echo "$2" > "$RUN/$1.stop"
  kill -TERM "$pid" 2>/dev/null || true
  for i in 1 2 3 4 5 6 7 8 9 10; do kill -0 "$pid" 2>/dev/null || return 0; sleep 1; done
  kill -KILL "$pid" 2>/dev/null || true
}

# Remote end must be a public address: no loopback, private, CGNAT,
# link-local, ULA, multicast or reserved. Sets WHY on refusal.
public_ip() {
  local ip="${1,,}"
  if [[ "$ip" == *:* ]]; then
    case "$ip" in ::ffff:*|64:ff9b:*|2001:db8:*|2001:0db8:*) return 1 ;; esac
    [[ "$ip" =~ ^[23][0-9a-f]{3}: ]]   # 2000::/3 global unicast only
    return
  fi
  [[ "$ip" =~ ^([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})$ ]] || return 1
  local a=${BASH_REMATCH[1]} b=${BASH_REMATCH[2]} c=${BASH_REMATCH[3]}
  (( a == 0 || a == 10 || a == 127 || a >= 224 )) && return 1
  (( a == 100 && b >= 64 && b <= 127 )) && return 1
  (( a == 169 && b == 254 )) && return 1
  (( a == 172 && b >= 16 && b <= 31 )) && return 1
  (( a == 192 && b == 168 )) && return 1
  (( a == 192 && b == 0 && c == 0 )) && return 1
  (( a == 198 && (b == 18 || b == 19) )) && return 1
  return 0
}
public_host() {
  local ips ip
  ips="$(getent ahosts "$1" 2>/dev/null | awk '{print $1}' | sort -u || true)"
  [[ -n "$ips" ]] || { WHY="$1 not found in DNS"; return 1; }
  for ip in $ips; do public_ip "$ip" || { WHY="$1 resolves to a private or reserved address ($ip)"; return 1; }; done
}

case "$MODE" in
  supported)
    supported && { echo "supported"; exit 0; }
    echo "mailbox import needs Dovecot 2.3 (found: $(dovecot --version 2>/dev/null || echo none))"; exit 1 ;;
  cancel)
    JOB="${1:?}"; [[ "$JOB" =~ ^[a-f0-9]{16}$ ]] || { echo "bad job"; exit 1; }
    stop_job "$JOB" cancel; echo "import stopped"; exit 0 ;;
  stop-addr)
    A="${1:?}"; [[ "$A" =~ $ADDR_RE ]] || { echo "bad address: $A"; exit 1; }
    for f in "$RUN"/*.pid; do
      [[ -f "$f" ]] || continue
      read -r _ a < "$f" || continue
      [[ "$a" == "$A" ]] && stop_job "$(basename "$f" .pid)" gone
    done
    exit 0 ;;
  start) ;;
  *) echo "usage: mail-import.sh start|cancel|stop-addr|supported"; exit 1 ;;
esac

JOB="${1:?}"; A="${2:?}"; HOST="${3:?}"; PORT="${4:?}"; RU="${5:?}"; PW="${6:?}"
[[ "$JOB" =~ ^[a-f0-9]{16}$ ]] || { echo "bad job"; exit 1; }
[[ "$A" =~ $ADDR_RE ]] || { echo "bad address: $A"; exit 1; }
# a DNS name (an IP literal could never match the server's certificate)
[[ ${#HOST} -le 253 && "$HOST" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$ ]] || { echo "bad host: $HOST"; exit 1; }
[[ "$PORT" == 993 || "$PORT" == 143 ]] || { echo "port must be 993 (TLS) or 143 (STARTTLS)"; exit 1; }
# no % (Dovecot expands it in imapc_user), quotes or spaces
[[ "$RU" =~ ^[A-Za-z0-9._+=@-]{1,254}$ ]] || { echo "bad remote username"; exit 1; }
[[ ${#PW} -le 256 && "$PW" != *$'\n'* && "$PW" != *$'\r'* ]] || { echo "bad password"; exit 1; }
DBP="$(cat /root/.skypanel-dbpass 2>/dev/null || true)"
prog() {  # <stage> <copied> <total> <detail> — detail may carry remote text
  local d="${4//\'/}"; d="${d//\\/}"
  MYSQL_PWD="$DBP" mysql -uskypanel skypanel -e "UPDATE mail_imports SET stage='$1', copied=$2, total=$3, detail='${d:0:250}', updated_at=NOW() WHERE job='$JOB'" 2>/dev/null || true
}
fail() { prog failed 0 0 "$1"; echo "$1"; exit "${2:-1}"; }

supported || fail "mailbox import needs Dovecot 2.3 — not available on this server yet"
doveadm user "$A" >/dev/null 2>&1 || fail "no such mailbox: $A"
public_host "$HOST" || fail "refused: $WHY" 3

# one import per mailbox + a server-wide cap. The detached job inherits
# these fds and holds the locks until it ends.
KEY="$(printf '%s' "$A" | sha1sum | cut -c1-16)"
exec {BOXFD}>"$RUN/box-$KEY.lock"
flock -n "$BOXFD" || fail "an import into $A is already running" 4
SLOT=""
for s in $(seq 1 "$SLOTS"); do
  exec {SFD}>"$RUN/slot-$s.lock"
  if flock -n "$SFD"; then SLOT="$s"; break; fi
  exec {SFD}>&-
done
[[ -n "$SLOT" ]] || fail "the server is already running $SLOTS mail imports — try again in a while" 4

T="$(mktemp -d "$RUN/j.XXXXXX")"
trap 'rm -rf "$T"' EXIT
( umask 077; printf '%s' "$PW" > "$T/pw" ); unset PW
SSLMODE=imaps; [[ "$PORT" == 143 ]] && SSLMODE=starttls
# guid-forced: IMAP servers give no message GUIDs, so dsync would match by
# UID alone and re-copy everything once local UIDs diverge (seen on test);
# header-hash GUIDs make every re-run skip what is already here
cat > "$T/c.conf" <<EOF
!include /etc/dovecot/dovecot.conf
imapc_host = $HOST
imapc_port = $PORT
imapc_ssl = $SSLMODE
imapc_ssl_verify = yes
imapc_user = $RU
imapc_password = <$T/pw
imapc_features = rfc822.size fetch-headers guid-forced
ssl_client_ca_dir = /etc/ssl/certs
ssl_client_require_valid_cert = yes
EOF
chmod 600 "$T/c.conf"

why() {  # <stderr file> — one customer-readable line
  if grep -qiE 'AUTHENTICATIONFAILED|Authentication failed' "$1"; then
    echo "login refused by $HOST — check the username and password (Gmail, Yahoo, iCloud, AOL and Zoho need an app password)"
  elif grep -q 'dns_lookup' "$1"; then echo "$HOST not found in DNS"
  elif grep -qi 'certificate' "$1"; then echo "$HOST did not present a valid TLS certificate for that name"
  elif grep -qiE 'Connection refused|timed out|Disconnected' "$1"; then echo "could not talk IMAP to $HOST:$PORT"
  else
    local l; l="$(grep -E 'Error:' "$1" | tail -1)"; l="${l##*Error: }"
    echo "${l:0:200}"
  fi
}
quota_kb() { { doveadm quota get -u "$A" 2>/dev/null || true; } | awk '$3=="STORAGE"{print $4, $5; exit}'; }
count_local() { { doveadm mailbox status -u "$A" messages '*' 2>/dev/null || true; } | awk -F'messages=' '{s+=$2} END{print s+0}'; }

# login + size check now, so a bad password fails on the form. Gmail's
# virtual views (All Mail / Starred / Important) are skipped by the sync,
# so leave them out of the estimate too.
if ! OUT="$(timeout 90 doveadm -c "$T/c.conf" -o mail_location=imapc: mailbox status -u "$A" "messages vsize" '*' 2>"$T/err")"; then
  fail "$(why "$T/err")" 2
fi
read -r TOTAL BYTES < <(printf '%s\n' "$OUT" | awk '{
  if (match($0, / messages=[0-9]+ vsize=[0-9]+$/)) {
    name = substr($0, 1, RSTART - 1); split(substr($0, RSTART + 1), p, /[ =]/)
    if (name ~ /(^|\/)(All Mail|Starred|Important)$/) next
    m += p[2]; v += p[4]
  } } END { print m + 0, v + 0 }')
read -r USED QLIM < <(quota_kb) || true
if [[ "${QLIM:-}" =~ ^[0-9]+$ ]]; then
  # dsync does not enforce Dovecot quota (verified) — this script does
  (( BYTES / 1024 > QLIM )) && fail "the old mailbox holds about $(( BYTES / 1048576 )) MB, more than this mailbox's $(( QLIM / 1024 )) MB quota — raise the quota first" 5
  (( ${USED:-0} >= QLIM )) && fail "this mailbox is already full — raise its quota first" 5
fi

trap - EXIT
(
  trap 'rm -rf "$T" "$RUN/$JOB.pid" "$RUN/$JOB.stop"' EXIT
  exec </dev/null >>"$LOG" 2>&1
  log() { echo "$(date '+%F %T') $JOB $A: $*"; }
  log "start: $RU at $HOST:$PORT (about $TOTAL messages, $(( BYTES / 1048576 )) MB)"
  START="$(count_local)"
  # re-check right before the sync (Dovecot resolves the name again itself)
  if ! public_host "$HOST"; then prog failed 0 "$TOTAL" "refused: $WHY"; log "refused: $WHY"; exit 1; fi
  nice -n 10 ionice -c3 timeout "$LIMIT_SECS" doveadm -c "$T/c.conf" sync -1 -R \
    -x '\All' -x '\Flagged' -x '\Important' -u "$A" imapc: 2>"$T/sync.err" &
  DPID=$!
  echo "$DPID $A" > "$RUN/$JOB.pid"
  prog running 0 "$TOTAL" "copying"
  copied() { local c; c=$(( $(count_local) - START )); (( c < 0 )) && c=0; echo "$c"; }
  while kill -0 "$DPID" 2>/dev/null; do
    sleep 2
    # mailbox deleted mid-import (SQL userdb: 67 = no such user)
    rc=0; doveadm user "$A" >/dev/null 2>&1 || rc=$?
    if [[ "$rc" -eq 67 ]]; then echo gone > "$RUN/$JOB.stop"; kill -TERM "$DPID" 2>/dev/null || true; break; fi
    USED=""; QLIM=""; read -r USED QLIM < <(quota_kb) || true
    if [[ "$QLIM" =~ ^[0-9]+$ && "$USED" =~ ^[0-9]+$ ]] && (( USED >= QLIM )); then
      echo quota > "$RUN/$JOB.stop"; kill -TERM "$DPID" 2>/dev/null || true; break
    fi
    C="$(copied)"; prog running "$C" "$TOTAL" "$C of about $TOTAL messages copied"
  done
  RC=0; wait "$DPID" || RC=$?
  C="$(copied)"; STOP="$(cat "$RUN/$JOB.stop" 2>/dev/null || true)"
  # a fast run can end between two polls: over quota at the end is "full" too
  if [[ -z "$STOP" ]]; then
    USED=""; QLIM=""; read -r USED QLIM < <(quota_kb) || true
    [[ "$QLIM" =~ ^[0-9]+$ && "$USED" =~ ^[0-9]+$ ]] && (( USED >= QLIM )) && STOP=quota
  fi
  case "$STOP" in
    quota)  prog full "$C" "$TOTAL" "mailbox full after $C messages — new mail is refused until you raise its quota; then run the import again (mail already copied is skipped)" ;;
    cancel) prog cancelled "$C" "$TOTAL" "cancelled after $C messages" ;;
    gone)   prog failed "$C" "$TOTAL" "the mailbox was deleted" ;;
    *) if [[ "$RC" -eq 0 ]]; then prog done "$C" "$TOTAL" "$C new messages copied"
       elif [[ "$RC" -eq 124 ]]; then prog failed "$C" "$TOTAL" "stopped after $(( LIMIT_SECS / 3600 )) hours — run the import again to continue (mail already copied is skipped)"
       else prog failed "$C" "$TOTAL" "$(why "$T/sync.err")"; fi ;;
  esac
  log "end: ${STOP:-rc=$RC}, $C new messages"
) &
echo "import started: about $TOTAL messages ($(( BYTES / 1048576 )) MB) — progress below"
