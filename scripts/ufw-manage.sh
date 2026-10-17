#!/usr/bin/env bash
# ufw-manage.sh <status|allow|deny|block|delete> [args] — panel-safe UFW control.
# GUARDED: SSH (port 22) rules can never be deleted (lockout protection);
# ports strictly validated; IPs must parse; rule numbers numeric only.
set -euo pipefail
A="${1:?}"; shift || true
port_ok() { [[ "$1" =~ ^[0-9]{1,5}$ && "$1" -ge 1 && "$1" -le 65535 ]]; }
proto_ok() { [[ "$1" == tcp || "$1" == udp ]]; }
case "$A" in
  status)
    ufw status numbered 2>&1
    ;;
  allow|deny)
    P="${1:?}"; PROTO="${2:-tcp}"
    port_ok "$P" || { echo "bad port"; exit 1; }
    proto_ok "$PROTO" || { echo "want tcp|udp"; exit 1; }
    if [[ "$A" == deny && "$P" == 22 ]]; then echo "REFUSED: port 22 stays open (lockout protection)"; exit 1; fi
    ufw "$A" "$P/$PROTO" 2>&1 | tail -1
    ;;
  block)
    IP="${1:?}"
    # strict IP check without depending on python/php here
    [[ "$IP" =~ ^[0-9a-fA-F.:]{3,45}$ ]] || { echo "bad ip"; exit 1; }
    python3 -c "import ipaddress,sys; ipaddress.ip_address(sys.argv[1])" "$IP" 2>/dev/null || { echo "bad ip"; exit 1; }
    ufw deny from "$IP" 2>&1 | tail -1
    ;;
  delete)
    N="${1:?}"
    [[ "$N" =~ ^[0-9]{1,3}$ ]] || { echo "bad rule number"; exit 1; }
    LINE="$(ufw status numbered 2>/dev/null | grep -E "^\[\s*$N\]")"
    [[ -n "$LINE" ]] || { echo "no such rule"; exit 1; }
    TO="$(echo "$LINE" | sed -E 's/^\[[ 0-9]+\] *([^ ]+).*/\1/')"
    if [[ "$TO" =~ ^22(/|$) ]]; then echo "REFUSED: rule $N guards SSH — deleting it locks you out"; exit 1; fi
    ufw --force delete "$N" 2>&1 | tail -1
    ;;
  *) echo "usage: status|allow|deny|block|delete"; exit 1 ;;
esac
