#!/usr/bin/env bash
# karnakpanel-install.sh <panel-domain> [admin-email] — one-file bootstrap for
# a fresh VPS. Downloads the matching release tarball, verifies its checksum,
# and launches the full installer. Run as root:
#   wget https://karnakpanel.com/downloads/karnakpanel-install.sh
#   sudo bash karnakpanel-install.sh panel.example.com admin@example.com
#
# Requirements (checked below, warned not enforced): fresh Ubuntu 24.04 LTS or Debian 13,
# 2GB RAM, 20GB disk, ports 22/80/443/8090 reachable, DNS for the panel domain
# already pointing here (needed for the Let's Encrypt step later in the panel).
set -euo pipefail
PANEL_DOMAIN="${1:-}"
ADMIN_EMAIL="${2:-admin@localhost}"
BASE_URL="${KARNAK_BASE_URL:-https://karnakpanel.com/downloads}"
PANEL_DOMAIN="${PANEL_DOMAIN,,}"; ADMIN_EMAIL="${ADMIN_EMAIL,,}"
[[ $EUID -eq 0 ]] || { echo "Run as root: sudo bash $0 <panel-domain> [admin-email]"; exit 1; }
# Placeholder guard: the website's copy-paste example uses panel.example.com /
# admin@example.com — the installer adds the hostname to the panel, so those
# must be replaced with real values before anything downloads.
_is_example_domain(){ case "${1,,}" in
  ""|example.com|example.net|example.org|*.example.com|*.example.net|*.example.org|*.localhost|*.local|*.test|*.invalid) return 0;;
  *) return 1;; esac; }
_is_example_email(){ case "${1,,}" in
  ""|admin@localhost|*@localhost|*@example.com|*@example.net|*@example.org) return 0;;
  *) return 1;; esac; }
if _is_example_domain "$PANEL_DOMAIN"; then
  [[ -n "$PANEL_DOMAIN" ]] && echo "Ignoring placeholder panel domain '$PANEL_DOMAIN' — the installer adds this hostname to the panel, it must be real."
  if [[ -t 0 ]]; then
    while _is_example_domain "$PANEL_DOMAIN"; do
      read -r -p "Panel domain (public FQDN pointing here, or server IP): " PANEL_DOMAIN || PANEL_DOMAIN=""
      PANEL_DOMAIN="${PANEL_DOMAIN,,}"
      if [[ -n "$PANEL_DOMAIN" ]] && ! _is_example_domain "$PANEL_DOMAIN" \
         && ! [[ "$PANEL_DOMAIN" =~ ^[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ || "$PANEL_DOMAIN" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]]; then
        echo "'$PANEL_DOMAIN' doesn't look like a domain or IP — try again."
        PANEL_DOMAIN=""
      fi
    done
  else
    echo "Usage: sudo bash $0 <real-panel-domain> [real-admin-email]"; exit 1
  fi
fi
if _is_example_email "$ADMIN_EMAIL"; then
  echo "Ignoring placeholder admin email '$ADMIN_EMAIL' — LE contact, alerts and recovery go here, it must reach you."
  if [[ -t 0 ]]; then
    while _is_example_email "$ADMIN_EMAIL"; do
      read -r -p "Admin email (real address, not @example): " ADMIN_EMAIL || ADMIN_EMAIL=""
      ADMIN_EMAIL="${ADMIN_EMAIL,,}"
      if [[ -n "$ADMIN_EMAIL" ]] && ! _is_example_email "$ADMIN_EMAIL" \
         && ! [[ "$ADMIN_EMAIL" =~ ^[^@]+@[^@]+\.[a-zA-Z]{2,}$ ]]; then
        echo "'$ADMIN_EMAIL' doesn't look like an email — try again."
        ADMIN_EMAIL=""
      fi
    done
  else
    # same rule as install.sh (which would die on it anyway — fail before
    # downloading instead of after)
    echo "Usage: sudo bash $0 <real-panel-domain> <real-admin-email>  (unattended runs need both real values)"; exit 1
  fi
fi
# unattended (no TTY) fresh installs must accept the licence explicitly —
# install.sh enforces it; say so here, before the download.
if [[ ! -t 0 && "${KARNAK_ACCEPT_LICENSE:-}" != "1" && ! -d /usr/local/skypanel/panel ]]; then
  echo "Unattended install: read the LICENSE (summary on the download page), then re-run with KARNAK_ACCEPT_LICENSE=1."; exit 1
fi
# SSH key: the installer disables password SSH ONLY when root gets a key the
# admin explicitly hands over (SKY_SSH_KEY="ssh-ed25519 AAAA..."). Never guess
# from *.pub files on this server: those are keypairs whose PRIVATE half lives
# here, not on the admin's machine — hardening on one locks everyone out.
# Run via sudo with a TTY, offer the keys the invoking user ALREADY logs in
# with (their authorized_keys), and only on an explicit yes.
if [[ -z "${SKY_SSH_KEY:-}" && -t 0 && -n "${SUDO_USER:-}" && "$SUDO_USER" != "root" ]]; then
  AK="$(getent passwd "$SUDO_USER" | cut -d: -f6)/.ssh/authorized_keys"
  if [[ -s "$AK" ]]; then
    echo "[KarnakPanel] $SUDO_USER logs in with these SSH keys:"
    ssh-keygen -lf "$AK" 2>/dev/null || true
    read -r -p "Install them for root AND disable SSH password logins (all users)? Only say yes if you hold one of these private keys. [y/N] " _ok || _ok=""
    if [[ "$_ok" =~ ^[Yy]$ ]]; then
      SKY_SSH_KEY="$(grep -E '^(ssh-|ecdsa-|sk-)' "$AK" | head -c 8192 || true)"
      export SKY_SSH_KEY
    fi
  fi
fi
if [[ -z "${SKY_SSH_KEY:-}" ]]; then
  echo "WARNING: no SSH key found — password logins stay ENABLED. Harden later:"
  echo "  1. generate one on YOUR machine: ssh-keygen -t ed25519"
  echo "  2. add the .pub via panel Services → SSH, verify a key login works,"
  echo "  3. then set PasswordAuthentication no in /etc/ssh/sshd_config."
  echo "Lose the private key after hardening with no provider console = locked out."
fi
if ! command -v wget >/dev/null 2>&1 || ! command -v openssl >/dev/null 2>&1; then
  apt-get update -qq && apt-get install -y wget ca-certificates tar openssl
fi
source "${KARNAK_OS_RELEASE:-/etc/os-release}" 2>/dev/null || true
# fail BEFORE downloading: fresh installs need Ubuntu 24.04 LTS or Debian 13
# (26.04 lacks the PHP packages this stack needs — installer re-checks too).
# An existing Ubuntu 22.04 panel may re-run (repair) until it migrates.
if [[ "${ID:-}:${VERSION_ID:-}" == "ubuntu:22.04" ]]; then
  if [[ -f "${KARNAK_EXISTING_MARKER:-/usr/local/skypanel/panel/config/config.php}" ]]; then
    echo "WARNING: existing Ubuntu 22.04 panel — repair allowed, but move to a supported server (Admin -> Migrate) before April 2027."
  else
    echo "ERROR: Ubuntu 22.04 is no longer supported for new installs — use Ubuntu 24.04 LTS or Debian 13"; exit 1
  fi
elif [[ "${ID:-}:${VERSION_ID:-}" != "ubuntu:24.04" && "${ID:-}:${VERSION_ID:-}" != "debian:13" ]]; then
  echo "ERROR: ${PRETTY_NAME:-${ID:-unknown} ${VERSION_ID:-}} is not supported — use Ubuntu 24.04 LTS or Debian 13"; exit 1
fi
MEM="$(free -m 2>/dev/null | awk '/^Mem:/{print $2}')"
if [[ -n "$MEM" ]] && [[ "$MEM" -lt 1800 ]]; then
  echo "WARNING: ${MEM}MB RAM under the 2GB minimum (ClamAV will be skipped; mail may scan fail-open)"
fi
echo "[KarnakPanel] resolving latest release..."
VER="$(wget -qO- --timeout=20 "$BASE_URL/VERSION" | tr -cd 'a-zA-Z0-9._-' | head -c 32)"
[[ -n "$VER" ]] || { echo "ERROR: could not read $BASE_URL/VERSION"; exit 1; }
echo "[KarnakPanel] release $VER for domain $PANEL_DOMAIN"
cd /root
wget --timeout=60 -O "karnakpanel-$VER.tar.gz" "$BASE_URL/karnakpanel-$VER.tar.gz"
wget --timeout=20 -O "karnakpanel-$VER.tar.gz.sha256" "$BASE_URL/karnakpanel-$VER.tar.gz.sha256"
sha256sum -c "karnakpanel-$VER.tar.gz.sha256" || { echo "ERROR: checksum mismatch — delete the files and retry"; exit 1; }
# authenticity: the checksum above only proves an intact download (it comes
# from the same server). The .sig is made on the build host with the release
# signing key; this public half is the same trust anchor the panel's
# self-updater uses (installer/configs/update-pubkey.pem).
wget --timeout=20 -O "karnakpanel-$VER.tar.gz.sig" "$BASE_URL/karnakpanel-$VER.tar.gz.sig" \
  || { echo "ERROR: release signature missing — refusing to install an unsigned tarball"; exit 1; }
PUBKEY_TMP="$(mktemp)"
cat > "$PUBKEY_TMP" <<'PEM'
-----BEGIN PUBLIC KEY-----
MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE1SU+5PpR8+cN4dA6VLWZrpfy1hyl
6ylkw1KFof3THSOBfZ5ofWbQB3adf7tB+OYlrO9hTA6LjkIxXqkFHZAQ1A==
-----END PUBLIC KEY-----
PEM
if ! openssl dgst -sha256 -verify "$PUBKEY_TMP" -signature "karnakpanel-$VER.tar.gz.sig" "karnakpanel-$VER.tar.gz" >/dev/null 2>&1; then
  rm -f "$PUBKEY_TMP"; echo "ERROR: signature INVALID — this tarball was not built by KarnakPanel. Do not install it."; exit 1
fi
rm -f "$PUBKEY_TMP"
echo "[KarnakPanel] signature verified"
rm -rf "karnakpanel-$VER" && mkdir -p "karnakpanel-$VER"
tar --no-same-owner -xzf "karnakpanel-$VER.tar.gz" -C "karnakpanel-$VER"
echo "[KarnakPanel] launching installer (takes ~15 min, log: /var/log/karnakpanel-install.log)..."
bash "karnakpanel-$VER/installer/install.sh" "$PANEL_DOMAIN" "$ADMIN_EMAIL"
