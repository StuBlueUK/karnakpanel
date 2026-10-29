#!/usr/bin/env bash
# mailbox-restore.sh <domain> <localpart> <tarball> — restore one mailbox
# (whole Maildir) from a backup into the live virtual mail store.
# The mailbox must still exist (use the Email page to recreate it first).
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/as-user.sh"
D="${1:?}"; L="${2:?}"; F="${3:?}"
[[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad domain"; exit 1; }
[[ "$L" =~ ^[a-z0-9._-]{1,64}$ ]] || { echo "bad mailbox"; exit 1; }
[[ "$F" =~ ^[a-zA-Z0-9_.-]+\.tar\.gz$ && "$F" != *"/"* ]] || { echo "bad filename"; exit 1; }
SRC="/backup/skypanel/$F"
[[ -f "$SRC" ]] || { echo "backup not found"; exit 1; }
S="$("$(dirname "$0")/backup-crypt.sh" stage "$SRC")"
if [[ "$S" != "$SRC" ]]; then trap 'rm -f "$S"' EXIT; SRC="$S"; fi
DST="/var/mail/virtual/$D/$L"
[[ -d "$DST" ]] || { echo "mailbox $L@$D does not exist (recreate it first)"; exit 1; }
PRE="var/mail/virtual/$D/$L/"
LIST="$(tar tzf "$SRC" 2>/dev/null || true)"
grep -q -F "$PRE" <<< "$LIST" || { echo "mailbox not in backup"; exit 1; }
# extracted AS vmail (root opens the archive): the store is vmail's alone
cd /
as_user vmail tar xzf - -C / --no-same-owner --no-same-permissions --wildcards "$PRE*" < "$SRC" 2>/dev/null || { echo "extract failed"; exit 1; }
chown -R -P vmail:vmail "$DST"
as_user vmail find "$DST" -type f -exec chmod 600 {} + 2>/dev/null || true
N="$(ls "$DST"/new 2>/dev/null | wc -l)"
echo "restored $L@$D ($N message(s) in inbox)"
