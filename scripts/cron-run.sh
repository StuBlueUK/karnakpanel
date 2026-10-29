#!/usr/bin/env bash
# cron-run.sh <user> <jobid> — run ONE stored customer cron job. Called by
# root cron from /etc/cron.d/skypanel-user-<user> (written by cron-sync.sh);
# the command itself lives root-only in /var/lib/karnakpanel/cron/<user>/<id>,
# so this never runs anything but a job the panel stored for that user.
# Why root-launched instead of a per-user crontab: a seccomp filter is
# installed AS ROOT, then privileges drop to the user (setpriv), so inside the
# job symlink()/symlinkat() and io_uring (which can create links past
# seccomp) fail with EPERM. Customer links into other accounts' files would
# otherwise be served by OLS, which reads every home as nobody. Installed by
# root => no no_new_privs, so setuid exim (sendmail, PHP mail()) still works.
# Suspended accounts' jobs are refused here (account_suspended).
# The job sees its own /tmp and /var/tmp (~/.tmp; private-tmp.sh).
set -uo pipefail
U="${1:-}"; ID="${2:-}"
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ && "$ID" =~ ^[0-9]{1,10}$ ]] || { echo "usage: $0 <user> <jobid>"; exit 1; }
[[ "$U" != root ]] || { echo "refused: root"; exit 1; }
CMDF="/var/lib/karnakpanel/cron/$U/$ID"
[[ -f "$CMDF" && ! -L "$CMDF" && "$(stat -c %u "$CMDF")" == 0 ]] || { echo "cron-run: no stored job $U/$ID"; exit 1; }
PW="$(getent passwd "$U")" || { echo "cron-run: no such user $U"; exit 1; }
IFS=: read -r _ _ UIDN GIDN _ HOMED _ <<<"$PW"
(( UIDN >= 1000 && GIDN >= 1000 )) || { echo "cron-run: refused system user/group $U"; exit 1; }
[[ "$HOMED" == "/home/$U" && -d "$HOMED" ]] || { echo "cron-run: bad home for $U"; exit 1; }
HERE="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
. "$HERE/account-check.sh"
account_suspended "$U" && exit 0
cd "$HOMED" || exit 1
. "$HERE/seccomp-common.sh"
. "$HERE/private-tmp.sh"
# the job log's ACL is decided HERE: the account can't read the panel vhost
# (lsws conf is 750), so web-acl.sh in cron-wrap would always say "unsplit"
. "$HERE/web-acl.sh"
# own /tmp + /var/tmp (= ~/.tmp) in a private mount namespace, set up as root
private_tmp_argv "$U" "$UIDN" "$GIDN"
# the command travels on fd 3 (opened here as root), never in argv: argv is
# world-readable in /proc/<pid>/cmdline (ps), and job commands carry passwords
# (mysqldump -p…, wget --password…). cron-wrap reads it into its environment,
# which /proc shows only to the account itself and root.
exec 3<"$CMDF" || exit 1
# numeric ids: a group NAME can be a system group (account "admin" -> sudo-capable "admin")
exec "${PTMP_ARGV[@]}" perl -e "$SECCOMP_PL" -- setpriv --reuid="$UIDN" --regid="$GIDN" --init-groups --reset-env -- env KP_WEB_SPLIT="$WEB_ACL_SPLIT" "$HERE/cron-wrap.sh" "$ID"
