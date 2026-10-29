#!/usr/bin/env bash
# php-pool.sh <username> <83|84|85…> [max_children] — create (or move) a user's PHP-FPM pool.
# One pool per user in their CURRENT version only; switching versions removes
# the old pool and creates the new one. OLS talks to it via an `fcgi` handler.
# max_children tiers the package (default 10, clamped 2-50): a busy Gold plan
# can't starve the box.
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/php-versions.sh"
. "$(dirname "$(readlink -f "$0")")/as-user.sh"
. "$(dirname "$(readlink -f "$0")")/private-tmp.sh"  # PTMP_OPTOUT
U="${1:?}"; V="${2:?}"; C="${3:-10}"
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad user"; exit 1; }
# any INSTALLED version (a legacy site's plan change must keep its pool);
# the panel offers only PHP_SELECTABLE for new picks
php_known_short "$V" || { echo "bad version: $V (want one of: $(php_installed | tr -d . | xargs))"; exit 1; }
[[ "$C" =~ ^[0-9]{1,2}$ ]] || C=10
(( C >= 2 && C <= 50 )) || C=10
id "$U" >/dev/null 2>&1 || { echo "no such user"; exit 1; }
# drop pools in other versions first (single active pool per user) — every
# pool dir that EXISTS, so a pool left on a just-retired version goes too
OLDV=()
while IFS= read -r PD; do
  OV="${PD#/etc/php/}"; OV="${OV%%/*}"
  [[ "$OV" == "8.${V:1}" ]] && continue
  if [[ -f "$PD/skypanel-$U.conf" ]]; then rm -f "$PD/skypanel-$U.conf"; OLDV+=("$OV"); fi
done < <(php_pool_dirs)
# both dirs sit in the customer's home: made and chmodded AS the owner (a link
# swapped in after a check then only reaches what they own); root only fixes
# ownership with chown -h, which never follows a link
S="/home/$U/.php-sessions"
[[ ! -L "$S" ]] || { echo "REFUSED: $S is a symlink"; exit 1; }
[[ -d "$S" ]] || as_user "$U" mkdir -m 700 -- "$S" || true
[[ -d "$S" && ! -L "$S" ]] || { echo "REFUSED: $S is not a directory"; exit 1; }
chown -h "$U:" -- "$S"; as_user "$U" chmod 700 -- "$S"
# private temp dir: /tmp is shared by every account (uploads, tempnam(),
# WordPress downloads). 700, no ACL — not even the web server reads it.
T="/home/$U/.tmp"
# the shared /tmp stays reachable only for an opted-out account (private-tmp.sh)
OB_TMP=""; [[ -e "$PTMP_OPTOUT/$U" ]] && OB_TMP=":/tmp"
if [[ ! -L "$T" ]]; then
  [[ -d "$T" ]] || as_user "$U" mkdir -m 700 -- "$T" || true
  if [[ -d "$T" && ! -L "$T" ]]; then
    chown -h "$U:" -- "$T"; as_user "$U" chmod 700 -- "$T" || true
    as_user "$U" setfacl -bk -- "$T" 2>/dev/null || true
  fi
fi
# per-user PHP error log (FPM runs as $U; OLS phpIniOverride does NOT reach
# external fcgi apps, so the pool config is the only per-user lever)
# 600 + panel read via ACL: other accounts' cron must not read it
touch "/var/log/karnakpanel/php-$U.error.log"
chown "$U:" "/var/log/karnakpanel/php-$U.error.log"; chmod 600 "/var/log/karnakpanel/php-$U.error.log"
acl_panel_only "/var/log/karnakpanel/php-$U.error.log"
cat > "/etc/php/8.${V:1}/fpm/pool.d/skypanel-$U.conf" <<EOF
; KarnakPanel per-user pool (managed — do not hand-edit)
[$U]
user = $U
group = $(id -gn "$U")
listen = /run/php/skypanel-$U.sock
listen.owner = nobody
listen.group = nogroup
listen.mode = 0660
; small socket backlog: overflow must fail FAST (503) instead of queueing for
; a minute while OLS workers pile up behind a saturated pool
listen.backlog = 16
pm = ondemand
pm.max_children = $C
pm.process_idle_timeout = 30s
; runaway-script brake: no single request (loop, hang, giant import) may burn
; CPU past 5 minutes — the request dies, the pool survives. Legit long jobs
; belong in cron/CLI, not web requests. (Full per-user CPU shares need
; cgroups — tracked as future work; this stops the casual DoS.)
request_terminate_timeout = 300s
; abuse guardrails: pools niced below system services, 256M per script
; (FPM pool files take ';' comments only — '#' lines break the parser)
process.priority = 10
php_admin_value[memory_limit] = 256M
; shell lockdown: hosted PHP (WordPress etc.) never needs these; mail(),
; curl and the filesystem API are untouched so apps keep working.
; pcntl_fork/wait included: with no process limits, one fork-bombing script
; eats the box-wide process table for every user
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec,pcntl_fork,pcntl_wait,pcntl_waitpid,show_source
php_admin_value[open_basedir] = /home/$U$OB_TMP
php_admin_value[error_log] = /var/log/karnakpanel/php-$U.error.log
php_admin_value[session.save_path] = /home/$U/.php-sessions
; temp files in the account's own 700 dir; the shared /tmp is outside
; open_basedir (cron jobs + app daemons see ~/.tmp AS /tmp: private-tmp.sh).
; Root-only opt-out for apps that hard-code /tmp: $PTMP_OPTOUT/<user>.
; soap.wsdl_cache_dir defaults to /tmp: every SoapClient would warn.
php_admin_value[soap.wsdl_cache_dir] = /home/$U/.tmp
; No stricter umask: move_uploaded_file() renames the
; upload into the docroot WITHOUT the dir's default ACL, so the web server
; reads it only through "other" (644) — umask 007 made every upload a 403.
; Docroot dirs are 770 + ACL, so "other" on a file there reaches no account.
php_admin_value[upload_tmp_dir] = /home/$U/.tmp
php_admin_value[sys_temp_dir] = /home/$U/.tmp
env[TMPDIR] = /home/$U/.tmp
EOF
# OLD versions first: on a version move the old master still listens on
# skypanel-$U.sock until reloaded, and a new master reloaded before that
# fails init ("Another FPM instance seems to already listen") and EXITS —
# every site on the new version was down until systemd's restart (~4s).
for OV in "${OLDV[@]}"; do
  systemctl reload "php$OV-fpm" 2>/dev/null || true
done
systemctl reload "php8.${V:1}-fpm" 2>/dev/null || systemctl restart "php8.${V:1}-fpm"
echo "pool $U on PHP 8.${V:1} (max_children=$C)"
