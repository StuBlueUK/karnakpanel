#!/usr/bin/env bash
# build-panel-update.sh [version] — assemble the self-update package the panel
# Update UI accepts, at /tmp/karnakpanel-panel-<ver>.tar.gz.
# panel-update.sh is strict and refuses anything else, so this mirrors its
# gates exactly. Layout at tarball ROOT (no top-level dir):
#   MANIFEST            — VERSION=<ver> (becomes releases/<ver>/; must be new)
#   panel/app|public|cli
#   panel/config/config.sample.php   (live config.php is carried, never shipped)
#   scripts/*.sh
#   migrations/*.sql    (from database/migrations/, tracked run-once)
#   cron/skypanel.cron  (merged into /etc/cron.d/skypanel by panel-update.sh)
# NEVER included: config.php, storage/, .git, internal docs, dev tooling.
# NOTE: this is NOT the fresh-install tarball (see docs/deploy-to-users.md —
# flat installer/|app/|... layout); the two formats are not interchangeable.
set -euo pipefail
SRC="$(cd "$(dirname "$0")/.." && pwd)"
VER="${1:-$(cat "$SRC/VERSION")}"
[[ "$VER" =~ ^[a-zA-Z0-9._-]+$ ]] || { echo "bad version: $VER"; exit 1; }
OUT="/tmp/karnakpanel-panel-$VER.tar.gz"
STAGE="$(mktemp -d)"; trap 'rm -rf "$STAGE"' EXIT
PKG="$STAGE/pkg"
mkdir -p "$PKG/panel/config" "$PKG/scripts" "$PKG/migrations" "$PKG/cron"
printf 'VERSION=%s\n' "$VER" > "$PKG/MANIFEST"
cp -a "$SRC/app" "$SRC/public" "$SRC/cli" "$PKG/panel/"
cp -a "$SRC/VERSION" "$PKG/panel/VERSION"
cp -a "$SRC/config/config.sample.php" "$PKG/panel/config/"
cp -a "$SRC"/scripts/*.sh "$PKG/scripts/"
cp -a "$SRC/scripts/wp-cli.phar" "$PKG/scripts/" 2>/dev/null || echo "(no wp-cli phar)"
cp -a "$SRC"/database/migrations/*.sql "$PKG/migrations/" 2>/dev/null || echo "(no migrations)"
cp -a "$SRC/cron/skypanel.cron" "$PKG/cron/" 2>/dev/null || echo "(no cron table)"
# fail fast like panel-update.sh does live
FAIL=0
while IFS= read -r f; do php -l "$f" >/dev/null 2>&1 || { echo "LINT FAIL: $f"; FAIL=1; }; done < <(find "$PKG/panel" -name '*.php')
[[ "$FAIL" == 0 ]] || { echo "ABORTED: fix lint first"; exit 1; }
# never ship secrets or runtime state
if find "$PKG" \( -name 'config.php' -o -name 'storage' -o -name '.git' \) | grep -q .; then
  echo "ABORTED: secrets/runtime would ship"; exit 1
fi
# authenticity: SHA256SUMS covers every shipped file; the .sig proves it came
# from the build host (private key NEVER leaves it). panel-update.sh refuses
# tarballs without a valid signature — a swapped mirror can't push code.
# NOTE: .sig signs SHA256SUMS, not the tarball (avoids sign-then-append paradox).
(cd "$PKG" && find MANIFEST panel scripts migrations cron -type f | sort | xargs sha256sum > SHA256SUMS)
KEY="${KARNAK_SIGN_KEY:-$HOME/.karnakpanel-sign.key}"
[[ -f "$KEY" ]] || { echo "ABORTED: signing key missing ($KEY) — refusing to build unsigned"; exit 1; }
openssl dgst -sha256 -sign "$KEY" -out "$PKG/SHA256SUMS.sig" "$PKG/SHA256SUMS" || { echo "ABORTED: signing failed"; exit 1; }
# root-owned, no group/world write: panel-update extracts as root and GNU tar
# restores owners BY NAME — the build user's name must never reach a box
tar --owner=0 --group=0 --numeric-owner --mode=go-w -czf "$OUT" -C "$PKG" MANIFEST SHA256SUMS SHA256SUMS.sig panel scripts migrations cron
tar tvzf "$OUT" > "$STAGE/vlist"
if [[ -n "$(awk '$2 != "0/0" || substr($1,6,1)=="w" || substr($1,9,1)=="w"' "$STAGE/vlist")" ]]; then
  echo "ABORTED: tarball has non-root owners or group/world-writable entries"; rm -f "$OUT"; exit 1
fi
# basename only: users run `sha256sum -c` next to the download (a /tmp path fails)
(cd "$(dirname "$OUT")" && sha256sum "$(basename "$OUT")") > "$OUT.sha256"
echo "built $OUT ($(tar tzf "$OUT" | wc -l) files)"
echo "upload via panel: System update → 3 → KarnakPanel itself"
