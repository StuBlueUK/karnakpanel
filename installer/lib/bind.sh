#!/usr/bin/env bash
# BIND9 authoritative
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
apt-get install -y bind9 bind9-utils
mkdir -p /etc/bind/zones
cp -f "$(cd "$(dirname "$0")/../.." && pwd)/installer/configs/bind/named.conf.options" /etc/bind/named.conf.options
grep -q "zones.skypanel" /etc/bind/named.conf.local 2>/dev/null || echo 'include "/etc/bind/zones.skypanel";' >> /etc/bind/named.conf.local
touch /etc/bind/zones.skypanel
named-checkconf
# DNSSEC prerequisites (inline-signing writes .signed/.jnl next to zone files;
# the keymgr writes keys to the key dir — both need DAC + AppArmor):
# NOTE: Ubuntu confines named (AppArmor) with /etc/bind read-only, and the key
# dir MUST be writable — /var/cache/bind/keys is the compliant path.
chown bind:bind /etc/bind/zones 2>/dev/null || chown named:named /etc/bind/zones 2>/dev/null || true
chmod 775 /etc/bind/zones
mkdir -p /var/cache/bind/keys
chown bind:bind /var/cache/bind/keys 2>/dev/null || chown named:named /var/cache/bind/keys 2>/dev/null || true
mkdir -p /etc/apparmor.d/local
printf '# KarnakPanel: inline-signing writes .signed/.jnl next to zone files\n/etc/bind/zones/** rwk,\n# KarnakPanel: dnssec-policy key management needs file locks\n/var/cache/bind/** lrwk,\n' > /etc/apparmor.d/local/usr.sbin.named
apparmor_parser -r /etc/apparmor.d/usr.sbin.named 2>/dev/null || true
# real unit is named.service on modern Ubuntu (bind9.service is an alias)
systemctl enable named 2>/dev/null || systemctl enable bind9 2>/dev/null || true
systemctl restart named || systemctl restart bind9 || true
ufw allow 53 || true
# Local mail/web need to see our own authoritative zones: resolve via local BIND first.
# demotest-style domains only exist here; external resolvers return foreign/wrong answers.
if systemctl is-active --quiet systemd-resolved 2>/dev/null; then
  mkdir -p /etc/systemd/resolved.conf.d
  printf '[Resolve]\nDNS=127.0.0.1\nDomains=~.\n' > /etc/systemd/resolved.conf.d/skypanel.conf
  systemctl restart systemd-resolved || true
  # point the static resolv.conf at the stub so libc/exim/dig use local zones first
  if [[ ! -L /etc/resolv.conf ]]; then
    cp /etc/resolv.conf /etc/resolv.conf.bak-pre-skypanel 2>/dev/null || true
    ln -sf /run/systemd/resolve/stub-resolv.conf /etc/resolv.conf
  fi
fi
