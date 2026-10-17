#!/usr/bin/env bash
# restore-account.sh <username> <filename> — restore an account from a panel
# backup tarball. GUARDED: filename must be a plain *.tar.gz inside
# /backup/skypanel; only members under home/<user>/ are extracted, excluding
# the backup staging dir itself; ownership is repaired afterwards.
set -euo pipefail
U="${1:?}"; F="${2:?}"
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad user: $U"; exit 1; }
[[ "$F" =~ ^[a-zA-Z0-9_.-]+\.tar\.gz$ ]] || { echo "bad filename: $F"; exit 1; }
[[ "$F" != *"/"* && "$F" != *".."* ]] || { echo "bad filename: $F"; exit 1; }
SRC="/backup/skypanel/$F"
[[ -f "$SRC" ]] || { echo "backup not found: $F"; exit 1; }
id "$U" >/dev/null 2>&1 || { echo "no such user: $U"; exit 1; }
S="$("$(dirname "$0")/backup-crypt.sh" stage "$SRC")"
if [[ "$S" != "$SRC" ]]; then trap 'rm -f "$S"' EXIT; SRC="$S"; fi
# refuse foreign tarballs: top-level members must be this user's home or mail
BAD="$(tar tzf "$SRC" 2>/dev/null | awk -F/ '{print $1"/"$2}' | sort -u | grep -v -x -e "home/$U" -e "var/mail" || true)"
if [[ -n "$BAD" ]]; then echo "tarball contains foreign paths: $BAD"; exit 1; fi
tar xzf "$SRC" -C / --wildcards "home/$U/*/public_html" "home/$U/public_html" "home/$U/Maildir" "var/mail/virtual/*" 2>/dev/null || true
chown -R "$U:" "/home/$U" 2>/dev/null || true
chown -R vmail:vmail /var/mail/virtual 2>/dev/null || true
# restored trees keep their archived modes: close them to other accounts
"$(dirname "$(readlink -f "$0")")/home-perms.sh" "$U" >/dev/null || true
echo "restored $F for $U (sites + mail)"
