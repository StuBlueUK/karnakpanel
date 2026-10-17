#!/usr/bin/env bash
# offsite-fetch.sh <filename> — pull one file back from the encrypted remote
# into /backup/skypanel/ (decrypted on the fly by the crypt wrapper), so the
# existing browse/restore/download UI works unchanged. Filename is validated
# like the rest of the backup code (flat tarball names only, no paths).
set -euo pipefail
F="${1:?}"
[[ "$F" =~ ^[a-zA-Z0-9_.-]+\.tar\.gz$ ]] || { echo "bad filename"; exit 1; }
[[ "$F" != *"/"* ]] || { echo "bad filename"; exit 1; }
RCLONE="$(command -v rclone || echo /usr/bin/rclone)"
CONF="/root/.config/rclone/rclone.conf"
[[ -x "$RCLONE" && -f "$CONF" ]] || { echo "offsite not configured"; exit 1; }
[[ -f "/backup/skypanel/$F" ]] && { echo "already local: $F"; exit 0; }
"$RCLONE" --config "$CONF" copy "offsite-crypt:$F" /backup/skypanel/ 2>&1 | tail -2 || { echo "fetch failed (no such remote file?)"; exit 1; }
[[ -f "/backup/skypanel/$F" ]] || { echo "fetch failed (no such remote file?)"; exit 1; }
chmod 640 "/backup/skypanel/$F"
echo "fetched: $F"
