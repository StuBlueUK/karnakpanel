#!/usr/bin/env bash
# home-perms.sh <user|all> — close hosting homes to OTHER accounts.
# Customer cron (and PHP via putenv+mail()) runs arbitrary code as the
# account, so file permissions are the boundary between accounts, not
# open_basedir. Homes are 711; anything inside with "other" bits (skel
# dotfiles, backup/, Maildir/, legacy public_html/, transferred/restored
# files) was readable by every other account that guessed the path.
#  1. the web server keeps its own way in FIRST: every docroot under the home
#     (from the OLS vhost configs, + legacy ~/public_html) and the dirs above
#     it get u:nobody, and o-readable items in a docroot get u:nobody:rX (dirs
#     also the docroot default ACL) — transferred 644 trees were served via
#     "other" and would 403 if "other" went first.
#  2. then "other" is dropped on everything inside the home (chmod o-rwx /
#     setfacl o::--- never touch the ACL mask; chmod 7x0 would, killing
#     u:nobody), each item first getting u:nobody:rX so the panel (file
#     manager downloads run as nobody) keeps what it reached via "other";
#     ~/Maildir only loses "other". The home itself keeps --x.
#  3. the home's default ACL keeps o::--- for new files.
#  4. ~/.tmp (700, no ACL): the account's PHP/cron temp dir (php-pool.sh
#     TMPDIR/sys_temp_dir/upload_tmp_dir); entries older than 2 days removed.
# Item work runs AS THE ACCOUNT (runuser): a path swapped for a link mid-walk
# then only reaches what the account owns. Only entries the account does not
# own are fixed by root. Idempotent; quiet when nothing changes.
# Called by create-account, vhost-add, restore-account, host-converge,
# transfer import --finish, and daily from the shipped cron table.
set -uo pipefail
ARG="${1:-}"
[[ "$ARG" == all || ( "$ARG" =~ ^[a-z][a-z0-9_]{2,31}$ && "$ARG" != root ) ]] || { echo "usage: $0 <user|all>"; exit 1; }
VH=/usr/local/lsws/conf/vhosts
rc=0

# per-item fix, run as the account (or root for entries it doesn't own).
# args: <home> <owner-test: own|other> <user> <docroot>...
# An item losing "other" first gets u:nobody (if it has no nobody entry):
# rX in docroots (+ the docroot default ACL on dirs), and rX elsewhere in the
# home too — the panel's file manager downloads as nobody and these items
# were reachable for it only through "other". ~/Maildir is the exception:
# it just loses "other" (the panel has no business in mail).
read -r -d '' FIX <<'SH'
H="$1"; MODE="$2"; U="$3"; shift 3
cd / || exit 1   # find -exec + gives up when it can't return to the cwd (/root)
if [[ "$MODE" == own ]]; then OWN=(-user "$U"); else OWN=(! -user "$U"); fi
n=0
fix() {  # <root> <dir-default-spec|-> [find args...]
  local R="$1" DEF="$2"; shift 2
  [[ -d "$R" && ! -L "$R" ]] || return 0
  while IFS= read -r -d '' F; do
    getfacl -cp -- "$F" 2>/dev/null | grep -q '^user:nobody:' || setfacl -m u:nobody:rX -- "$F" 2>/dev/null
    if [[ "$DEF" != - && -d "$F" ]] && [[ -z "$(getfacl -cpd -- "$F" 2>/dev/null)" ]]; then
      setfacl -d -m "$DEF" -- "$F" 2>/dev/null
    fi
    setfacl -m o::--- -- "$F" 2>/dev/null && n=$((n+1))
  done < <(find "$R" -xdev "$@" ! -type l "${OWN[@]}" -perm /o=rwx -print0 2>/dev/null)
}
for DOC in "$@"; do fix "$DOC" u::rwx,g::rwX,u:nobody:rX,o::---; done
fix "$H" - -mindepth 1 -path "$H/Maildir" -prune -o
m="$(find "$H" -xdev -mindepth 1 ! -type l "${OWN[@]}" -perm /o=rwx -exec chmod o-rwx -- {} + -print 2>/dev/null | wc -l)"
echo "$(( n + m ))"
SH

one() {
  local U="$1" PW UIDN H DOC A n1 n2
  PW="$(getent passwd "$U")" || { echo "home-perms: no such user $U"; return 1; }
  IFS=: read -r _ _ UIDN _ _ H _ <<<"$PW"
  (( UIDN >= 1000 )) || return 0
  [[ "$H" == "/home/$U" && -d "$H" && ! -L "$H" ]] || return 0
  # docroots under this home (literal paths only; never through a link)
  local DOCS=()
  for VC in "$VH"/*/vhost.conf; do
    [[ -f "$VC" ]] || continue
    DOC="$(sed -nE 's#^docRoot[[:space:]]+(/home/[^[:space:]]+)[[:space:]]*$#\1#p' "$VC" | head -1)"
    DOC="${DOC%/}"
    [[ "$DOC" == "$H/"* && "$DOC" != *..* && -d "$DOC" && "$(readlink -f -- "$DOC")" == "$DOC" ]] && DOCS+=("$DOC")
  done
  [[ -d "$H/public_html" && ! -L "$H/public_html" ]] && DOCS+=("$H/public_html")
  # 1a. nobody can still reach each docroot: the dirs above it (home included)
  for DOC in "${DOCS[@]}"; do
    A="${DOC%/*}"
    while [[ "$A" == "$H" || "$A" == "$H/"* ]]; do
      if [[ -d "$A" && ! -L "$A" ]] && ! getfacl -cp -- "$A" 2>/dev/null | grep -q '^user:nobody:'; then
        if [[ "$A" == "$H" ]]; then setfacl -m u:nobody:rX -- "$A"; else setfacl -m u:nobody:x -- "$A"; fi
      fi
      [[ "$A" == "$H" ]] && break
      A="${A%/*}"
    done
  done
  # 1b + 2: as the account, then root for entries it doesn't own
  n1="$(runuser -u "$U" -- bash -c "$FIX" _ "$H" own "$U" "${DOCS[@]}" 2>/dev/null)" || n1=0
  n2="$(bash -c "$FIX" _ "$H" other "$U" "${DOCS[@]}" 2>/dev/null)" || n2=0
  # home: never o+r/o+w (keeps --x); default ACL hides new files from others
  [[ -n "$(find "$H" -maxdepth 0 -perm /o=rw)" ]] && chmod o-rw -- "$H"
  if ! getfacl -cpd -- "$H" 2>/dev/null | grep -q '^other::---'; then
    if [[ -n "$(getfacl -cpd -- "$H" 2>/dev/null)" ]]; then setfacl -d -m o::--- -- "$H"
    else setfacl -d -m u::rwx,g::rX,u:nobody:rX,o::--- -- "$H"; fi
  fi
  # 4. private temp dir
  local T="$H/.tmp"
  if [[ -L "$T" || ( -e "$T" && ! -d "$T" ) ]]; then
    echo "home-perms: $U: $T is not a directory — left alone"; rc=1
  else
    [[ -d "$T" ]] || install -d -m 700 -o "$U" -g "$(id -gn "$U")" -- "$T"
    [[ "$(stat -c '%U %a' -- "$T")" == "$U 700" ]] || { chown "$U:" -- "$T"; chmod 700 -- "$T"; }
    setfacl -bk -- "$T" 2>/dev/null
    runuser -u "$U" -- find "$T" -xdev -mindepth 1 -mtime +2 -delete 2>/dev/null
  fi
  (( ${n1:-0} + ${n2:-0} > 0 )) && echo "home-perms: $U: closed $(( n1 + n2 )) item(s) to other accounts"
  return 0
}

if [[ "$ARG" == all ]]; then
  while IFS=: read -r U _ UIDN _ _ H _; do
    [[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ && "$U" != root && "$H" == "/home/$U" ]] || continue
    (( UIDN >= 1000 && UIDN < 60000 )) || continue
    one "$U" || rc=1
  done < <(getent passwd)
else
  one "$ARG" || rc=1
fi
exit $rc
