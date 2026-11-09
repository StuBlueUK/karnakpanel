#!/usr/bin/env bash
# blacklist-check.sh [now|running|verdict <zone> <answers>] — is this
# server's sending IP (v4 + global v6) or a hosted domain on a mail
# blacklist? Caches one row per (target, list) in blacklist_status for the
# Mail queue page; alerts the admin ONLY on a change (newly listed /
# delisted), never nightly for the same listing.
#   (none)   check everything now (nightly cron)
#   now      start a check in the background (the page's Check now button)
#   running  print running|idle
#   verdict  pure decision on a list's answers (tests/run.php)
#
# A list counts as checked only when its permanent TEST ENTRY answers
# "listed" through the resolver used. Spamhaus refuses public resolvers
# (127.255.255.254) and the local BIND gave prod no answer at all — a plain
# lookup there would report "not listed" forever. So each list tries the
# local BIND, the system resolver, the resolvectl upstreams, then public
# resolvers, and uses the first one that answers the test entry correctly;
# none = "unchecked" (shown as can't-check-from-here), never "clean".
# Error answers (Spamhaus 127.255.255.x, URIBL/SURBL 127.0.0.1 "query
# blocked") are never listings. SERVFAIL/timeout = unchecked.
# IPv6 only on Spamhaus ZEN (the others do not list IPv6).
# Test hooks: BLACKLIST_TEST_IP (checked instead of the server's IPv4, e.g.
# 127.0.0.2 = always listed), BLACKLIST_TEST_DOMAIN (instead of the hosted
# domains, e.g. dbltest.com), BLACKLIST_ALERT_TO (mail recipients).
set -uo pipefail
LOG="/var/log/karnakpanel/panel.log"
LOCK=/run/lock/skypanel-blacklist.lock
HOST="$(hostname -f 2>/dev/null || hostname)"

# zone|name|kinds|test entry|delist page
LISTS=(
  "zen.spamhaus.org|Spamhaus ZEN|ip ip6|2.0.0.127|https://check.spamhaus.org/"
  "bl.spamcop.net|SpamCop|ip|2.0.0.127|https://www.spamcop.net/bl.shtml"
  "b.barracudacentral.org|Barracuda|ip|2.0.0.127|https://www.barracudacentral.org/rbl/removal-request"
  "psbl.surriel.com|PSBL|ip|2.0.0.127|https://psbl.org/remove"
  "truncate.gbudb.net|GBUdb Truncate|ip|2.0.0.127|https://www.gbudb.com/truncate/"
  "dnsbl-1.uceprotect.net|UCEPROTECT L1|ip|2.0.0.127|https://www.uceprotect.net/en/rblcheck.php"
  "dbl.spamhaus.org|Spamhaus DBL|dom|dbltest.com|https://check.spamhaus.org/"
  "multi.surbl.org|SURBL|dom|test.surbl.org|https://www.surbl.org/surbl-analysis"
  "multi.uribl.com|URIBL|dom|test.uribl.com|https://admin.uribl.com/"
)

# verdict <zone> <answers> — answers = "NX" | "ERR" | space-separated A
# records. Prints clean | error | listed:<what>. Codes from each list's docs:
# ZEN 127.0.0.2-11 (2,9 SBL/DROP, 3 CSS, 4-7 XBL, 10-11 PBL), DBL
# 127.0.1.2-99, URIBL bitmask (2 black, 8 red; 4 grey alone = not a
# listing), SURBL bitmask (any bit above 1), the rest 127.0.0.2.
verdict() {
  local z="$1" a="$2" o n m="" bad=0
  [[ "$a" == NX ]] && { echo clean; return; }
  [[ "$a" == ERR || -z "$a" ]] && { echo error; return; }
  for o in $a; do
    if [[ ! "$o" =~ ^127\.([0-9]+)\.([0-9]+)\.([0-9]+)$ ]]; then bad=1; continue; fi
    n="${BASH_REMATCH[3]}"
    case "$z" in
      zen.spamhaus.org)
        if [[ "${BASH_REMATCH[1]}.${BASH_REMATCH[2]}" != 0.0 ]]; then bad=1
        elif (( n == 2 || n == 9 )); then m+="SBL "
        elif (( n == 3 )); then m+="CSS "
        elif (( n >= 4 && n <= 7 )); then m+="XBL "
        elif (( n == 10 || n == 11 )); then m+="PBL "
        else bad=1; fi ;;
      dbl.spamhaus.org)
        if [[ "${BASH_REMATCH[1]}.${BASH_REMATCH[2]}" == 0.1 ]] && (( n >= 2 && n <= 99 )); then m+="DBL "; else bad=1; fi ;;
      multi.uribl.com)
        if [[ "${BASH_REMATCH[1]}.${BASH_REMATCH[2]}" != 0.0 ]] || (( n == 1 )); then bad=1
        else (( n & 2 )) && m+="black "; (( n & 8 )) && m+="red "; fi ;;
      multi.surbl.org)
        if [[ "${BASH_REMATCH[1]}.${BASH_REMATCH[2]}" == 0.0 ]] && (( n > 1 )); then m+="listed "; else bad=1; fi ;;
      *)
        if [[ "$o" == 127.0.0.2 ]]; then m+="listed "; else bad=1; fi ;;
    esac
  done
  if [[ -n "$m" ]]; then echo "listed:$(tr ' ' '\n' <<<"$m" | grep -v '^$' | sort -u | paste -sd' ' -)"
  elif (( bad )); then echo error
  else echo clean; fi
}

if [[ "${1:-}" == verdict ]]; then verdict "${2:-}" "${3:-}"; exit 0; fi
if [[ "${1:-}" == running ]]; then
  exec 9>"$LOCK" || { echo idle; exit 0; }
  if flock -n 9; then echo idle; else echo running; fi; exit 0
fi
if [[ "${1:-}" == now ]]; then
  ( setsid bash "$0" >/dev/null 2>&1 </dev/null & )
  echo "Blacklist check started — results in a minute or two."; exit 0
fi
[[ -z "${1:-}" ]] || { echo "usage: $0 [now|running]"; exit 1; }

command -v dig >/dev/null 2>&1 || { echo "no dig (dnsutils missing)"; exit 1; }
PASS="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
[[ -n "$PASS" ]] || { echo "no panel db pass (/root/.skypanel-dbpass)"; exit 1; }
sql() { MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "$1"; }
exec 9>"$LOCK"; flock -n 9 || { echo "a check is already running"; exit 0; }

# q <resolver|-> <name> — NX | ERR | A answers ("-" = the system resolver)
q() {
  local at=() out st a
  [[ "$1" != - ]] && at=("@$1")
  out="$(timeout 8 dig +time=3 +tries=1 +noall +comments +answer A "$2." "${at[@]}" 2>/dev/null)" || { echo ERR; return; }
  st="$(grep -oE 'status: [A-Z]+' <<<"$out" | head -1 | cut -d' ' -f2)"
  case "$st" in
    NXDOMAIN) echo NX ;;
    NOERROR) a="$(awk '$4=="A"{print $5}' <<<"$out" | sort -u | paste -sd' ' -)"; echo "${a:-NX}" ;;
    *) echo ERR ;;
  esac
}
CANDS=(127.0.0.1 -)
while read -r r; do
  [[ "$r" =~ ^[0-9a-f.:]+$ && "$r" != 127.* && "$r" != ::1 ]] && CANDS+=("$r")
done < <(resolvectl dns 2>/dev/null | sed 's/^[^:]*://' | tr ' ' '\n' | sed 's/%.*//' | sort -u)
CANDS+=(1.1.1.1 8.8.8.8 9.9.9.9)

# --- what to check
V4=(); V6=(); DOMS=()
if [[ -n "${BLACKLIST_TEST_IP:-}" ]]; then
  [[ "$BLACKLIST_TEST_IP" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || { echo "bad BLACKLIST_TEST_IP"; exit 1; }
  V4=("$BLACKLIST_TEST_IP")
else
  for ip in $(hostname -I 2>/dev/null); do
    [[ "$ip" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || continue
    [[ "$ip" =~ ^(10\.|127\.|192\.168\.|169\.254\.|172\.(1[6-9]|2[0-9]|3[01])\.|100\.(6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])\.) ]] && continue
    (( ${#V4[@]} < 4 )) && V4+=("$ip")
  done
  while read -r ip; do
    [[ "$ip" =~ ^[0-9a-f:]+$ && ! "$ip" =~ ^f[cd] && ! "$ip" =~ ^fe80 ]] || continue
    (( ${#V6[@]} < 4 )) && V6+=("$ip")
  done < <(ip -6 addr show scope global 2>/dev/null | awk '/inet6/ && !/temporary|deprecated/{sub(/\/.*/,"",$2); print $2}' | sort -u)
fi
# registrable domain (DBL lists those, not subdomains); same two-level
# suffixes as expiry-check.sh
parent_domain() {
  local D="$1" sfx
  for sfx in co.uk org.uk me.uk ltd.uk plc.uk com.au net.au org.au co.nz co.za com.br com.mx co.jp co.in com.ar; do
    [[ "$D" == *".$sfx" ]] && { rev <<<"$D" | cut -d. -f1-3 | rev; return; }
  done
  rev <<<"$D" | cut -d. -f1-2 | rev
}
if [[ -n "${BLACKLIST_TEST_DOMAIN:-}" ]]; then
  [[ "$BLACKLIST_TEST_DOMAIN" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad BLACKLIST_TEST_DOMAIN"; exit 1; }
  DOMS=("$BLACKLIST_TEST_DOMAIN")
else
  while read -r D; do
    [[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || continue
    [[ "$D" =~ \.(local|lan|test|internal|localhost|example|invalid|home|arpa)$ ]] && continue
    DOMS+=("$(parent_domain "$D")")
  done < <(sql "SELECT domain FROM domains")
  mapfile -t DOMS < <(printf '%s\n' "${DOMS[@]}" | grep -v '^$' | sort -u)
fi
rev4() { awk -F. '{print $4"."$3"."$2"."$1}' <<<"$1"; }
# full nibble form: 2001:db8::1 -> 1.0.0.0...8.b.d.0.1.0.0.2
rev6() {
  python3 -c 'import ipaddress,sys; print(".".join(reversed(ipaddress.ip_address(sys.argv[1]).exploded.replace(":",""))))' "$1" 2>/dev/null
}

NEWS=(); GONE=(); LISTED=0; UNCHECKED=0
# record <target> <kind> <zone> <name> <state> <detail> <delist>
# A listing is "open" while listed_since is set; it survives an unchecked
# night (a refused lookup proves nothing either way), so a list that flaps
# between answering and refusing never re-alerts the same listing.
record() {
  local was since why=""
  was="$(sql "SELECT listed_since FROM blacklist_status WHERE target='$1' AND zone='$3' AND listed_since IS NOT NULL")"
  [[ "$6" != listed && -n "$6" ]] && why=" ($6)"
  if [[ "$5" == listed ]]; then
    since="${was:+'$was'}"; since="${since:-NOW()}"
    [[ -n "$was" ]] || NEWS+=("$1 is listed on $4$why — $7")
  elif [[ "$5" == unchecked ]]; then
    since="${was:+'$was'}"; since="${since:-NULL}"
  else
    since=NULL
    [[ -n "$was" ]] && GONE+=("$1 is no longer listed on $4")
  fi
  [[ "$5" == listed ]] && LISTED=$((LISTED+1)); [[ "$5" == unchecked ]] && UNCHECKED=$((UNCHECKED+1))
  sql "INSERT INTO blacklist_status(target,kind,zone,name,state,detail,delist,listed_since,checked_at)
       VALUES('$1','$2','$3','$4','$5','$6','$7',$since,NOW())
       ON DUPLICATE KEY UPDATE kind=VALUES(kind),name=VALUES(name),state=VALUES(state),detail=VALUES(detail),
         delist=VALUES(delist),listed_since=$since,checked_at=NOW()"
}

for L in "${LISTS[@]}"; do
  IFS='|' read -r Z NAME KINDS TP DEL <<<"$L"
  USE=""
  for r in "${CANDS[@]}"; do
    [[ "$(verdict "$Z" "$(q "$r" "$TP.$Z")")" == listed:* ]] && { USE="$r"; break; }
  done
  check() { # check <target> <kind> <qname>
    local v
    if [[ -z "$USE" ]]; then record "$1" "$2" "$Z" "$NAME" unchecked "refuses lookups from every DNS resolver this server can use" "$DEL"; return; fi
    v="$(verdict "$Z" "$(q "$USE" "$3.$Z")")"
    case "$v" in
      clean) record "$1" "$2" "$Z" "$NAME" clean "" "$DEL" ;;
      listed:*) record "$1" "$2" "$Z" "$NAME" listed "${v#listed:}" "$DEL" ;;
      *) record "$1" "$2" "$Z" "$NAME" unchecked "no usable answer tonight" "$DEL" ;;
    esac
  }
  if [[ " $KINDS " == *" ip "* ]]; then for ip in "${V4[@]}"; do check "$ip" ipv4 "$(rev4 "$ip")"; done; fi
  if [[ " $KINDS " == *" ip6 "* ]]; then for ip in "${V6[@]}"; do n="$(rev6 "$ip")"; [[ -n "$n" ]] && check "$ip" ipv6 "$n"; done; fi
  if [[ " $KINDS " == *" dom "* ]]; then for D in "${DOMS[@]}"; do check "$D" domain "$D"; done; fi
done

# targets that are gone (IP changed, domain deleted) and lists no longer
# checked leave the page (by name, not by time: back-to-back runs share a
# second). Every name here passed the regexes above.
inlist() { local x o=""; for x in "$@"; do o+="${o:+,}'$x'"; done; echo "${o:-''}"; }
ZONES=(); for L in "${LISTS[@]}"; do ZONES+=("${L%%|*}"); done
sql "DELETE FROM blacklist_status WHERE target NOT IN ($(inlist "${V4[@]}" "${V6[@]}" "${DOMS[@]}")) OR zone NOT IN ($(inlist "${ZONES[@]}"))"
RELAY=0; [[ -e /etc/exim4/virtual/relay_on ]] && RELAY=1
sql "INSERT INTO settings(name,value) VALUES('blacklist_checked_at','$(date -Is)'),('blacklist_relay','$RELAY') ON DUPLICATE KEY UPDATE value=VALUES(value)"

# --- alerts: changes only
notify() {
  sql "INSERT INTO notifications(user_id,type,title) VALUES(NULL,'blacklist','${1//\'/}')" >>"$LOG" 2>&1 || echo "$(date -Is) blacklist-check: notify insert failed" >>"$LOG"
}
recipients() {
  local explicit
  explicit="$(sql "SELECT value FROM settings WHERE name='alert_email'" 2>/dev/null | grep -E '^[^@[:space:]]+@[^@[:space:]]+\.[a-z]{2,}$' | grep -vE '@localhost$' | head -1 || true)"
  if [[ -n "$explicit" ]]; then echo "$explicit"; return; fi
  sql "SELECT DISTINCT email FROM users WHERE role='admin'" 2>/dev/null | grep -E '^[^@[:space:]]+@[^@[:space:]]+\.[a-z]{2,}$' | grep -vE '@localhost$' || true
}
if (( ${#NEWS[@]} + ${#GONE[@]} )); then
  for m in "${NEWS[@]}"; do notify "Blacklisted: ${m%% — *}"; done
  for m in "${GONE[@]}"; do notify "Delisted: $m"; done
  if (( ${#NEWS[@]} )); then SUBJ="Blacklist alert on $HOST: ${#NEWS[@]} new listing(s)"; else SUBJ="Blacklist recovery on $HOST"; fi
  BODY="$( (( ${#NEWS[@]} )) && { echo "Newly listed:"; printf '  - %s\n' "${NEWS[@]}"; echo; }
    (( ${#GONE[@]} )) && { echo "No longer listed:"; printf '  - %s\n' "${GONE[@]}"; echo; }
    (( RELAY )) && { echo "Note: outgoing mail goes through your SMTP relay, so receivers judge the relay's IP, not this server's."; echo; }
    echo "Details and removal links: Server > Mail queue in the panel."
    echo "Fix the cause first (a hacked mailbox or script sending spam), then ask for removal: most lists drop a listing by themselves within days once the spam stops. Never pay for removal.")"
  TOS="${BLACKLIST_ALERT_TO:-$(recipients)}"
  if [[ -z "${TOS// }" ]]; then echo "$(date -Is) blacklist-check: no routable admin email — alert logged only: $SUBJ" >>"$LOG"
  else
    for to in $TOS; do
      # 9>&- : the delivery process sendmail forks must not inherit the run lock
      /usr/sbin/sendmail -t 2>/dev/null 9>&- <<EOF || echo "$(date -Is) blacklist-check: sendmail to $to failed" >>"$LOG"
To: $to
From: KarnakPanel Blacklist Monitor <watchdog@$HOST>
Auto-Submitted: auto-generated
Subject: $SUBJ

$BODY
EOF
    done
  fi
fi
echo "$(date -Is) blacklist-check: ${#V4[@]} IPv4, ${#V6[@]} IPv6, ${#DOMS[@]} domain(s): $LISTED listed, $UNCHECKED unchecked, ${#NEWS[@]} new, ${#GONE[@]} cleared" | tee -a "$LOG"
