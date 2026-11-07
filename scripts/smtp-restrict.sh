#!/usr/bin/env bash
# smtp-restrict.sh — hosting accounts can't open SMTP connections to other
# servers; their mail goes through Exim (PHP mail()/sendmail, or SMTP to this
# box), where limits, the mail guard and DKIM apply. A webshell or a spam
# script talking straight to remote MXes bypasses all of that and gets the
# server's IP blacklisted.
#   status                 TSV for the Firewall page / Security Advisor
#   set <off|mx|all>       mx = port 25 only (default for fresh installs);
#                          all = 25, 465, 587, 2525 too (breaks SMTP plugins
#                          that log in to Gmail/SendGrid/Brevo — exempt those
#                          accounts)
#   exempt-add|exempt-del <user>
#   apply [--quiet]        (re)build the rules from the state file (ufw hook)
#   clear                  remove the rules (ufw hook on stop; state kept)
# How: own chain KARNAK-SMTP, jumped from OUTPUT position 1 (v4 + v6) for new
# TCP connections to the SMTP ports by uid >= 1000. Inside: loopback and the
# box's own addresses, nobody, exempted accounts and every uid >= 1000 with a
# login shell (operators) RETURN; the rest is logged
# (rate-limited, KARNAK-SMTP-BLOCK, with the uid) and reset. Exim's remote
# deliveries run as Debian-exim (system uid), so mail() is never caught.
# Persistence: /etc/ufw/after.init (ufw runs it after loading its rules and
# on stop) — written only over the stock file or our own. Idempotent.
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/account-check.sh"
CH=KARNAK-SMTP
STATE=/var/lib/karnakpanel/smtp-restrict.conf
HOOK=/etc/ufw/after.init
SELF=/usr/local/skypanel/scripts/smtp-restrict.sh
MARK="# KarnakPanel smtp-restrict hook (managed by smtp-restrict.sh)"

MODE=off; EXEMPT=""
if [[ -r "$STATE" ]]; then
  # parsed, never sourced
  m="$(sed -n 's/^mode=\(off\|mx\|all\)$/\1/p' "$STATE" | tail -1)"; [[ -n "$m" ]] && MODE="$m"
  EXEMPT="$(sed -n 's/^exempt=\([a-z0-9_,]*\)$/\1/p' "$STATE" | tail -1)"
fi
save_state() {
  mkdir -p "$(dirname "$STATE")"
  printf 'mode=%s\nexempt=%s\n' "$MODE" "$EXEMPT" > "$STATE.tmp" && chmod 644 "$STATE.tmp" && mv -f "$STATE.tmp" "$STATE"
}
ports() { [[ "$MODE" == all ]] && echo "25,465,587,2525" || echo "25"; }
have6() { command -v ip6tables >/dev/null && ip6tables -w 5 -S OUTPUT >/dev/null 2>&1; }

clear_rules() {
  local ipt rules
  for ipt in iptables ip6tables; do
    [[ "$ipt" == ip6tables ]] && ! have6 && continue
    # capture first, then filter (pipefail + grep -q SIGPIPE — gotchas.md)
    rules="$("$ipt" -w 5 -S OUTPUT)"
    while IFS= read -r r; do
      [[ -n "$r" ]] || continue
      # shellcheck disable=SC2086  # the rule spec is word-split on purpose
      # nosemgrep: bash.lang.correctness.unquoted-expansion.unquoted-variable-expansion-in-command -- rule spec is word-split on purpose
      "$ipt" -w 5 ${r/#-A /-D }
    done < <(grep -- "-j $CH\$" <<<"$rules" || true)
    if "$ipt" -w 5 -S "$CH" >/dev/null 2>&1; then
      "$ipt" -w 5 -F "$CH"; "$ipt" -w 5 -X "$CH"
    fi
  done
}

# no login shell = hosting account (or a service user like vmail, which mails
# through sendmail anyway). Not panel_account: its group test refuses an
# account whose login group is NAMED admin, so the owner's own "admin" site
# account would slip through.
no_login() {
  case "$1" in /usr/sbin/nologin|/sbin/nologin|/bin/false|/usr/bin/false) return 0 ;; esac
  return 1
}
# uids that must never be caught: nobody, exempted accounts, login users
skip_uids() {
  local u uid e
  echo 65534
  IFS=, read -ra e <<<"$EXEMPT"
  for u in "${e[@]}"; do
    [[ -n "$u" ]] && uid="$(id -u "$u" 2>/dev/null)" && echo "$uid"
  done
  while IFS=: read -r u _ uid _ _ _ sh; do
    [[ "$uid" =~ ^[0-9]+$ ]] && (( uid >= 1000 && uid != 65534 )) || continue
    no_login "$sh" || echo "$uid"
  done < <(getent passwd)
}
restricted_users() {
  local u uid out=() e=",$EXEMPT,"
  while IFS=: read -r u _ uid _ _ _ sh; do
    [[ "$uid" =~ ^[0-9]+$ ]] && (( uid >= 1000 && uid != 65534 )) || continue
    [[ "$e" == *",$u,"* ]] && continue
    no_login "$sh" && out+=("$u")
  done < <(getent passwd)
  # nosemgrep: bash.lang.security.ifs-tampering.ifs-tampering -- IFS set inside a subshell to join with commas
  (IFS=,; echo "${out[*]}")
}

apply_rules() {
  local ipt uid
  clear_rules
  [[ "$MODE" == off ]] && return 0
  mapfile -t SKIP < <(skip_uids | sort -un)
  for ipt in iptables ip6tables; do
    [[ "$ipt" == ip6tables ]] && ! have6 && continue
    "$ipt" -w 5 -N "$CH"
    "$ipt" -w 5 -A "$CH" -o lo -j RETURN
    "$ipt" -w 5 -A "$CH" -m addrtype --dst-type LOCAL -j RETURN
    for uid in "${SKIP[@]}"; do "$ipt" -w 5 -A "$CH" -m owner --uid-owner "$uid" -j RETURN; done
    "$ipt" -w 5 -A "$CH" -m limit --limit 6/min --limit-burst 10 -j LOG --log-prefix "KARNAK-SMTP-BLOCK " --log-uid --log-level 4
    "$ipt" -w 5 -A "$CH" -p tcp -j REJECT --reject-with tcp-reset
    "$ipt" -w 5 -I OUTPUT 1 -p tcp -m multiport --dports "$(ports)" -m conntrack --ctstate NEW -m owner --uid-owner 1000-4294967294 -j "$CH"
  done
}

# ufw hook: only over the stock file (or ours, or none); a hand-made one is left alone
hook_state() {
  [[ -d /etc/ufw ]] || { echo none; return; }
  [[ -f "$HOOK" ]] || { echo missing; return; }
  if grep -qF "$MARK" "$HOOK"; then [[ -x "$HOOK" ]] && echo ok || echo off
  elif [[ -f /usr/share/ufw/after.init ]] && cmp -s "$HOOK" /usr/share/ufw/after.init; then echo missing
  else echo foreign; fi
}
install_hook() {
  local h; h="$(hook_state)"
  case "$h" in
    ok|none) return 0 ;;
    foreign) echo "WARN: $HOOK is customised — not replaced; the SMTP rules won't survive a reboot or ufw reload. Add \"$SELF apply --quiet\" to its start case and \"$SELF clear\" to stop/flush-all." >&2; return 0 ;;
  esac
  [[ -f "$HOOK" && ! -f "$HOOK.karnak-orig" ]] && cp -a "$HOOK" "$HOOK.karnak-orig"
  cat > "$HOOK.tmp" <<EOF
#!/bin/sh
$MARK
# ufw runs this after loading its rules (start) and on stop/reload. The stock
# file is kept as after.init.karnak-orig.
case "\$1" in
start) $SELF apply --quiet || true ;;
stop|flush-all) $SELF clear || true ;;
status) ;;
*) echo "'\$1' not supported"; echo "Usage: after.init {start|stop|flush-all|status}" ;;
esac
EOF
  chmod 750 "$HOOK.tmp" && mv -f "$HOOK.tmp" "$HOOK"
}

live_state() {
  local r4 c
  r4="$(iptables -w 5 -S OUTPUT 2>/dev/null || true)"
  c="$(iptables -w 5 -S "$CH" 2>/dev/null || true)"
  if grep -q -- "-j $CH\$" <<<"$r4" && grep -q -- "-j REJECT" <<<"$c"; then
    grep -m1 -- "-j $CH\$" <<<"$r4" | grep -q -- "--dports 25,465,587,2525" && echo all || echo mx
  else echo off; fi
}
blocked_count() {
  local ipt n t=0
  for ipt in iptables ip6tables; do
    n="$("$ipt" -w 5 -L "$CH" -v -x -n 2>/dev/null | awk '/REJECT/{s+=$1} END{print s+0}')" || n=0
    # nosemgrep: bash.lang.correctness.unquoted-expansion.unquoted-variable-expansion-in-command -- arithmetic context, no word splitting
    t=$((t + ${n:-0}))
  done
  echo "$t"
}

CMD="${1:-status}"
case "$CMD" in
  status)
    printf 'mode\t%s\nlive\t%s\nhook\t%s\nexempt\t%s\nrestricted\t%s\nblocked\t%s\n' \
      "$MODE" "$(live_state)" "$(hook_state)" "$EXEMPT" "$(restricted_users)" "$(blocked_count)"
    # blocked attempts per uid over the last 7 days (rate-limited log)
    if command -v journalctl >/dev/null; then
      J="$(timeout 5 journalctl -k --since -7d --no-pager -q -g KARNAK-SMTP-BLOCK 2>/dev/null || true)"
      while read -r n uid; do
        [[ "$uid" =~ ^[0-9]+$ ]] || continue
        printf 'recent\t%s\t%s\n' "$(getent passwd "$uid" | cut -d: -f1 || echo "uid $uid")" "$n"
      done < <(grep -o 'UID=[0-9]*' <<<"$J" | cut -d= -f2 | sort | uniq -c | sort -rn | head -20 || true)
    fi ;;
  set)
    M="${2:-}"; [[ "$M" =~ ^(off|mx|all)$ ]] || { echo "want off|mx|all"; exit 1; }
    MODE="$M"; save_state
    [[ "$MODE" == off ]] || install_hook
    apply_rules
    echo "SMTP restriction: $MODE (live: $(live_state))" ;;
  exempt-add|exempt-del)
    U="${2:-}"; valid_account_name "$U"
    E=",$EXEMPT,"; E="${E//,$U,/,}"
    if [[ "$CMD" == exempt-add ]]; then
      getent passwd "$U" >/dev/null || { echo "no such user $U"; exit 1; }
      E="$E$U,"
    fi
    E="$(tr -s , <<<"$E")"; E="${E#,}"; EXEMPT="${E%,}"; save_state
    apply_rules
    echo "exempt: ${EXEMPT:-none}" ;;
  apply)
    [[ "$MODE" == off ]] || install_hook
    apply_rules
    [[ "${2:-}" == --quiet ]] || echo "SMTP restriction: $MODE (live: $(live_state))" ;;
  clear)
    clear_rules ;;
  *) echo "usage: smtp-restrict.sh status|set <off|mx|all>|exempt-add <user>|exempt-del <user>|apply|clear"; exit 1 ;;
esac
