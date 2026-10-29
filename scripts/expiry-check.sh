#!/usr/bin/env bash
# expiry-check.sh — daily domain + SSL expiry watch. Alerts (mail + panel
# notify to the domain OWNER) when either drops to 30/14/7/1 days, once per
# band; above 30d the state clears silently. Caches both dates on domains for
# the Domains page. Unparseable/unreachable entries are skipped, never alerted.
# Test hooks: EXPIRY_STATE_DIR + EXPIRY_ALERT_TO (space-separated override).
set -uo pipefail
LOG="/var/log/karnakpanel/panel.log"
STATE_BASE="${EXPIRY_STATE_DIR:-/var/lib/skypanel/expiry}"
mkdir -p "$STATE_BASE" 2>/dev/null || true
HOST="$(hostname -f 2>/dev/null || hostname)"
PASS="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
[[ -n "$PASS" ]] || { echo "no panel db pass"; exit 1; }
# optional scope: expiry-check.sh <domain> refreshes just one (used at
# domain creation so the panel isn't blank until the nightly run)
ONLY="${1:-}"
if [[ -n "$ONLY" ]]; then
  [[ "$ONLY" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad domain"; exit 1; }
fi
notify_user() {
  local uid="$1" msg="$2"
  MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "INSERT INTO notifications(user_id,type,title) SELECT $uid,'expiry','$msg' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM notifications WHERE user_id=$uid AND type='expiry' AND title='$msg' AND created_at > (NOW() - INTERVAL 1 HOUR));" >>/var/log/karnakpanel/panel.log 2>&1 || echo "$(date -Is) expiry-check: notify insert failed" >>/var/log/karnakpanel/panel.log
}
recipients_for() {
  local owner_email="$1" out=""
  if [[ "$owner_email" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[a-z]{2,}$ && ! "$owner_email" =~ @localhost$ ]]; then out="$owner_email"; fi
  if [[ -z "$out" ]]; then
    out="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT value FROM settings WHERE name='alert_email';" 2>/dev/null | grep -E '^[^@[:space:]]+@[^@[:space:]]+\.[a-z]{2,}$' | grep -vE '@localhost$' | head -1 || true)"
  fi
  printf '%s' "$out"
}
send_mail() {
  local to="$1" subject="$2" body="$3"
  [[ -n "${to// }" ]] || { echo "$(date -Is) expiry: no routable recipient — logged only: $subject" >> "$LOG"; return 0; }
  /usr/sbin/sendmail -t 2>/dev/null <<EOF || echo "$(date -Is) expiry: sendmail to $to failed" >> "$LOG"
To: $to
From: KarnakPanel Expiry <expiry@$HOST>
Auto-Submitted: auto-generated
Subject: $subject

$body
EOF
}
# WHOIS expiry is registrar-format soup — try the common labels in order,
# first parseable date wins.
# Registrar data lives on the PARENT (whois forum.example.com returns junk) —
# strip to the registrable domain, honoring two-level suffixes (co.uk etc.).
parent_domain() {
  local D="$1" two="co.uk org.uk me.uk ltd.uk plc.uk com.au net.au org.au co.nz co.za com.br com.mx co.jp co.in com.ar"
  local last2; last2="$(echo "$D" | rev | cut -d. -f1-2 | rev)"
  for sfx in $two; do
    if [[ "$D" == *".$sfx" ]]; then
      echo "$D" | rev | cut -d. -f1-3 | rev
      return 0
    fi
  done
  echo "$last2"
}
whois_expiry() {
  local line cand
  while IFS= read -r line; do
    cand="$(echo "$line" | grep -oiE '[0-9]{4}-[0-9]{2}-[0-9]{2}|[0-9]{2}-[A-Za-z]{3}-[0-9]{4}|[A-Za-z]+ [0-9]{1,2},? [0-9]{4}|[0-9]{1,2}\.[0-9]{1,2}\.[0-9]{4}' | head -1 || true)"
    [[ -n "$cand" ]] || continue
    date -d "$cand" +%F 2>/dev/null && return 0
  done < <(timeout 15 whois "$1" 2>/dev/null | grep -iE 'expir|paid-till|valid until' | head -8)
  return 1
}
ssl_expiry() {
  local end cert
  cert="$(timeout 10 openssl s_client -connect "$1:443" -servername "$1" </dev/null 2>/dev/null | openssl x509 2>/dev/null || true)"
  [[ -n "$cert" ]] || return 1
  # hostname match: without this, a domain with NO cert records the fallback
  # (default/self-signed) cert's far-future date as its own SSL expiry.
  # -checkhost prints a verdict but always exits 0: match on its text
  # ("does match" vs "does NOT match" — OpenSSL 3.0 wording, both LTS).
  echo "$cert" | openssl x509 -noout -checkhost "$1" 2>&1 | grep -q "does match certificate" || return 1
  end="$(echo "$cert" | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2 || true)"
  [[ -n "$end" ]] || return 1
  date -d "$end" +%F 2>/dev/null || return 1
}
NOW="$(date +%s)"
COUNT=0
while IFS=$'\t' read -r DID D OUID UEMAIL; do
  [[ -n "${DID:-}" && "$DID" =~ ^[0-9]+$ && "$OUID" =~ ^[0-9]+$ ]] || continue
  [[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || continue
  DEXP=""; SEXP=""
  DEXP="$(whois_expiry "$(parent_domain "$D")" || true)"
  SEXP="$(ssl_expiry "$D" || true)"
  if [[ -n "$DEXP" ]]; then MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "UPDATE domains SET dom_expires='$DEXP' WHERE id=$DID;" 2>/dev/null || true; fi
  if [[ -n "$SEXP" ]]; then MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "UPDATE domains SET ssl_expires='$SEXP' WHERE id=$DID;" 2>/dev/null || true; fi
  MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "UPDATE domains SET expiry_checked_at=NOW() WHERE id=$DID;" 2>/dev/null || true
  for KIND in "dom:$DEXP" "ssl:$SEXP"; do
    E="${KIND#*:}"; K="${KIND%%:*}"
    [[ -n "$E" ]] || continue
    ETS="$(date -d "$E" +%s 2>/dev/null || echo 0)"
    DAYS=$(( (ETS - NOW) / 86400 ))
    BAND=""
    for b in 1 7 14 30; do if (( DAYS <= b )); then BAND="$b"; break; fi; done
    SF="$STATE_BASE/$K-$DID"
    if [[ -z "$BAND" ]]; then rm -f "$SF"; continue; fi
    if [[ ! -f "$SF" ]] || [[ "$(cat "$SF" 2>/dev/null)" != "$BAND" ]]; then
      echo "$BAND" > "$SF"
      WHAT="domain registration"; [[ "$K" == "ssl" ]] && WHAT="SSL certificate"
      TO="${EXPIRY_ALERT_TO:-$(recipients_for "$UEMAIL")}"
      echo "$(date -Is) expiry: $D $WHAT expires in $DAYS days" >> "$LOG"
      notify_user "$OUID" "$D $WHAT expires in $DAYS days"
      send_mail "$TO" "[KarnakPanel $HOST] $D $WHAT expires in $DAYS days" "The $WHAT for $D expires on $E ($DAYS days). Renew it, then this notice clears automatically."
    fi
  done
  COUNT=$((COUNT+1))
  sleep 2  # whois servers rate-limit; dozens of domains stay polite
done < <(if [[ -n "$ONLY" ]]; then MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT d.id, d.domain, d.user_id, u.email FROM domains d JOIN users u ON u.id=d.user_id WHERE d.domain='$ONLY' LIMIT 1;"; else MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT d.id, d.domain, d.user_id, u.email FROM domains d JOIN users u ON u.id=d.user_id;"; fi)
echo "expiry check done, $COUNT domain(s)"
