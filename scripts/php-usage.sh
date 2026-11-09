#!/usr/bin/env bash
# php-usage.sh [user…] — PHP resource use per account (own FPM masters,
# fpm-account.sh), for the Accounts list and the customer dashboard.
# No user = every account with a pool. One TSV line per account:
#   user state ram_mb (without page cache) cap_mb cpu_cap_% peak_mb (incl. page cache) cpu_throttled_s oom_kills
#   ram_high_events proc_limit_hits since_epoch worker_full_7d worker_full_days_7d worker_limit
#   worker_full_real_7d worker_full_real_days_7d (bot bursts + warm-ups left out)
# php-usage.sh --worker-hits — only "user<TAB>times<TAB>days<TAB>limit<TAB>
# real-times<TAB>real-days" for every account whose PHP workers were all busy
# in the last 7 days (dashboard). --worker-cause <user>: see worker_cause.
# state = active|idle|failed|shared|off; "-" = not measured. Counters come
# from the account's slice cgroup and run since it last started (since_epoch,
# boot or first request after boot) — an idle stop keeps them. Read-only;
# straight from /sys so a page of 200 accounts costs two systemctl calls.
set -uo pipefail
. "$(dirname "$(readlink -f "$0")")/fpm-account.sh"
CG=/sys/fs/cgroup/karnakfpm.slice

# Workers full: FPM logs "server reached max_children setting (N)" once each
# time every worker of the pool is busy (visitors queue). Journal-tagged
# karnakfpm-<user> (fpm_master_text), so one indexed journalctl covers any
# number of accounts. Days = UTC days with at least one, last 7 days.
# WR/WRD = the same without bot bursts/warm-ups (bot_bursts below): what the dashboard
# and Accounts list call "needs more workers".
declare -A WH=() WD=() WL=() WR=() WRD=()
worker_hits() {
  local a=() U u h d l
  for U in "$@"; do a+=(-t "karnakfpm-$U"); done
  (( ${#a[@]} )) || return 0
  while read -r u h d l; do
    [[ "$u" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || continue
    WH["$u"]="$h"; WD["$u"]="$d"; WL["$u"]="$l"; WR["$u"]="$h"; WRD["$u"]="$d"
  done < <(journalctl "${a[@]}" --since -7d --no-pager -o short-unix 2>/dev/null | awk '
    / server reached max_children setting \([0-9]+\)/ {
      u = $3; sub(/^karnakfpm-/, "", u); sub(/\[.*/, "", u)
      d = int($1 / 86400); h[u]++
      if (!((u SUBSEP d) in seen)) { seen[u, d] = 1; n[u]++ }
      if (match($0, /setting \([0-9]+\)/)) l[u] = substr($0, RSTART + 9, RLENGTH - 10)
    }
    END { for (u in h) print u, h[u], n[u], (l[u] == "" ? "-" : l[u]) }')
  bot_bursts
}

# Bot bursts: a scanner sends ~100+ requests in 2-3 s from one address — it
# fills any pool (more workers only move the bar) and only it gets the 503s
# (prod/swiftnest 2026-11: 13 of 15 events, real visitors unaffected). They
# and warm-ups (worker_cause kind 1/2) stay on the Firewall page but don't
# make the account "need more workers". Each account's site logs are read only when its totals would
# warn at all (same rule as PhpUsage::keepsFillingWorkers: 2+ days or 5+
# times), within a time budget so a scan wave across many sites can't stall
# the dashboard: anything not classified counts as real (never hides one).
bot_bursts() {
  local u t bots days start=$SECONDS
  for u in "${!WH[@]}"; do
    (( WD[$u] >= 2 || WH[$u] >= 5 )) || continue
    (( SECONDS - start < 8 )) || break
    bots=0; days=""
    while IFS=$'\t' read -r _ t _ _ _ _ _ b; do
      [[ "$t" =~ ^[0-9]+$ ]] || continue
      if [[ "$b" == [12] ]]; then bots=$((bots + 1)); else days+="$(( t / 86400 )) "; fi
    done < <(worker_cause "$u" | grep '^ev'$'\t')
    WR["$u"]=$(( WH[$u] > bots ? WH[$u] - bots : 0 ))
    WRD["$u"]="$(printf '%s\n' $days | sort -u | grep -c .)"
  done
}

# worker_cause <user>: what was hitting the account's sites when its PHP
# workers were all busy (last 7 days). One "ev" line per event, then its top
# clients and paths:
#   ev<TAB>epoch<TAB>limit<TAB>secs-since-PHP-started|-<TAB>requests<TAB>503s<TAB>distinct paths<TAB>kind
#   ip<TAB>epoch<TAB>ip<TAB>requests<TAB>503s<TAB>distinct paths<TAB>top path<TAB>user agent
#   path<TAB>epoch<TAB>requests<TAB>path
# Window = 15 s before to 3 s after the FPM warning. kind 1 = bot burst: one
# /24 (IPv6: /64) sent 40+ of the window's requests and 60%+ of them —
# scanners rotate neighbouring IPs (swiftnest 10-31: .100/.101/.102); 20-odd
# requests can't fill 10 workers by themselves, so those stay real. kind 2 =
# warm-up: PHP had started <= 30 s earlier (idle stop, first requests at
# once; the listen backlog queues them). 0 = real, incl. no log lines. The account's logs come from the root-owned
# vhost confs (docRoot under /home/<user>/), never from names in the
# customer's home (a customer could mkdir another tenant's domain there).
# Paths/agents are visitor-controlled: callers escape them.
worker_cause() {
  local U="$1" C D L e t WIN EVS
  local -a EV=() LOGS=()
  mapfile -t EV < <(journalctl -t "karnakfpm-$U" --since -7d --no-pager -o short-unix 2>/dev/null | awk '
    / fpm is running, pid / { st = int($1) }
    / server reached max_children setting \([0-9]+\)/ {
      l = "-"; if (match($0, /setting \([0-9]+\)/)) l = substr($0, RSTART + 9, RLENGTH - 10)
      print int($1), l, (st ? int($1) - st : "-") }')
  (( ${#EV[@]} )) || return 0
  for C in /usr/local/lsws/conf/vhosts/*/vhost.conf; do
    [[ -f "$C" && ! -L "$C" ]] || continue
    grep -qE "^docRoot[[:space:]]+/home/$U/" "$C" || continue
    D="$(awk '$1=="vhDomain"{print $2; exit}' "$C")"
    [[ "$D" =~ ^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$ ]] || continue
    for L in /var/log/karnakpanel/"$D".access.log /var/log/karnakpanel/"$D".access.log.*; do
      [[ -f "$L" && ! -L "$L" ]] && [[ -n "$(find "$L" -maxdepth 0 -mtime -8 2>/dev/null)" ]] && LOGS+=("$L")
    done
  done
  # each event's seconds as OLS prints them (local time, C-locale month): "sec<TAB>event epoch"
  WIN="$(for e in "${EV[@]}"; do
    read -r t _ _ <<< "$e"
    for (( s = t - 15; s <= t + 3; s++ )); do printf '%s\t%s\n' "$(LC_ALL=C date -d "@$s" '+%d/%b/%Y:%H:%M:%S')" "$t"; done
  done)"
  EVS="$(printf '%s\n' "${EV[@]}")"
  { (( ${#LOGS[@]} )) && for L in "${LOGS[@]}"; do zcat -f -- "$L" 2>/dev/null; done; } | LC_ALL=C awk -v win="$WIN" -v evs="$EVS" '
    BEGIN {
      n = split(win, w, "\n"); for (i = 1; i <= n; i++) { split(w[i], p, "\t"); sec[p[1]] = p[2] }
      m = split(evs, e, "\n"); for (i = 1; i <= m; i++) { split(e[i], p, " "); if (p[1] != "") { ev[p[1]] = 1; lim[p[1]] = p[2]; age[p[1]] = p[3] } }
    }
    # the network a client sits in: IPv4 /24, IPv6 first four groups (/64)
    function net(ip,   g, k) {
      if (index(ip, ":")) { k = split(ip, g, ":"); return g[1] ":" g[2] ":" g[3] ":" g[4] }
      sub(/\.[0-9]+$/, "", ip); return ip
    }
    {
      line = $0; sub(/^'"'"'/, "", line)
      # ip - - [dd/Mon/yyyy:HH:MM:SS +zzzz] "GET /p HTTP/x" status bytes "ref" "agent"
      if (!match(line, /\[[0-9][0-9]\/[A-Z][a-z][a-z]\/[0-9]+:[0-9:]+ /)) next
      s = substr(line, RSTART + 1, RLENGTH - 2); if (!(s in sec)) next
      t = sec[s]; split(line, f, " "); ip = f[1]
      nq = split(line, q, "\"")
      split(q[2], r, " "); path = r[2]; sub(/\?.*/, "", path); if (length(path) > 80) path = substr(path, 1, 80) "…"
      split(q[3], sc, " "); st = sc[1]; ua = (nq >= 6 ? q[6] : "-"); if (length(ua) > 80) ua = substr(ua, 1, 80) "…"
      gsub(/\t/, " ", path); gsub(/\t/, " ", ua)
      tot[t]++; if (st == "503") s5[t]++
      k = t SUBSEP ip; ipn[k]++; if (st == "503") ip5[k]++
      gk = t SUBSEP net(ip); gn[gk]++; if (gn[gk] > gtop[t]) gtop[t] = gn[gk]
      if (!(k in ipua)) ipua[k] = ua
      pk = k SUBSEP path; if (!(pk in ipp)) ipd[k]++; ipp[pk]++; if (ipp[pk] > ipbest[k]) { ipbest[k] = ipp[pk]; iptop[k] = path }
      kp = t SUBSEP path; if (!(kp in pn)) evd[t]++; pn[kp]++
    }
    END {
      for (t in ev) {
        kind = (gtop[t] >= 40 && gtop[t] * 10 >= tot[t] * 6) ? 1 : ((age[t] != "-" && age[t] + 0 <= 30) ? 2 : 0)
        printf "ev\t%s\t%s\t%s\t%d\t%d\t%d\t%d\n", t, lim[t], age[t], tot[t], s5[t], evd[t], kind
        # top 3 clients, then top 3 paths (small n: selection by repeated max)
        delete used
        for (j = 0; j < 3; j++) { b = ""; bn = 0
          for (k in ipn) { split(k, kk, SUBSEP); if (kk[1] != t || (k in used)) continue; if (ipn[k] > bn) { bn = ipn[k]; b = k } }
          if (b == "") break; used[b] = 1; split(b, kk, SUBSEP)
          printf "ip\t%s\t%s\t%d\t%d\t%d\t%s\t%s\n", t, kk[2], ipn[b], ip5[b], ipd[b], iptop[b], ipua[b] }
        for (j = 0; j < 3; j++) { b = ""; bn = 0
          for (k in pn) { split(k, kk, SUBSEP); if (kk[1] != t || (k in used)) continue; if (pn[k] > bn) { bn = pn[k]; b = k } }
          if (b == "") break; used[b] = 1; split(b, kk, SUBSEP)
          printf "path\t%s\t%d\t%s\n", t, pn[b], kk[2] }
      }
    }' | sort -t$'\t' -k2,2nr -s
}

USERS=()
if [[ "${1:-}" == --worker-hits ]]; then
  (( $# == 1 )) || { echo "usage: php-usage.sh --worker-hits"; exit 1; }
  mapfile -t USERS < <(fpm_accounts)
  worker_hits "${USERS[@]}"
  for U in "${!WH[@]}"; do printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$U" "${WH[$U]}" "${WD[$U]}" "${WL[$U]}" "${WR[$U]}" "${WRD[$U]}"; done | sort
  exit 0
fi
if [[ "${1:-}" == --worker-cause ]]; then
  (( $# == 2 )) && [[ "$2" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "usage: php-usage.sh --worker-cause <user>"; exit 1; }
  worker_cause "$2"
  exit 0
fi
if (( $# )); then
  for U in "$@"; do
    [[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad user: $U"; exit 1; }
    USERS+=("$U")
  done
else
  mapfile -t USERS < <(fpm_accounts)
fi
(( ${#USERS[@]} )) || exit 0
worker_hits "${USERS[@]}"

# unit states in one call: "<unit> <active-state>"
declare -A ST=()
while read -r unit _ active _; do
  [[ -n "$unit" ]] && ST["$unit"]="$active"
done < <(systemctl list-units --all --plain --no-legend 'karnakfpm-*.service' 'karnakfpx-*.socket' 2>/dev/null)
declare -A SINCE=()
SL=(); for U in "${USERS[@]}"; do SL+=("karnakfpm-$U.slice"); done
# one block per unit, blank-line separated; Id is not always printed first
id=""; ts=""
while IFS='=' read -r k v; do
  case "$k" in
    Id) id="$v" ;;
    ActiveEnterTimestamp) ts="${v#@}" ;;
    "") [[ -n "$id" && "$ts" =~ ^[0-9]+$ ]] && SINCE["$id"]="$ts"; id=""; ts="" ;;
  esac
done < <(systemctl show -p Id -p ActiveEnterTimestamp --timestamp=unix "${SL[@]}" 2>/dev/null; echo)

kv() { awk -v k="$2" '$1==k{print $2; exit}' "$1" 2>/dev/null; }
mb() { [[ "$1" =~ ^[0-9]+$ ]] && echo $(( $1 / 1048576 )) || echo -; }

for U in "${USERS[@]}"; do
  if [[ ! -f "$FPMA_SYSD/karnakfpm-$U.service" ]]; then
    st=off; compgen -G "/etc/php/8.[0-9]/fpm/pool.d/skypanel-$U.conf" >/dev/null && st=shared
    printf '%s\t%s\t-\t-\t-\t-\t-\t-\t-\t-\t-\t-\t-\t-\t-\t-\n' "$U" "$st"; continue
  fi
  st="${ST["karnakfpm-$U.service"]:-inactive}"
  if [[ "$st" != active && -n "${ST["karnakfpx-$U.socket"]:-}" ]]; then
    [[ "${ST["karnakfpx-$U.socket"]}" == active ]] && st=idle || st=failed
  fi
  d="$CG/karnakfpm-$U.slice"
  if [[ ! -d "$d" ]]; then
    # not started since boot: no cgroup, nothing used yet; caps from the slice file
    f="$FPMA_SYSD/karnakfpm-$U.slice"
    cap="$(sed -n 's/^MemoryMax=\([0-9]*\)M$/\1/p' "$f" 2>/dev/null)"
    cpu="$(sed -n 's/^CPUQuota=\([0-9]*\)%$/\1/p' "$f" 2>/dev/null)"
    printf '%s\t%s\t0\t%s\t%s\t-\t-\t-\t-\t-\t-\t%s\t%s\t%s\t%s\t%s\n' "$U" "$st" "${cap:--}" "${cpu:--}" \
      "${WH[$U]:-0}" "${WD[$U]:-0}" "${WL[$U]:--}" "${WR[$U]:-0}" "${WRD[$U]:-0}"; continue
  fi
  # in use = everything but reclaimable page cache (opcache is shmem: counted)
  cur="$(awk '$1=="anon"{a=$2} $1=="file"{f=$2} $1=="shmem"{s=$2} $1=="kernel"{k=$2} END{print a+s+k}' "$d/memory.stat" 2>/dev/null)"
  cur="$(mb "$cur")"
  cap="$(mb "$(cat "$d/memory.max" 2>/dev/null)")"
  peak="$(mb "$(cat "$d/memory.peak" 2>/dev/null)")"
  read -r q p < "$d/cpu.max" 2>/dev/null || { q=; p=; }
  [[ "$q" =~ ^[0-9]+$ && "$p" =~ ^[0-9]+$ ]] && cpu=$(( q * 100 / p )) || cpu=-
  thr="$(kv "$d/cpu.stat" throttled_usec)"; [[ "$thr" =~ ^[0-9]+$ ]] && thr=$(( thr / 1000000 )) || thr=-
  oom="$(kv "$d/memory.events" oom_kill)"; high="$(kv "$d/memory.events" high)"
  pmax="$(kv "$d/pids.events" max)"
  printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$U" "$st" "$cur" "$cap" "$cpu" "$peak" "$thr" \
    "${oom:--}" "${high:--}" "${pmax:--}" "${SINCE["karnakfpm-$U.slice"]:--}" "${WH[$U]:-0}" "${WD[$U]:-0}" "${WL[$U]:--}" "${WR[$U]:-0}" "${WRD[$U]:-0}"
done
exit 0
