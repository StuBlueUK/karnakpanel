#!/usr/bin/env bash
# mail-autoconfig.sh <domain> <owner> — drop client-setup XML into the docroot
# so Thunderbird/Outlook/Apple Mail/K-9 configure from email+password alone.
# No DNS, certs or vhosts needed: both clients fall back to domain-root paths
# (TB: /.well-known/autoconfig/mail/config-v1.1.xml, Outlook:
# /autodiscover/autodiscover.xml). Server name = the domain itself (resolves,
# cert-covered). IMAP-only (no POP3 on this stack) + SMTP submission.
# Idempotent: rewrites the two files. Runs at domain create; backfill by hand.
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/as-user.sh"   # the docroot is the customer's: write it as them
D="${1:?}"; U="${2:?}"; MODE="${3:-write}"
[[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad domain"; exit 1; }
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad owner"; exit 1; }
DOC="/home/$U/$D/public_html"
[[ -d "$DOC" ]] || { echo "no docroot (add the domain first)"; exit 1; }
if [[ "$MODE" == remove ]]; then
  # remote-handled domains must not advertise our IMAP/SMTP to mail clients
  as_user "$U" rm -f -- "$DOC/.well-known/autoconfig/mail/config-v1.1.xml" "$DOC/autodiscover/autodiscover.xml"
  echo "autoconfig removed for $D (remote mail handling)"
  exit 0
fi
[[ "$MODE" == write ]] || { echo "mode must be write|remove"; exit 1; }
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT   # built root-only, placed as the owner
cat > "$T/tb.xml" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<clientConfig version="1.1">
  <emailProvider id="$D">
    <domain>$D</domain>
    <displayName>$D mail</displayName>
    <displayShortName>$D</displayShortName>
    <incomingServer type="imap">
      <hostname>$D</hostname>
      <port>993</port>
      <socketType>SSL</socketType>
      <username>%EMAILADDRESS%</username>
      <authentication>password-cleartext</authentication>
    </incomingServer>
    <outgoingServer type="smtp">
      <hostname>$D</hostname>
      <port>587</port>
      <socketType>STARTTLS</socketType>
      <username>%EMAILADDRESS%</username>
      <authentication>password-cleartext</authentication>
    </outgoingServer>
    <outgoingServer type="smtp">
      <hostname>$D</hostname>
      <port>465</port>
      <socketType>SSL</socketType>
      <username>%EMAILADDRESS%</username>
      <authentication>password-cleartext</authentication>
    </outgoingServer>
  </emailProvider>
</clientConfig>
EOF
cat > "$T/ol.xml" <<'XEOF'
<?xml version="1.0" encoding="utf-8" ?>
<Autodiscover xmlns="http://schemas.microsoft.com/exchange/autodiscover/responseschema/2006">
  <Response xmlns="http://schemas.microsoft.com/exchange/autodiscover/outlook/responseschema/2006a">
    <Account>
      <AccountType>email</AccountType>
      <Action>settings</Action>
XEOF
# domain varies per site — append with the real values (heredoc above is static)
cat >> "$T/ol.xml" <<EOF
      <Protocol>
        <Type>IMAP</Type>
        <Server>$D</Server>
        <Port>993</Port>
        <LoginName></LoginName>
        <SSL>on</SSL>
        <AuthRequired>on</AuthRequired>
      </Protocol>
      <Protocol>
        <Type>SMTP</Type>
        <Server>$D</Server>
        <Port>587</Port>
        <LoginName></LoginName>
        <SSL>off</SSL>
        <AuthRequired>on</AuthRequired>
      </Protocol>
      <Protocol>
        <Type>SMTP</Type>
        <Server>$D</Server>
        <Port>465</Port>
        <LoginName></LoginName>
        <SSL>on</SSL>
        <AuthRequired>on</AuthRequired>
      </Protocol>
    </Account>
  </Response>
</Autodiscover>
EOF
as_user "$U" mkdir -p -- "$DOC/.well-known/autoconfig/mail" "$DOC/autodiscover" || { echo "can't create autoconfig dirs"; exit 1; }
user_put "$U" "$DOC/.well-known/autoconfig/mail/config-v1.1.xml" 644 < "$T/tb.xml" || { echo "autoconfig write failed"; exit 1; }
user_put "$U" "$DOC/autodiscover/autodiscover.xml" 644 < "$T/ol.xml" || { echo "autodiscover write failed"; exit 1; }
echo "autoconfig written for $D"
