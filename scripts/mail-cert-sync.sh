#!/usr/bin/env bash
# mail-cert-sync.sh <domain> — point Dovecot + Exim at the domain's Let's
# Encrypt cert so mail clients (Thunderbird autodiscover etc.) stop hitting
# the self-signed IP cert. Single shared pair in /etc/ssl/skypanel/ (multi-
# domain SNI is future work — today only domains with mailboxes matter and
# the panel autoconfig advertises the domain itself). Records the domain for
# the renew hook. Verifies both ports present the new cert afterwards.
set -euo pipefail
D="${1:?domain}"
[[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad domain"; exit 1; }
LIVE="/etc/letsencrypt/live/$D"
[[ -f "$LIVE/fullchain.pem" && -f "$LIVE/privkey.pem" ]] || { echo "no LE cert for $D (issue one first)"; exit 1; }
SSLDIR="/etc/ssl/skypanel"
cp -a "$SSLDIR/cert.pem" "$SSLDIR/cert.pem.bak-$(date +%Y%m%d)" 2>/dev/null || true
cp -a "$SSLDIR/key.pem" "$SSLDIR/key.pem.bak-$(date +%Y%m%d)" 2>/dev/null || true
cp -f "$LIVE/fullchain.pem" "$SSLDIR/cert.pem"
cp -f "$LIVE/privkey.pem" "$SSLDIR/key.pem"
chmod 644 "$SSLDIR/cert.pem"
chmod 640 "$SSLDIR/key.pem"
chgrp ssl-cert "$SSLDIR/key.pem" 2>/dev/null || chown root:Debian-exim "$SSLDIR/key.pem" 2>/dev/null || true
echo "$D" > "$SSLDIR/DOMAIN"
systemctl reload dovecot 2>/dev/null || systemctl restart dovecot
systemctl reload exim4 2>/dev/null || systemctl restart exim4
sleep 3
OK=1
for spec in "993" "465"; do
  SUBJ="$(echo | timeout 10 openssl s_client -connect "127.0.0.1:$spec" -servername "$D" 2>/dev/null | openssl x509 -noout -subject 2>/dev/null || true)"
  echo "port $spec: $SUBJ"
  echo "$SUBJ" | grep -qF "$D" || OK=0
done
[[ "$OK" == "1" ]] && echo "mail now on LE cert for $D" || { echo "VERIFY FAILED — check ports"; exit 1; }
