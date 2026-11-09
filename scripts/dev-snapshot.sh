#!/usr/bin/env bash
# dev-snapshot.sh [label] — poor-man's versioning for a git-free repo (run on
# the DEV host after each feature). Copies the project tree (minus runtime)
# to ~/snapshots/<date>-<label>/ plus a sha256 manifest, keeps the last 10.
# Answers "what changed?" via: diff -r <snapA> <snapB>, and verifies live
# boxes with: sha256sum -c against the manifest (paths are repo-relative).
set -euo pipefail
SRC="$(cd "$(dirname "$0")/.." && pwd)"
LABEL="${1:-work}"
DEST="$HOME/snapshots/$(date +%Y%m%d-%H%M)-$LABEL"
mkdir -p "$DEST"
tar cf - --exclude=storage --exclude=config/config.php --exclude=.git -C "$SRC" . | tar xf - -C "$DEST"
(cd "$DEST" && find . -type f -not -path "./storage/*" | sort | xargs sha256sum > MANIFEST.sha256)
ls -dt "$HOME"/snapshots/*/ 2>/dev/null | tail -n +11 | xargs -r rm -rf
echo "snapshot: $DEST ($(wc -l < "$DEST/MANIFEST.sha256") files)"
