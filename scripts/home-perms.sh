#!/usr/bin/env bash
# home-perms.sh <user|all> — close hosting homes to OTHER accounts.
# Customer cron (and PHP via putenv+mail()) runs arbitrary code as the
# account, so file permissions are the boundary between accounts, not
# open_basedir. Homes are 711; anything inside with "other" bits (skel
# dotfiles, backup/, Maildir/, legacy public_html/, transferred/restored
# files) was readable by every other account that guessed the path.
#  1. the web server keeps its own way in FIRST: every docroot under the home
#     (web-acl.sh user_docroots: OLS vhost configs + legacy ~/public_html)
#     and the dirs above it get u:nobody, and o-readable items in a docroot
#     get u:nobody:rX (dirs also the docroot default ACL) — transferred 644
#     trees were served via "other" and would 403 if "other" went first.
#  2. then "other" is dropped on everything inside the home (chmod o-rwx /
#     setfacl o::--- never touch the ACL mask; chmod 7x0 would, killing
#     the named entries), each item first getting the panel's read
#     (acl_panel: the file manager reads as the panel worker) so it keeps
#     what it reached via "other"; ~/Maildir only loses "other". The home
#     itself keeps --x.
#  3. the home's default ACL keeps o::--- for new files.
#  4. ~/.tmp (700, no ACL): the account's PHP/cron temp dir (php-pool.sh
#     TMPDIR/sys_temp_dir/upload_tmp_dir; cron jobs and app daemons see it
#     as /tmp — private-tmp.sh); entries older than 2 days removed (not
#     sockets/FIFOs a long-running daemon may hold).
#  5. the two web uids converge (ACLPASS below):
#     - u:nobody entries get a u:karnak-panel twin (the panel worker's own
#       uid since panel-user.sh);
#     - SPLIT (the panel no longer runs as nobody, web-acl.sh): nobody keeps
#       rX inside docroots, only --x on the dirs above them, nothing anywhere
#       else in the home — OLS serves static files, it never needs the rest;
#     - UNSPLIT (panel on nobody, or WEB_ACL_PANEL=nobody during a revert):
#       nobody gets back every read karnak-panel has (nobody IS the panel).
# Item work runs AS THE ACCOUNT (runuser): a path swapped for a link mid-walk
# then only reaches what the account owns. Only entries the account does not
# own are fixed by root. Idempotent; quiet when nothing changes.
# Called by create-account, vhost-add, restore-account, host-converge,
# transfer import --finish, and daily from the shipped cron table.
set -uo pipefail
. "$(dirname "$(readlink -f "$0")")/as-user.sh"
ARG="${1:-}"
[[ "$ARG" == all || ( "$ARG" =~ ^[a-z][a-z0-9_]{2,31}$ && "$ARG" != root ) ]] || { echo "usage: $0 <user|all>"; exit 1; }
rc=0

# per-item fix, run as the account (or root for entries it doesn't own).
# args: <home> <owner-test: own|other> <user> <web spec> <panel spec>
#       <panel uid name> <docroot>...
# An item losing "other" first gets a read entry (if it has none yet for the
# uid that needs it): the web spec in docroots (+ the docroot default ACL on
# dirs), the panel spec elsewhere in the home — the panel's file manager
# reached these items only through "other". ~/Maildir is the exception: it
# just loses "other" (the panel has no business in mail).
read -r -d '' FIX <<'SH'
H="$1"; MODE="$2"; U="$3"; WEB="$4"; PAN="$5"; PWHO="$6"; shift 6
cd / || exit 1   # find -exec + gives up when it can't return to the cwd (/root)
if [[ "$MODE" == own ]]; then OWN=(-user "$U"); else OWN=(! -user "$U"); fi
n=0
fix() {  # <root> <dir-default-spec|-> <spec> <uid name it is for> [find args...]
  local R="$1" DEF="$2" SPEC="$3" WHO="$4"; shift 4
  [[ -d "$R" && ! -L "$R" ]] || return 0
  while IFS= read -r -d '' F; do
    getfacl -cp -- "$F" 2>/dev/null | grep -q "^user:$WHO:" || setfacl -m "$SPEC" -- "$F" 2>/dev/null
    if [[ "$DEF" != - && -d "$F" ]] && [[ -z "$(getfacl -cpd -- "$F" 2>/dev/null)" ]]; then
      setfacl -d -m "$DEF" -- "$F" 2>/dev/null
    fi
    setfacl -m o::--- -- "$F" 2>/dev/null && n=$((n+1))
  done < <(find "$R" -xdev "$@" ! -type l "${OWN[@]}" -perm /o=rwx -print0 2>/dev/null)
}
for DOC in "$@"; do fix "$DOC" "u::rwx,g::rwX,$WEB,o::---" "$WEB" nobody; done
fix "$H" - "$PAN" "$PWHO" -mindepth 1 -path "$H/Maildir" -prune -o
m="$(find "$H" -xdev -mindepth 1 ! -type l "${OWN[@]}" -perm /o=rwx -exec chmod o-rwx -- {} + -print 2>/dev/null | wc -l)"
echo "$(( n + m ))"
SH

# root's share of the same fix (entries the account does NOT own), race-free:
# every path is walked by descriptor from the home (O_NOFOLLOW per component,
# os.fwalk) and acted on through /proc/self/fd — a path re-resolved by name
# (find | setfacl/chmod) could be steered by a link the account swaps in
# mid-walk onto a system file. Same rules as FIX; prints the count.
read -r -d '' ROOTFIX <<'PYF'
import os, stat, subprocess, sys
H, U, UID, WEB, PAN, PWHO = sys.argv[1], sys.argv[2], int(sys.argv[3]), sys.argv[4], sys.argv[5], sys.argv[6]
docs = sys.argv[7:]
NF = os.O_PATH | os.O_NOFOLLOW
n = 0
def acl(fd, *a):
    return subprocess.run(["setfacl", *a, "--", f"/proc/self/fd/{fd}"], pass_fds=(fd,),
                          stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0
def facl(fd, *a):
    return subprocess.run(["getfacl", "-cp", *a, "--", f"/proc/self/fd/{fd}"], pass_fds=(fd,),
                          capture_output=True, text=True).stdout
def item(fd, st, dflt, spec, who):
    global n
    if facl(fd).find(f"user:{who}:") < 0: acl(fd, "-m", spec)
    if dflt != "-" and stat.S_ISDIR(st.st_mode) and not facl(fd, "-d").strip(): acl(fd, "-d", "-m", dflt)
    if acl(fd, "-m", "o::---"): n += 1
    m = stat.S_IMODE(os.fstat(fd).st_mode)
    if m & 0o007: os.chmod(f"/proc/self/fd/{fd}", m & ~0o007); n += 1
def want(st):
    return not stat.S_ISLNK(st.st_mode) and st.st_uid != UID and st.st_mode & 0o007
def opendir_nf(hfd, rel):  # descend component by component, never through a link
    fd = os.dup(hfd)
    for c in [x for x in rel.split("/") if x]:
        try: nfd = os.open(c, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
        finally: os.close(fd)
        fd = nfd
    return fd
def tree(hfd, rel, dflt, spec, who, self_too, prune=None):
    try: top = opendir_nf(hfd, rel)
    except OSError: return
    try:
        dev = os.fstat(top).st_dev
        if self_too and want(os.fstat(top)): item(top, os.fstat(top), dflt, spec, who)
        for dp, dn, fn, dfd in os.fwalk(".", dir_fd=top, follow_symlinks=False):
            if dp == "." and prune in dn: dn.remove(prune)
            for name in list(dn) + fn:
                try: fd = os.open(name, NF, dir_fd=dfd)
                except OSError: continue
                try:
                    st = os.fstat(fd)
                    if st.st_dev != dev:
                        if name in dn: dn.remove(name)
                        continue
                    if want(st): item(fd, st, dflt, spec, who)
                finally: os.close(fd)
    finally: os.close(top)
hfd = os.open(H, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
for d in docs:
    if d.startswith(H + "/"): tree(hfd, d[len(H) + 1:], f"u::rwx,g::rwX,{WEB},o::---", WEB, "nobody", True)
tree(hfd, "", "-", PAN, PWHO, False, prune="Maildir")
print(n)
PYF

# 5. the two web uids converge (see the header). Raw xattr edits by
# descriptor (fd-walk, /proc/self/fd, never a name): no setfacl per file
# (20k-file trees), no link-following. ~/Maildir pruned (neither uid has
# entries there). args: <home> <split 0|1> <docroot>... Prints
# "<twinned> <web reads dropped or narrowed> <web reads restored>".
# nobody is only ever dropped where karnak-panel already holds at least the
# same perms (twinned first), so a half-run pass can never strand the panel.
read -r -d '' ACLPASS <<'PYM'
import os, pwd, stat, struct, sys
H, SPLIT = sys.argv[1], sys.argv[2] == "1"
DOCS = [d[len(H) + 1:] for d in sys.argv[3:] if d.startswith(H + "/")]
NOB, KP = pwd.getpwnam("nobody").pw_uid, pwd.getpwnam("karnak-panel").pw_uid
USER, X = 0x02, 1
cnt = [0, 0, 0]
def where(rel):  # "doc" inside a docroot, "anc" a dir above one, "" elsewhere
    if any(rel == d or rel.startswith(d + "/") for d in DOCS): return "doc"
    if DOCS and (rel == "" or any(d.startswith(rel + "/") for d in DOCS)): return "anc"
    return ""
def fix(path, isdir, rel):
    w = where(rel) if SPLIT else "doc"
    for name in ("system.posix_acl_access",) + (("system.posix_acl_default",) if isdir else ()):
        try: b = os.getxattr(path, name)
        except OSError: continue
        if len(b) < 4 or (len(b) - 4) % 8 or struct.unpack_from("<I", b)[0] != 2: continue
        ents = [struct.unpack_from("<HHI", b, 4 + 8 * i) for i in range((len(b) - 4) // 8)]
        get = lambda uid: next((e[1] for e in ents if e[0] == USER and e[2] == uid), None)
        nob, kp, c = get(NOB), get(KP), None
        if nob is not None and kp is None:                       # twin
            ents.append((USER, nob, KP)); kp = nob; c = 0
        if not SPLIT:
            if kp is not None and (nob is None or nob & kp != kp):  # nobody is the panel again
                ents = [e for e in ents if not (e[0] == USER and e[2] == NOB)] + [(USER, (nob or 0) | kp, NOB)]; c = 2
        elif nob is not None and kp is not None and kp & nob == nob and w != "doc":
            keep = X if (w == "anc" and name == "system.posix_acl_access") else None
            if keep != nob:                                      # narrow to --x or drop
                ents = [e for e in ents if not (e[0] == USER and e[2] == NOB)] + ([(USER, keep, NOB)] if keep else []); c = 1
        if c is None: continue
        ents.sort(key=lambda e: (e[0], e[2]))
        try: os.setxattr(path, name, struct.pack("<I", 2) + b"".join(struct.pack("<HHI", *e) for e in ents)); cnt[c] += 1
        except OSError: pass
top = os.open(H, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
dev = os.fstat(top).st_dev
fix(f"/proc/self/fd/{top}", True, "")
for dp, dn, fn, dfd in os.fwalk(".", dir_fd=top, follow_symlinks=False):
    if dp == "." and "Maildir" in dn: dn.remove("Maildir")
    base = os.path.normpath(dp)
    for name in list(dn) + fn:
        try: fd = os.open(name, os.O_PATH | os.O_NOFOLLOW, dir_fd=dfd)
        except OSError: continue
        try:
            st = os.fstat(fd)
            if stat.S_ISLNK(st.st_mode): continue
            if st.st_dev != dev:
                if name in dn: dn.remove(name)
                continue
            fix(f"/proc/self/fd/{fd}", stat.S_ISDIR(st.st_mode), name if base == "." else f"{base}/{name}")
        finally: os.close(fd)
print(*cnt)
PYM

one() {
  local U="$1" PW UIDN H DOC A B C n1 n2 n3 PWHO
  PW="$(getent passwd "$U")" || { echo "home-perms: no such user $U"; return 1; }
  IFS=: read -r _ _ UIDN _ _ H _ <<<"$PW"
  (( UIDN >= 1000 )) || return 0
  [[ "$H" == "/home/$U" && -d "$H" && ! -L "$H" ]] || return 0
  local DOCS=()
  mapfile -t DOCS < <(user_docroots "$H")
  # 1a. nobody can still reach each docroot: the dirs above it (home included;
  #     unsplit the home also lists for it — nobody is the panel there)
  for DOC in "${DOCS[@]}"; do
    A="${DOC%/*}"
    while [[ "$A" == "$H" || "$A" == "$H/"* ]]; do
      if [[ -d "$A" && ! -L "$A" ]] && ! getfacl -cp -- "$A" 2>/dev/null | grep -q '^user:nobody:'; then
        # -P: a link swapped in after the -L test is skipped, never followed
        if [[ "$A" == "$H" ]] && (( ! WEB_ACL_SPLIT )); then setfacl -P -m u:nobody:rX -- "$A"; else setfacl -P -m u:nobody:x -- "$A"; fi
      fi
      [[ "$A" == "$H" ]] && break
      A="${A%/*}"
    done
  done
  # 1b + 2: as the account, then root for entries it doesn't own
  if (( WEB_ACL_SPLIT )); then PWHO=karnak-panel; else PWHO=nobody; fi
  n1="$(as_user "$U" bash -c "$FIX" _ "$H" own "$U" "$(acl_web rX)" "$(acl_panel rX)" "$PWHO" "${DOCS[@]}" 2>/dev/null)" || n1=0
  n2="$(python3 -I -c "$ROOTFIX" "$H" "$U" "$UIDN" "$(acl_web rX)" "$(acl_panel rX)" "$PWHO" "${DOCS[@]}" 2>/dev/null)" || n2=0
  # home: never o+r/o+w (keeps --x); default ACL hides new files from others
  [[ -n "$(find "$H" -maxdepth 0 -perm /o=rw)" ]] && chmod o-rw -- "$H"
  if ! getfacl -cpd -- "$H" 2>/dev/null | grep -q '^other::---'; then
    if [[ -n "$(getfacl -cpd -- "$H" 2>/dev/null)" ]]; then setfacl -d -m o::--- -- "$H"
    else setfacl -d -m "u::rwx,g::rX,$(acl_panel rX),o::---" -- "$H"; fi
  fi
  # 4. private temp dir
  local T="$H/.tmp"
  if [[ -L "$T" || ( -e "$T" && ! -d "$T" ) ]]; then
    echo "home-perms: $U: $T is not a directory — left alone"; rc=1
  else
    [[ -d "$T" ]] || install -d -m 700 -o "$U" -g "$(id -gn "$U")" -- "$T"
    # chown -h never follows; the chmod then runs AS the (new) owner
    [[ "$(stat -c '%U %a' -- "$T")" == "$U 700" ]] || { chown -h "$U:" -- "$T"; as_user "$U" chmod 700 -- "$T"; }
    as_user "$U" setfacl -bk -- "$T" 2>/dev/null
    # sockets/FIFOs kept: ~/.tmp is also cron's and app daemons' /tmp (private-tmp.sh)
    as_user "$U" find "$T" -xdev -mindepth 1 -mtime +2 ! -type s ! -type p -delete 2>/dev/null
  fi
  (( ${n1:-0} + ${n2:-0} > 0 )) && echo "home-perms: $U: closed $(( n1 + n2 )) item(s) to other accounts"
  # 5. web uids converge (only once the panel user exists)
  if (( WEB_ACL_KP )); then
    n3="$(python3 -I -c "$ACLPASS" "$H" "$WEB_ACL_SPLIT" "${DOCS[@]}" 2>/dev/null)" || { echo "home-perms: $U: web ACL pass failed"; rc=1; n3="0 0 0"; }
    read -r A B C <<<"$n3"
    (( ${A:-0} > 0 )) && echo "home-perms: $U: panel worker (karnak-panel) ACL added on $A entr(ies)"
    (( ${B:-0} > 0 )) && echo "home-perms: $U: web server (nobody) read dropped outside docroots on $B entr(ies)"
    (( ${C:-0} > 0 )) && echo "home-perms: $U: web server (nobody) read restored on $C entr(ies) (panel on nobody)"
  fi
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
