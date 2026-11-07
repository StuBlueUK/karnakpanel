#!/usr/bin/env bash
# common: base packages, users, dirs
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
# fresh VPS images run unattended-upgrades on first boot, holding the dpkg
# lock and killing the install mid-apt. Stop it for the run + make every later
# apt call wait up to 10 min for the lock instead of failing instantly.
systemctl stop unattended-upgrades 2>/dev/null || true
printf 'DPkg::Lock::Timeout "600";\n' > /etc/apt/apt.conf.d/99karnakpanel-lock
apt-get update
# rsyslog: Debian 13 images log to the journal only — no auth.log/mail.log/
# syslog, which the fail2ban sshd jail and log-read.sh read (Ubuntu ships it).
# gpgv: the Roundcube signature check (roundcube-upstream.sh); not always in
# minimal Debian images.
apt-get install -y curl wget git zip unzip bzip2 openssl sudo cron ufw certbot whois bind9-dnsutils htop net-tools lsof acl rclone rsyslog gpgv ca-certificates python3
. /etc/os-release
# add-apt-repository (Ubuntu PPA path in php-fpm.sh); Debian uses a plain source.
[[ "${ID:-}" == "ubuntu" ]] && apt-get install -y software-properties-common
# automatic security updates, as every Ubuntu image has them: minimal Debian
# images may lack the package or the periodic switch (an existing file is the
# owner's choice and is kept). Debian's stock 50unattended-upgrades covers
# Debian point releases + Debian-Security only (no sury/LiteSpeed repos).
if [[ "${ID:-}" == "debian" ]]; then
  apt-get install -y unattended-upgrades
  [[ -f /etc/apt/apt.conf.d/20auto-upgrades ]] || \
    printf 'APT::Periodic::Update-Package-Lists "1";\nAPT::Periodic::Unattended-Upgrade "1";\n' > /etc/apt/apt.conf.d/20auto-upgrades
fi
systemctl enable --now rsyslog 2>/dev/null || true
mkdir -p /usr/local/skypanel /var/log/karnakpanel /var/lib/skypanel/sessions /backup/skypanel /etc/bind/zones
chmod 700 /backup/skypanel
# pin release upgrades OFF: only 24.04 (and existing 22.04) are supported — a do-release-upgrade
# to 26.04 (lacks the PHP packages) would brick the stack. Upgrades happen by
# migrating, never by bumping in place. Three layers (accidents, not sabotage —
# root can undo all of this, and the integrity watcher pages on pin changes):
mkdir -p /etc/update-manager
if [[ -f /etc/update-manager/release-upgrades ]]; then
  sed -i 's/^Prompt=.*/Prompt=never/' /etc/update-manager/release-upgrades
else
  printf '[DEFAULT]\nPrompt=never\n' > /etc/update-manager/release-upgrades
fi
grep -q '^Prompt=never' /etc/update-manager/release-upgrades || printf '\nPrompt=never\n' >> /etc/update-manager/release-upgrades
# 1. the MOTD "new release available — run do-release-upgrade" tease: drop any
# already-computed cache and disable the motd hook (update-motd only runs
# executable hooks; package updates may restore +x — re-runs re-disable).
rm -f /var/lib/ubuntu-release-upgrader/release-upgrade-available
chmod -x /etc/update-motd.d/91-release-upgrade 2>/dev/null || true
# 2. the command itself: shadow it with a refusal shim (PATH finds
# /usr/local/bin first; a deliberate full-path call still works, as it should).
cat > /usr/local/bin/do-release-upgrade <<'EOF'
#!/usr/bin/env bash
echo "REFUSED: in-place release upgrades break the KarnakPanel stack (the PHP packages don't exist on newer Ubuntu)." >&2
echo "Move OS versions by migrating to a fresh box, never by bumping this one in place. (Panel Update page says the same.)" >&2
exit 1
EOF
chmod +x /usr/local/bin/do-release-upgrade
id skypanel >/dev/null 2>&1 || useradd -r -s /bin/false skypanel || true
