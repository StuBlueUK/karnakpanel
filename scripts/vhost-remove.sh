#!/usr/bin/env bash
# vhost-remove.sh <domain> <username> — remove vhost, OLS registration, Exim maps,
# and the domain dir /home/<user>/<domain> (docroot + everything beside it).
# SAFE: only that exact path (both names regex-checked), only a real dir, never a link.
# Missing pieces are skipped.
set -euo pipefail
D="${1:?}"; U="${2:?}"
[[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad domain: $D"; exit 1; }
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad user: $U"; exit 1; }
ESC="${D//./\\.}"
HTTPD=/usr/local/lsws/conf/httpd_config.conf

# 1. OLS registration: drop virtualhost block + EVERY map line that routes
# here — apex (`map D D`) plus preview/parked maps (`map D <host>`). Orphan
# map lines pointing at a deleted vhost break nothing today but rot the config.
if [[ -f "$HTTPD" ]]; then
  sed -i "/^virtualhost $ESC {/,/^}/d" "$HTTPD"
  sed -i "/^  map $ESC /d" "$HTTPD"
fi
rm -rf "/usr/local/lsws/conf/vhosts/$D"

# drop the user's PHP-FPM pool only if none of their other vhosts remain
# (pools are per-user, shared across all of a user's domains)
if ! grep -rl "docRoot.*/home/$U/" /usr/local/lsws/conf/vhosts/*/vhost.conf 2>/dev/null | grep -q .; then
  "$(dirname "$0")/php-pool-remove.sh" "$U" 2>/dev/null || true
fi

# 2. Exim maps
sed -i "/^$ESC: ok$/d" /etc/exim4/virtual/domains 2>/dev/null || true
sed -i "/@$ESC: /d" /etc/exim4/virtual/aliases 2>/dev/null || true
sed -i "/@$ESC: /d" /etc/exim4/virtual/maildirs 2>/dev/null || true
sed -i "/@$ESC: /d" /etc/exim4/virtual/forwarders 2>/dev/null || true
sed -i "/^$ESC: /d" /etc/exim4/virtual/remote 2>/dev/null || true
sed -i "/^$ESC: /d" /etc/exim4/virtual/dkim 2>/dev/null || true
rm -f /etc/exim4/dkim/"$D".default.pem 2>/dev/null || true
# 2a. DMARC/TLS-RPT report intake (map lines + spool maildir)
sed -i "/@$ESC: /d" /etc/exim4/virtual/reports 2>/dev/null || true
rm -rf "/var/spool/karnak-reports/$D" 2>/dev/null || true
# 2b. virtual Maildirs for the whole domain (boxes die with their domain)
rm -rf "/var/mail/virtual/$D" 2>/dev/null || true
# 2c. vacation-reply data (autoreply-sync only rebuilds enabled rows — a whole
# domain vanishing would otherwise leave answer-files behind)
sed -i "/@$ESC: /d" /etc/exim4/virtual/autoreplies 2>/dev/null || true
rm -rf "/var/lib/skypanel/autoreply/$D" 2>/dev/null || true
# 2e. sieve filters + mailing lists lived here (sync scripts rebuild from the
# DB, but the DB rows die with the domain — drop the generated files now).
# Paths come from our own map files, still shape-checked before any rm.
sed -i "/@$ESC: /d" /etc/exim4/virtual/filtered 2>/dev/null || true
rm -rf "/var/lib/skypanel/sieve/$D" 2>/dev/null || true
while IFS= read -r line; do
  MP="${line#*: }"
  [[ "$MP" =~ ^/etc/exim4/virtual/listmembers/[a-z0-9.-]+\.[a-z]{2,}/[a-z0-9._-]+$ ]] || continue
  rm -f "$MP"
done < <(grep "^[^:]*@$ESC: " /etc/exim4/virtual/lists 2>/dev/null || true)
sed -i "/@$ESC: /d" /etc/exim4/virtual/lists 2>/dev/null || true
sed -i "/@$ESC: /d" /etc/exim4/virtual/listtargets 2>/dev/null || true
find /etc/exim4/virtual/listmembers -mindepth 1 -type d -empty -delete 2>/dev/null || true
# 2d. domain logs (history goes with the domain)
rm -f "/var/log/karnakpanel/$D.access.log" "/var/log/karnakpanel/$D.error.log" 2>/dev/null || true

# 3. domain dir — names are regex-checked above; must be a real dir, not a link
DD="/home/$U/$D"
if [[ "$D" != *..* && -d "$DD" && ! -L "$DD" && ! -L "/home/$U" ]]; then
  rm -rf -- "$DD"
  echo "removed docroot /home/$U/$D"
else
  echo "docroot /home/$U/$D absent — left alone"
fi

/usr/local/lsws/bin/lswsctrl restart >/dev/null 2>&1 || systemctl restart lshttpd 2>/dev/null || true
echo "vhost $D removed"
