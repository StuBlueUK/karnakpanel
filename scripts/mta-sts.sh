#!/usr/bin/env bash
# mta-sts.sh <domain> <owner> <action> [mode] [mx-csv]
# MTA-STS (RFC 8461) policy hosting for one domain. DNS rows (_mta-sts TXT,
# mta-sts A, _smtp._tls TXT) are owned by the panel (dns_records + apply);
# this script owns the web side:
#   write <testing|enforce|none> <mx1,mx2>  policy file + mta-sts.<d> host
#   remove                                  policy file (host kept, see below)
#   check-policy                            HTTPS fetch, cert VERIFIED (what
#                                           senders do) — prints policy mode
#   check-mx <mx>                           STARTTLS on :25 with a cert valid
#                                           for <mx> (enforce gate, one MX per
#                                           call so each gets its own 15s window)
# Policy lives in the docroot (.well-known/mta-sts.txt) and is written AS the
# owner (customer-writable tree: never root-write through planted symlinks).
# The mta-sts.<d> name joins the domain's own vhost (comma vhAliases + explicit
# `map <vhost> <host>` on both listeners) so the domain's LE cert serves it
# once ssl-issue.sh adds the SAN. IDEMPOTENT, fails loudly.
set -euo pipefail
D="${1:?}"; U="${2:?}"; ACT="${3:?}"
[[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad domain"; exit 1; }
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad owner"; exit 1; }
H="mta-sts.$D"
VH="/usr/local/lsws/conf/vhosts/$D/vhost.conf"
HTTPD=/usr/local/lsws/conf/httpd_config.conf
DOC="/home/$U/$D/public_html"
ESC="${D//./\\.}"; HESC="${H//./\\.}"
IP="$(hostname -I 2>/dev/null | awk '{print $1}')"

case "$ACT" in
write)
  MODE="${4:?mode}"; MXS="${5:-}"
  [[ "$MODE" == testing || "$MODE" == enforce || "$MODE" == none ]] || { echo "mode must be testing|enforce|none"; exit 1; }
  [[ -f "$VH" ]] || { echo "no site for $D (add the domain first)"; exit 1; }
  [[ -d "$DOC" ]] || { echo "no docroot for $D"; exit 1; }
  # mx lines: every MX the domain publishes (an unlisted backup MX is refused
  # by enforcing senders). none-mode policies need none.
  POL="version: STSv1"$'\r\n'"mode: $MODE"$'\r\n'
  N=0
  if [[ -n "$MXS" ]]; then
    IFS=',' read -r -a ARR <<< "$MXS"
    for m in "${ARR[@]}"; do
      [[ "$m" =~ ^(\*\.)?[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad mx: $m"; exit 1; }
      POL+="mx: $m"$'\r\n'; N=$((N+1))
    done
  fi
  [[ "$MODE" == none || $N -gt 0 ]] || { echo "refusing: $MODE policy needs at least one MX"; exit 1; }
  # testing/none: 1 day (mistakes age out fast); enforce: 1 week
  AGE=86400; [[ "$MODE" == enforce ]] && AGE=604800
  POL+="max_age: $AGE"$'\r\n'
  TMP="$(mktemp)"; trap 'rm -f "$TMP"' EXIT
  printf '%s' "$POL" > "$TMP"; chmod 644 "$TMP"
  for R in "$DOC" "/home/$U/$D/maintenance"; do
    [[ -d "$R" ]] || continue
    runuser -u "$U" -- mkdir -p "$R/.well-known"
    runuser -u "$U" -- cp -f "$TMP" "$R/.well-known/mta-sts.txt"
    runuser -u "$U" -- chmod 644 "$R/.well-known/mta-sts.txt"
  done
  # host alias (comma-separated vhAliases — the space form is silently wrong)
  CHANGED=0
  if ! grep -E '^vhAliases' "$VH" | grep -qE "(^vhAliases[[:space:]]+|,)$HESC(,|[[:space:]]*$)"; then
    if grep -q '^vhAliases' "$VH"; then
      sed -i "s/^vhAliases\(.*\)$/vhAliases\1,$H/" "$VH"
    else
      sed -i "s/^vhDomain\(.*\)/vhDomain\1\nvhAliases                 $H/" "$VH"
    fi
    CHANGED=1
  fi
  for L in Default SSL443; do
    grep -q "^listener $L" "$HTTPD" 2>/dev/null || continue
    if ! sed -n "/^listener $L/,/^}/p" "$HTTPD" | grep -q "^  map $ESC $HESC\$"; then
      sed -i "/^listener $L/a\\  map $D $H" "$HTTPD"; CHANGED=1
    fi
  done
  [[ $CHANGED == 1 ]] && { /usr/local/lsws/bin/lswsctrl restart 2>&1 | tail -1 || true; }
  echo "policy $MODE written for $D ($N mx, max_age $AGE)"
  ;;
remove)
  # policy file only. The mta-sts.<d> alias + listener maps (and the panel's
  # A record) STAY: once the name is a SAN on the domain's LE lineage,
  # dropping it makes `certbot renew` fail HTTP-01 for that name — and with
  # it the whole cert. A host serving a 404 for the policy is harmless; the
  # _mta-sts TXT going away is what tells senders there is no policy.
  # (vhost-remove.sh clears the alias/maps with the domain itself.)
  for R in "$DOC" "/home/$U/$D/maintenance"; do
    [[ -d "$R/.well-known" ]] && runuser -u "$U" -- rm -f "$R/.well-known/mta-sts.txt" || true
  done
  echo "MTA-STS policy file removed for $D (policy host kept: it may be on the certificate)"
  ;;
check-policy)
  # exactly what a sending MTA does: HTTPS to mta-sts.<d>, cert must verify
  # for that name. --resolve pins this box so local DNS state can't lie.
  OUT="$(curl -fsS --max-time 5 --resolve "$H:443:$IP" "https://$H/.well-known/mta-sts.txt" 2>&1)" || {
    echo "policy=fail https://$H/.well-known/mta-sts.txt: $(grep -m1 '^curl:' <<< "$OUT" || echo "$OUT" | head -1) (re-issue SSL so $H is on the certificate)"; exit 1; }
  grep -q '^version: STSv1' <<< "$OUT" || { echo "policy=fail served file is not an STSv1 policy"; exit 1; }
  echo "policy=ok mode=$(grep -oE '^mode: [a-z]+' <<< "$OUT" | cut -d' ' -f2)"
  # public view: senders resolve mta-sts.<d> through public DNS
  PUB="$(dig +short +time=2 +tries=1 A "$H" @8.8.8.8 2>/dev/null | tail -1 || true)"
  if [[ "$PUB" == "$IP" ]]; then echo "dns=ok $H resolves here publicly"; else echo "dns=fail $H resolves publicly to '${PUB:-nothing}' (not $IP)"; exit 1; fi
  ;;
check-mx)
  MX="${4:?mx}"
  [[ "$MX" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad mx: $MX"; exit 1; }
  if timeout 6 openssl s_client -starttls smtp -connect "$MX:25" -servername "$MX" \
       -verify_hostname "$MX" -verify_return_error </dev/null >/dev/null 2>&1; then
    echo "mx=ok $MX presents a valid certificate for its name"
  else
    echo "mx=fail $MX: STARTTLS on port 25 did not present a valid certificate for $MX (issue SSL for the domain so mail.<domain> is covered)"; exit 1
  fi
  ;;
*) echo "action must be write|remove|check-policy|check-mx"; exit 1 ;;
esac
