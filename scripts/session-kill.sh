#!/usr/bin/env bash
# session-kill.sh <sid> — destroy one panel session file. Strict basename
# handling: the sid can never escape the sessions dir.
set -euo pipefail
SID="${1:?}"
[[ "$SID" =~ ^[a-z0-9,-]{22,128}$ ]] || { echo "bad session"; exit 1; }
# the PANEL's PHP (skypanel-php -> its lsphp) decides where its sessions live
DIR="$(/usr/bin/skypanel-php -r 'echo ini_get("session.save_path");' 2>/dev/null || echo /var/lib/php/sessions)"
[[ -d "$DIR" ]] || DIR="/var/lib/php/sessions"
F="$DIR/sess_$SID"
[[ "$(realpath -m "$F")" == "$(realpath -m "$DIR")/sess_$SID" ]] || { echo "bad session"; exit 1; }
[[ -f "$F" ]] || { echo "session already gone"; exit 1; }
rm -f -- "$F"
echo "session killed"
