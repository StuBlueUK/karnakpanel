#!/usr/bin/env bash
# ClamAV antivirus for mail (OPTIONAL — heavy).
# Memory: clamd holds ~0.5-1GB RSS. Do NOT install under 2GB total RAM;
# comfortable at 4GB+. The Exim malware ACL is always present but fails OPEN
# (defer_ok) when clamd is absent, so skipping this is safe.
# Opt in: WITH_CLAMAV=1 bash install.sh  (or answer yes at the prompt)
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
TOTAL_MB="$(free -m | awk '/^Mem:/{print $2}')"
if [[ "$TOTAL_MB" -lt 1800 ]]; then
  echo "REFUSING: ${TOTAL_MB}MB RAM < 1800MB minimum for ClamAV (resize first)"
  exit 1
fi
apt-get install -y clamav clamav-daemon
# first signature download is ~200MB and slow — foreground it so mail isn't
# unprotected behind a stale/empty database
systemctl stop clamav-freshclam 2>/dev/null || true
freshclam 2>&1 | tail -2 || true
systemctl enable clamav-daemon clamav-freshclam 2>/dev/null || true
systemctl restart clamav-daemon 2>&1 | tail -1 || true
systemctl start clamav-freshclam 2>/dev/null || true
# Exim hands clamd files under /var/spool/exim4: without spool access the
# malware ACL fails OPEN (defer_ok) and nothing is ever scanned. Both halves:
adduser clamav Debian-exim 2>/dev/null || true
mkdir -p /etc/apparmor.d/local
printf '# KarnakPanel: let clamd traverse+lock exim scan spool\n/var/spool/exim4/ r,\n/var/spool/exim4/** rwk,\n' > /etc/apparmor.d/local/usr.sbin.clamd
apparmor_parser -r /etc/apparmor.d/usr.sbin.clamd 2>/dev/null || true
systemctl restart clamav-daemon 2>&1 | tail -1 || true
echo "ClamAV ready (clamd + freshclam auto-update)"
