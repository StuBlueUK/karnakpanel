#!/usr/bin/env bash
# panel-jail-retry.sh — raise the fail2ban [panel] jail from the stock
# maxretry = 5 to 10. The panel locks an IP itself after 4 failed logins and
# says how long to wait; each try while locked logs a "login throttled" line
# the jail also counts. At 5 the firewall ban came first and the page just
# stopped loading. Only an exact stock `maxretry = 5` inside [panel] changes
# (a hand-tuned value is left alone). Reloads only that jail. Idempotent;
# quiet when there is nothing to do. Called by host-converge.sh.
set -euo pipefail
J=/etc/fail2ban/jail.local
[[ -f "$J" ]] || exit 0
awk '/^\[panel\]/{p=1; next} /^\[/{p=0} p && /^maxretry = 5$/{f=1} END{exit !f}' "$J" || exit 0
cp -a "$J" "$J.pre-panel-retry"
awk '/^\[/{p=($0=="[panel]")} p && $0=="maxretry = 5"{print "maxretry = 10"; next} {print}' "$J" > "$J.tmp"
[[ "$(wc -l < "$J.tmp")" == "$(wc -l < "$J")" ]] && grep -q '^maxretry = 10$' "$J.tmp" || { rm -f "$J.tmp"; echo "panel-jail-retry: edit failed — jail.local unchanged"; exit 1; }
cat "$J.tmp" > "$J"; rm -f "$J.tmp"
if fail2ban-client reload panel >/dev/null 2>&1 && [[ "$(fail2ban-client get panel maxretry 2>/dev/null)" == 10 ]]; then
  rm -f "$J.pre-panel-retry"; echo "panel-jail-retry: panel jail maxretry 10"
else
  cat "$J.pre-panel-retry" > "$J"; rm -f "$J.pre-panel-retry"; fail2ban-client reload panel >/dev/null 2>&1 || true
  echo "panel-jail-retry: reload failed — previous jail.local restored"; exit 1
fi
