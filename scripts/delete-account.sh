#!/usr/bin/env bash
# delete-account.sh <username> — full teardown of a hosting account. Idempotent:
# every step tolerates already-gone pieces. ORDER: system artifacts first
# (vhosts, zones, pools, crontab, mail maps), home dir + user last.
# NEVER touches panel DB rows (controller does that) or customer MySQL DBs
# (controller drops those via the provision handle first).
set -euo pipefail
U="${1:?}"
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad user"; exit 1; }
[[ "$U" == "admin" || "$U" == "root" ]] && { echo "refusing to delete $U"; exit 1; }
# a user that is still there must be a hosting account (never a distro/sudo user)
. "$(dirname "$(readlink -f "$0")")/account-check.sh"
if getent passwd "$U" >/dev/null 2>&1; then panel_account "$U" || { echo "refusing to delete $U: $ACCOUNT_CHECK_WHY"; exit 1; }; fi
D="$(dirname "$0")"

# per-domain teardown (vhost+zone); mail aliases die with the mailbox rows via email-remove
# NOTE: layout is /home/<user>/<domain>/public_html — skip home root entries
for d in /home/$U/*/; do
  [[ -d "$d" ]] || continue
  DN="$(basename "$d")"
  [[ "$DN" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || continue
  "$D/vhost-remove.sh" "$DN" "$U" 2>/dev/null || true
  "$D/dns-remove-zone.sh" "$DN" 2>/dev/null || true
done
# FPM pools (also covered by last-vhost logic, but be thorough)
"$D/php-pool-remove.sh" "$U" 2>/dev/null || true
# crontab + ftp allow-list + per-mailbox FTP system users (owner_suffix convention)
crontab -r -u "$U" 2>/dev/null || true
rm -f -- "/etc/cron.d/skypanel-user-$U"; rm -rf -- "/var/lib/karnakpanel/cron/$U"
sed -i "/^$U\$/d;/^${U}_/d" /etc/vsftpd.userlist 2>/dev/null || true
for F in $(getent passwd | cut -d: -f1 | grep "^${U}_" || true); do
  crontab -r -u "$F" 2>/dev/null || true
  userdel -r "$F" 2>/dev/null || userdel "$F" 2>/dev/null || true
done
# FPM pools (a pool referencing a deleted UID makes php-fpm REFUSE TO START —
# exit 78 — so this must run while/after the user still resolves; userdel is last)
"$D/php-pool-remove.sh" "$U" 2>/dev/null || true
# supervised apps: units + root-only command store (a unit left behind would
# start under the freed UID, i.e. as the next account that gets it)
"$D/app-daemon.sh" purge "$U" 2>/dev/null || true
rm -f -- "$SUSPENDED_DIR/$U"
# processes, home, account
pkill -u "$U" 2>/dev/null || true
sleep 1
userdel -r "$U" 2>/dev/null || userdel "$U" 2>/dev/null || true
# incremental snapshots for the user (tarballs under /backup/skypanel/ are
# deliberately KEPT for restores — see the delete flash — but per-day
# hardlinked trees of a deleted account are unrestorable dead weight)
rm -rf "/backup/skypanel/inc/$U" 2>/dev/null || true
# per-user FPM error log (php-pool.sh): owned by the freed UID, which the
# next new account reuses — it would inherit the old account's errors
rm -f "/var/log/karnakpanel/php-$U.error.log" 2>/dev/null || true
echo "account $U deleted"
