#!/usr/bin/env bash
# restic-install.sh — converge the restic engine on this box (fresh installs
# via installer/lib/restic.sh call it; upgraders via the panel Backups page).
# Pinned binary + checksum (like the PrestaShop CLI): never "latest".
# Idempotent: skips what exists, verifies what it installs.
set -euo pipefail
VER="0.19.1"
SHA="f415415624dcc452f2a02b8c33641791a8c6d6d3b65bbb3543fcf9a25151585c"
URL="https://github.com/restic/restic/releases/download/v${VER}/restic_${VER}_linux_amd64.bz2"
BIN="/usr/local/bin/restic"
PASSF="/root/.restic-pass"
REPO="/backup/restic"
export RESTIC_PASSWORD_FILE="$PASSF"
have="$(restic version 2>/dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1 || true)"
if [[ "$have" != "$VER" ]]; then
  command -v bzip2 >/dev/null 2>&1 || apt-get install -y bzip2
  T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
  curl -sSL --max-time 300 -o "$T/restic.bz2" "$URL"
  echo "$SHA  $T/restic.bz2" | sha256sum -c - || { echo "restic checksum MISMATCH — refusing"; exit 1; }
  bzip2 -dkc "$T/restic.bz2" > "$BIN"
  chmod 755 "$BIN"
  echo "restic $VER installed ($(restic version))"
else
  echo "restic $VER already present"
fi
if [[ ! -f "$PASSF" ]]; then
  openssl rand -base64 32 > "$PASSF"
  chmod 600 "$PASSF"
  echo "repo password generated ($PASSF)"
fi
if [[ ! -d "$REPO/keys" ]]; then
  mkdir -p "$REPO"
  restic init -r "$REPO" 2>&1 | tail -1
else
  echo "repo already initialized ($REPO)"
fi
restic snapshots -r "$REPO" >/dev/null 2>&1 && echo "repo unlocks OK"
# cron is never refreshed by panel updates, so own the schedule lines here
# (idempotent: grep before append; root-owned file stays root-owned).
CRON="/etc/cron.d/skypanel"
if [[ -f "$CRON" ]]; then
  for line in "45 3 * * * root /usr/local/skypanel/scripts/restic-offsite.sh >/dev/null 2>&1" "0 4 * * 0 root /usr/local/skypanel/scripts/restic-forget.sh >/dev/null 2>&1" "30 5 * * 0 root /usr/local/skypanel/scripts/restic-check.sh >/dev/null 2>&1" "0 6 1 * * root /usr/local/skypanel/scripts/restic-verify.sh >/dev/null 2>&1"; do
    job="$(echo "$line" | awk '{print $6}')"
    grep -qF "$job" "$CRON" || echo "$line" >> "$CRON"
  done
  echo "cron lines ensured"
fi
