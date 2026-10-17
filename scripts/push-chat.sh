#!/usr/bin/env bash
# push-chat.sh <bot-token> — list recent chats that messaged the bot (panel
# "Find my chat"). Message the bot anything first, then run: prints one
# "chat_id name" per line for the UI to offer. Token validated by shape only
# here; Telegram itself rejects bad ones (empty result, exit 1).
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/secret-args.sh"; secret_argv "$@"; set -- "${SECRET_ARGV[@]}"  # @stdin secrets
TOKEN="${1:?}"
[[ "$TOKEN" =~ ^[0-9]{5,20}:[A-Za-z0-9_-]{20,80}$ ]] || { echo "bad token shape (digits:string from @BotFather)"; exit 1; }
OUT="$(curl -fsSL --max-time 20 "https://api.telegram.org/bot$TOKEN/getUpdates?limit=20" 2>/dev/null || true)"
[[ -n "$OUT" ]] || { echo "no answer from Telegram (bad token or network?)"; exit 1; }
echo "$OUT" | python3 -c "
import json, sys
try:
    d = json.load(sys.stdin)
except Exception:
    print('bad reply from Telegram'); sys.exit(1)
if not d.get('ok'):
    print('Telegram refused the token'); sys.exit(1)
seen = {}
for u in d.get('result', []):
    c = (u.get('message') or {}).get('chat') or {}
    cid = c.get('id')
    if cid is None or cid in seen: continue
    seen[cid] = True
    name = c.get('title') or ((c.get('first_name') or '') + ' ' + (c.get('last_name') or '')).strip() or c.get('username') or '?'
    print(f\"{cid}\t{name}\")
" || exit 1
