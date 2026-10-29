#!/usr/bin/env bash
# restic-file.sh <username> <snapshot> <path> — print one file from a snapshot
# to stdout (panel file/mailbox restore reads it). GUARDED: snapshot id is
# hex/short-id/latest only; path must sit under the user's home or one of
# THEIR OWN domains' maildirs (ownership re-checked here, not just the panel).
set -euo pipefail
U="${1:?}"; SNAP="${2:?}"; PTH="${3:?}"
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad user"; exit 1; }
[[ "$SNAP" =~ ^([0-9a-f]{7,64}|latest)$ ]] || { echo "bad snapshot"; exit 1; }
[[ "$PTH" != *".."* && "$PTH" == /* ]] || { echo "bad path"; exit 1; }
id "$U" >/dev/null 2>&1 || { echo "no such user"; exit 1; }
BIN="/usr/local/bin/restic"
PASSF="/root/.restic-pass"
REPO="/backup/restic"
[[ -x "$BIN" && -f "$PASSF" && -d "$REPO/keys" ]] || { echo "restic not initialized"; exit 1; }
export RESTIC_PASSWORD_FILE="$PASSF"
ok=""
[[ "$PTH" == "/home/$U"/* ]] && ok=1
if [[ -z "$ok" && "$PTH" == /var/mail/virtual/* ]]; then
  dom="$(echo "$PTH" | cut -d/ -f5)"
  [[ "$dom" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "not your file"; exit 1; }
  P="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
  if [[ -n "$P" && -n "$dom" ]] && MYSQL_PWD="$P" mysql -uskypanel skypanel -N -e "SELECT 1 FROM domains d JOIN users u ON u.id=d.user_id WHERE u.username='$U' AND d.domain='$dom';" 2>/dev/null | grep -q 1; then
    ok=1
  fi
fi
[[ -n "$ok" ]] || { echo "not your file"; exit 1; }
# bare "latest" is repo-global (could be a db snapshot that lacks the path):
# resolve it to this account's newest FILES snapshot instead.
if [[ "$SNAP" == "latest" ]]; then
  SNAP="$("$BIN" snapshots -r "$REPO" --json --tag "account=$U" 2>/dev/null | python3 -c "import json,sys; ss=[s for s in json.load(sys.stdin) if 'kind=files' in (s.get('tags') or [])]; print(max(ss, key=lambda s: s['time'])['short_id'] if ss else '')" 2>/dev/null || true)"
  [[ -n "$SNAP" ]] || { echo "no files snapshot"; exit 1; }
fi
"$BIN" dump -r "$REPO" "$SNAP" "$PTH" 2>/dev/null || { echo "not in snapshot"; exit 1; }
