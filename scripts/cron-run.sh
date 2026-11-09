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
# Limits: own scope per run inside karnakcron-<user>.slice (cron-caps.sh),
# overlapping runs skipped, the whole run stopped after 6 h.
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
AS_USER=("${PTMP_ARGV[@]}" perl -e "$SECCOMP_PL" -- setpriv --reuid="$UIDN" --regid="$GIDN" --init-groups --reset-env -- env KP_WEB_SPLIT="$WEB_ACL_SPLIT")
# one line in the job's log, written AS the account (root never opens a path
# the account controls)
note() { "${AS_USER[@]}" KP_NOTE="$1" "$HERE/cron-wrap.sh" "$ID" 3<&- || true; }
# limits (cron-caps.sh): the run is its own scope in the account's cron
# slice — CPU, memory and process caps, stopped whole after KC_RUNTIME
. "$HERE/cron-caps.sh"
. "$HERE/abuse-events.sh"   # limit hits -> the Abuse board's cron flags
kc_caps
RT=(-p RuntimeMaxSec="$KC_RUNTIME" -p OOMPolicy=continue); TO=()
kc_scope_new || { RT=(); TO=(timeout -k 60 "$KC_RUNTIME"); }
# overlap: a run still going = this one is skipped (an every-minute job that
# takes 5 min would otherwise pile up). -o: the job never holds the lock, so a
# child it leaves in the background doesn't block the next run.
install -d -m 700 -o root -g root /run/karnakcron
T0=$SECONDS
flock -n -o -E 75 "/run/karnakcron/$U.$ID.lock" \
  systemd-run --scope --collect --quiet --slice="karnakcron-$U.slice" --unit="karnakcron-$U-$ID-$$" \
    -p CPUQuota="$KC_CPU%" -p MemoryMax="${KC_MEMMAX}M" -p TasksMax="$KC_TASKS" "${RT[@]}" \
    -- nice -n 10 ionice -c2 -n7 "${TO[@]}" "${AS_USER[@]}" "$HERE/cron-wrap.sh" "$ID"
rc=$?
exec 3<&-
# cron-wrap always exits 0 once it ran; anything else is about the run itself
case "$rc" in
  0) ;;
  75) note "=== SKIPPED $(date -Is) === the previous run is still going"; abuse_event "$U" cron-skip "job $ID" ;;
  *)
    if (( rc >= 124 && SECONDS - T0 >= KC_RUNTIME - 60 )); then
      note "=== END exit=$rc $(date -Is) === stopped: ran past the $(( KC_RUNTIME / 3600 )) h time limit"
      abuse_event "$U" cron-timeout "job $ID"
    elif (( rc > 128 )); then
      note "=== END exit=$rc $(date -Is) === stopped: killed (signal $(( rc - 128 ))) — memory or process limit?"
      abuse_event "$U" cron-killed "job $ID signal $(( rc - 128 ))"
    else
      # systemd-run itself failed: never run the job without its limits
      echo "cron-run: $U/$ID not started — could not create its limited scope (rc $rc)" >&2
      note "=== SKIPPED $(date -Is) === could not start under the cron limits — ask the server admin"
    fi ;;
esac
exit 0
