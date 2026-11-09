# private-tmp.sh — sourced (never run): per-account private /tmp.
# /tmp and /var/tmp are shared by every account; a customer's cron job or
# app daemon gets its own instead: ~/.tmp (700, the same dir the account's
# web PHP uses as sys_temp_dir/upload_tmp_dir/TMPDIR — php-pool.sh), bind-
# mounted over /tmp and /var/tmp in a private mount namespace, nosuid,nodev,
# plus a fresh tmpfs on /dev/shm (the account's own, 700, gone with the job)
# so POSIX shm/semaphores aren't shared with other accounts either.
# So a job writing /tmp/x and a page reading sys_get_temp_dir().'/x' still
# meet, and no other account sees either. Root sets it up BEFORE the
# seccomp filter and the privilege drop (cron-run.sh, app-daemon-run.sh); the
# account cannot undo it (no CAP_SYS_ADMIN after setpriv).
# Race-free: ~/.tmp is opened by descriptor (O_PATH|O_NOFOLLOW, component by
# component from the home, owner + same-fs checked) and the mount source is
# that descriptor (/proc/self/fd/N straight to mount(2) — util-linux mount
# would canonicalize it back to a path a swapped link could steer).
# A ~/.tmp that is a link or not the account's: the job is refused, never
# run on the shared /tmp.
# Needs python3 (installer base package; fail2ban pulls it in anyway) and
# util-linux unshare/setpriv.
# Opt-out (root-only flag, also read by php-pool.sh): a file
# /var/lib/karnakpanel/tmp-shared/<user> keeps that account on the shared
# /tmp everywhere — for apps that hard-code /tmp across web + cron.
PTMP_OPTOUT=/var/lib/karnakpanel/tmp-shared

read -r -d '' PTMP_PY <<'PYT' || true   # read -d '' returns 1 at EOF (set -e callers)
import ctypes, os, stat, sys
U, UID, GID = sys.argv[1], int(sys.argv[2]), int(sys.argv[3]); cmd = sys.argv[4:]
libc = ctypes.CDLL(None, use_errno=True)
libc.mount.argtypes = [ctypes.c_char_p, ctypes.c_char_p, ctypes.c_char_p, ctypes.c_ulong, ctypes.c_void_p]
MS_NOSUID, MS_NODEV, MS_NOEXEC, MS_REMOUNT, MS_BIND = 2, 4, 8, 32, 4096
def die(m):
    print(f"private-tmp: {U}: {m} — refused", file=sys.stderr); sys.exit(1)
try:
    h = os.open(f"/home/{U}", os.O_PATH | os.O_NOFOLLOW | os.O_DIRECTORY)
    t = os.open(".tmp", os.O_PATH | os.O_NOFOLLOW | os.O_DIRECTORY, dir_fd=h)
except OSError as e: die(f"~/.tmp unusable ({e.strerror})")
hs, ts = os.fstat(h), os.fstat(t)
if hs.st_uid != UID or ts.st_uid != UID or ts.st_dev != hs.st_dev or not stat.S_ISDIR(ts.st_mode):
    die("~/.tmp is not the account's own directory")
src = f"/proc/self/fd/{t}".encode()
for dst in (b"/tmp", b"/var/tmp"):
    if libc.mount(src, dst, None, MS_BIND, None) != 0:
        die(f"bind {dst.decode()}: {os.strerror(ctypes.get_errno())}")
    if libc.mount(None, dst, None, MS_REMOUNT | MS_BIND | MS_NOSUID | MS_NODEV, None) != 0:
        die(f"remount {dst.decode()}: {os.strerror(ctypes.get_errno())}")
if libc.mount(b"tmpfs", b"/dev/shm", b"tmpfs", MS_NOSUID | MS_NODEV | MS_NOEXEC,
              f"mode=700,uid={UID},gid={GID},size=25%".encode()) != 0:
    die(f"tmpfs /dev/shm: {os.strerror(ctypes.get_errno())}")
os.close(t); os.close(h)
os.execvp(cmd[0], cmd)
PYT

# private_tmp_argv <user> <uid> <gid>: sets PTMP_ARGV, the prefix to exec the
# job through (empty when the account opted out). Creates a missing ~/.tmp
# as the account (never as root inside a customer home).
private_tmp_argv() {
  local u="$1" uid="$2" gid="$3"
  PTMP_ARGV=()
  [[ -e "$PTMP_OPTOUT/$u" ]] && return 0
  [[ -e "/home/$u/.tmp" || -L "/home/$u/.tmp" ]] || ( cd / && setpriv --reuid="$uid" --regid="$gid" --init-groups --reset-env -- mkdir -m 700 -- "/home/$u/.tmp" ) 2>/dev/null || true
  PTMP_ARGV=(unshare --mount --propagation private -- python3 -I -c "$PTMP_PY" "$u" "$uid" "$gid")
}
