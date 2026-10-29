#!/usr/bin/env bash
# panel-fetch-update.sh — one-click update import for the Update page.
# Downloads the LATEST signed panel-update package from the release feed
# into the panel staging dir (verified SHA256; signature itself is verified
# later by panel-update.sh before anything executes). Prints the staged path
# (or "current <ver>" when already up to date). Never installs anything.
set -euo pipefail
BASE="${KARNAK_BASE_URL:-https://karnakpanel.com/downloads}"
P="/usr/local/skypanel/panel"
RSTAGE="$(realpath -m "$P/storage/tmp-uploads")"
mkdir -p "$RSTAGE"
CUR="$(cat "$P/VERSION" 2>/dev/null || echo 0)"
REMOTE="$(curl -fsSL --max-time 20 "$BASE/VERSION" 2>/dev/null | tr -d ' \r\n' || true)"
[[ "$REMOTE" =~ ^[a-zA-Z0-9._-]+$ ]] || { echo "feed unreachable (got '$REMOTE')"; exit 1; }
if [[ "$REMOTE" == "$CUR" ]]; then echo "current $CUR"; exit 0; fi
# refuse downgrades too (feed compromise shouldn't roll the box back)
if printf '%s\n%s\n' "$REMOTE" "$CUR" | sort -V | head -1 | grep -qx "$REMOTE"; then
  echo "feed older than installed ($REMOTE < $CUR) — refusing"; exit 1
fi
T="karnakpanel-panel-$REMOTE.tar.gz"
curl -fsSL --max-time 120 -o "$RSTAGE/$T" "$BASE/$T" || { echo "download failed"; exit 1; }
EXP="$(curl -fsSL --max-time 20 "$BASE/$T.sha256" 2>/dev/null | awk '{print $1}' || true)"
ACT="$(sha256sum "$RSTAGE/$T" | awk '{print $1}')"
if [[ -z "$EXP" || "$EXP" != "$ACT" ]]; then
  rm -f "$RSTAGE/$T"
  echo "checksum mismatch — staged file deleted, aborting"; exit 1
fi
chmod 600 "$RSTAGE/$T"
echo "staged $RSTAGE/$T ($REMOTE)"
