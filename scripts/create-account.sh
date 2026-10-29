#!/usr/bin/env bash
# create-account.sh [--repair] <username> [php_version] [password] [max_children]
# password (optional): sets the SYSTEM password via chpasswd so the main
# account can FTP immediately (vsftpd local_enable). Panel password changes
# re-sync it via password-sync.sh — the two are the same credential by design.
# Default = a NEW account: refused (exit 3, nothing changed) when a Linux user
# of that name already exists. Checked FIRST, before anything else runs: an
# existing user was adopted before — "ubuntu" kept its shell + sudo group and
# got the customer's password (see account-check.sh).
# --repair = re-converge an EXISTING panel account (domain-add self-heal,
# transfer --finish): an existing user is accepted only when it looks
# panel-made (uid >= 1000, nologin, no sudo/adm group); a missing one is
# created (panel admin with no system user yet). Never sets a password.
set -euo pipefail
HERE="$(dirname "$(readlink -f "$0")")"
. "$HERE/account-check.sh"
. "$HERE/as-user.sh"
REPAIR=0; [[ "${1:-}" == "--repair" ]] && { REPAIR=1; shift; }
valid_account_name "${1:-}" || exit 1
NEWUSER=1
if getent passwd "$1" >/dev/null 2>&1; then
  [[ "$REPAIR" == "1" ]] || { echo "refused: a Linux user named $1 already exists on this server (pick another username)"; exit 3; }
  panel_account "$1" || { echo "refused: $ACCOUNT_CHECK_WHY"; exit 3; }
  NEWUSER=0
fi
# Login group for a new user: its own name, else u_<name>. An existing group is
# reused only when it is a leftover of ours (gid >= 1000, nobody in it, nobody's
# primary group) — never a system group (Ubuntu's 'admin' grants sudo) and
# never a group another user's files belong to.
group_free() {
  local ent gid
  ent="$(getent group "$1")" || return 0
  gid="$(cut -d: -f3 <<<"$ent")"
  [[ "$gid" =~ ^[0-9]+$ ]] && (( gid >= 1000 && gid != 65534 )) || return 1
  [[ -z "$(cut -d: -f4 <<<"$ent")" ]] || return 1
  ! getent passwd | awk -F: -v g="$gid" '$4==g {f=1} END {exit !f}'
}
G=""
if [[ "$NEWUSER" == "1" ]]; then
  # a home left by an account that still exists under another name is not ours
  if [[ -e "/home/$1" ]] && getent passwd "$(stat -c %u "/home/$1")" >/dev/null 2>&1; then
    echo "refused: /home/$1 already exists and belongs to another user"; exit 3
  fi
  if group_free "$1"; then G="$1"; elif group_free "u_$1"; then G="u_$1"
  else echo "refused: groups $1 and u_$1 both exist and belong to something else"; exit 3; fi
fi
. "$HERE/secret-args.sh"; secret_argv "$@"; set -- "${SECRET_ARGV[@]}"  # @stdin secrets
. "$HERE/php-versions.sh"
U="$1"; PHPV="${2:-$PHP_DEFAULT}"; PWD="${3:-}"; KIDS="${4:-10}"
[[ "$REPAIR" == "1" && -n "$PWD" ]] && { echo "refused: --repair never sets a password"; exit 1; }
# a version this box doesn't have (transfer/import from an older box still on
# a retired one) lands on the default instead of ending up with no pool at all
php_known "$PHPV" || { echo "NOTE: PHP $PHPV not installed here — account $U gets $PHP_DEFAULT"; PHPV="$PHP_DEFAULT"; }
# nologin shell: accounts are FTP-only by design (vsftpd check_shell=NO).
# A login shell + panel-synced password would mean full SSH for every customer.
# Group: private group per user, chosen above (never a system group).
if [[ "$NEWUSER" == "1" ]]; then
  getent group "$G" >/dev/null 2>&1 || groupadd "$G"
  # fails if a parallel run made the user meanwhile: exit 3 = not ours, so the
  # caller must not tear it down
  useradd -m -s /usr/sbin/nologin -g "$G" "$U" || { echo "refused: could not create user $U (does it exist already?)"; exit 3; }
fi
if [[ -n "$PWD" ]]; then
  [[ "${#PWD}" -ge 8 ]] || { echo "password too short, system login left locked"; }
  # pre-hashed (-e): provider images may enforce pam_pwquality dictionary
  # checks that reject even strong panel passwords via plain chpasswd.
  # Quality is the panel's job (length + HIBP), not PAM's.
  if [[ "${#PWD}" -ge 8 ]]; then
    H="$(printf '%s' "$PWD" | openssl passwd -6 -stdin)" || { echo "hash failed"; exit 1; }
    echo "$U:$H" | chpasswd -e && echo "system password set for $U"
  fi
fi
# --repair runs on every domain add, over a home full of customer content:
# below /home/$U everything is done AS the owner (a planted link then only
# reaches what they own). Root: the home dir itself (/home is root's, so it
# can't be a link) and chown -R, which never follows links.
[[ -d "/home/$U" && ! -L "/home/$U" ]] || install -d -m 711 -o "$U" -g "$(id -g "$U")" -- "/home/$U"
chown -R "$U:" "/home/$U"
as_user "$U" mkdir -p -- "/home/$U/public_html" "/home/$U/Maildir" "/home/$U/backup"
# never clobber an existing homepage (domain flow re-runs this for repairs)
if ! as_user "$U" test -e "/home/$U/public_html/index.html" && ! as_user "$U" test -L "/home/$U/public_html/index.html"; then
user_put "$U" "/home/$U/public_html/index.html" 640 <<EOF
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Welcome — coming soon</title></head>
<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#0b1220,#0ea5e9);font-family:Segoe UI,Arial,sans-serif;color:#e2e8f0">
<div style="text-align:center;background:rgba(11,18,32,.72);border:1px solid #1e3a5f;border-radius:18px;padding:44px 56px;box-shadow:0 20px 60px rgba(0,0,0,.45)">
<svg width="72" height="72" viewBox="0 0 64 64"><rect width="64" height="64" rx="14" fill="#0b3b66"/><circle cx="32" cy="17" r="7" fill="#fbbf24"/><path d="M12 48 L32 24 L52 48 Z" fill="#d4a437"/><path d="M32 24 L52 48 L32 48 Z" fill="#92400e" opacity=".45"/><rect x="10" y="48" width="44" height="4" rx="2" fill="#f6d365" opacity=".85"/></svg>
<h1 style="margin:18px 0 6px">Welcome, $U</h1>
<p style="color:#94a3b8;margin:0">Your hosting space is ready — add a domain to publish a site.</p>
<p style="margin:22px 0 0;font-size:13px;color:#38bdf8">Hosted with 🏛️ KarnakPanel</p>
</div></body></html>
EOF
fi
# per-user PHP-FPM pool right away (isolated UID even before first domain) —
# but ONLY when none exists in any version. Pools are per-USER while domains
# carry per-domain versions: rebuilding here (php-pool.sh deletes other-version
# pools) would silently downgrade every other domain of this user to $PHPV.
# Real case 2026-10-10: a domain save re-ran this for admin with the form
# default 8.2, deleted the 8.3 pool, and killed the forum until restore.
# Deliberate moves belong to the PHP page (php-switch.sh), never to self-heal.
EXIST_POOL="$(ls /etc/php/8.*/fpm/pool.d/skypanel-"$U".conf 2>/dev/null | head -1 || true)"
if [[ -n "$EXIST_POOL" ]]; then
  echo "pool for $U exists ($EXIST_POOL) — keeping version (move it from the PHP page)"
else
  "$HERE/php-pool.sh" "$U" "${PHPV/./}" "$KIDS" 2>/dev/null || echo "WARN: no FPM pool for $U (php-fpm missing?)"
fi
# 711 home: owner full, others traverse-only (web server + FTP chroot need x, not r).
# public_html stays 755 so the shared web worker can read site files.
chmod 711 "/home/$U"; as_user "$U" chmod 755 -- "/home/$U/public_html"
# home itself: owner-only listing, but the panel worker must LIST for the
# file manager (traverse alone is not enough for scandir); the web server
# (nobody) only traverses it once the panel has its own uid (web-acl.sh).
# The home default ACL is the panel's only: docroots carry nobody's own.
if command -v setfacl >/dev/null 2>&1; then
  setfacl -m "$(acl_anc rX)" "/home/$U" 2>/dev/null || true
  # owning-group list rights on the home root (711 gives the group nothing —
  # suffix FTP logins share the home group and would see an empty home)
  setfacl -m g::rX "/home/$U" 2>/dev/null || true
  setfacl -d -m "$(acl_panel rX)" -m u::rwx -m g::rX -m o::--- "/home/$U" 2>/dev/null || true
fi
# File isolation between shell users: site files 640 + default ACL so the web
# server (nobody) and the panel can still read new files; other users cannot.
# NOTE: this stops SHELL snooping; customer PHP runs in per-user FPM pools
# (vhost-add.sh routes each domain to fpm-$U, running as the owner's UID).
if command -v setfacl >/dev/null 2>&1; then
  as_user "$U" setfacl -R -P -m "$(acl_web rX)" -- "/home/$U/public_html" 2>/dev/null || true
  as_user "$U" setfacl -d -m "$(acl_web rX)" -m u::rwx -m g::rX -m o::--- -- "/home/$U/public_html" 2>/dev/null || true
  as_user "$U" find "/home/$U/public_html" -type f -exec chmod 640 {} + 2>/dev/null || true
fi
echo "$U" >> /etc/vsftpd.userlist 2>/dev/null || true; sort -u /etc/vsftpd.userlist -o /etc/vsftpd.userlist
"$HERE/home-perms.sh" "$U" >/dev/null || true
echo "created $U php=$PHPV home=/home/$U (domains live in /home/$U/<domain>/public_html)"
