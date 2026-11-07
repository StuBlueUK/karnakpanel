#!/usr/bin/env bash
# backup-store.sh usage [user]          — backup storage totals, one line each:
#   tarballs<TAB>count<TAB>bytes        account tarballs (all accounts if no user)
#   snapshots<TAB>kb                    incremental day-dirs (/backup/skypanel/inc)
#   dumps<TAB>count<TAB>bytes           box-level db-*.sql.gz      (no user only)
#   restic<TAB>kb                       restic repo /backup/restic (no user only)
#   free<TAB>bytes<TAB>size_bytes       filesystem holding /backup (no user only)
# backup-store.sh delete <user> <file>… — remove that account's own tarballs.
#   Only <user>-YYYYMMDD-HHMMSS.tar.gz names (the controller already checked
#   ownership against the backups table; this re-checks the name prefix so a
#   row can never point it at another account's file or a db dump). Missing
#   files are fine (row unlisted by the caller). Prints "deleted <file>" each.
# Walks are capped with timeout: inc trees are hardlinked and can be large.
set -euo pipefail
B=/backup/skypanel
M="${1:-}"; shift || true
case "$M" in
usage)
  U="${1:-}"
  [[ -z "$U" || "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad user"; exit 1; }
  N=0; SZ=0
  for f in "$B"/${U:-*}-*.tar.gz; do
    [[ -f "$f" ]] || continue
    # nosemgrep: bash.lang.correctness.unquoted-expansion.unquoted-command-substitution-in-command -- arithmetic context, no word splitting
    N=$((N + 1)); SZ=$((SZ + $(stat -c %s "$f")))
  done
  echo -e "tarballs\t$N\t$SZ"
  D="$B/inc${U:+/$U}"
  if [[ -d "$D" ]]; then
    KB=$(timeout 10 du -sk -P "$D" 2>/dev/null | cut -f1) && echo -e "snapshots\t$KB"
  fi
  if [[ -z "$U" ]]; then
    N=0; SZ=0
    for f in "$B"/db-*.sql.gz; do
      [[ -f "$f" ]] || continue
      # nosemgrep: bash.lang.correctness.unquoted-expansion.unquoted-command-substitution-in-command -- arithmetic context, no word splitting
      N=$((N + 1)); SZ=$((SZ + $(stat -c %s "$f")))
    done
    echo -e "dumps\t$N\t$SZ"
    if [[ -d /backup/restic ]]; then
      KB=$(timeout 10 du -sk -P /backup/restic 2>/dev/null | cut -f1) && echo -e "restic\t$KB"
    fi
    df -B1 --output=avail,size /backup 2>/dev/null | awk 'NR==2{print "free\t"$1"\t"$2}'
  fi
  ;;
delete)
  U="${1:-}"; shift || true
  [[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad user"; exit 1; }
  (( $# >= 1 && $# <= 200 )) || { echo "need 1-200 files"; exit 1; }
  for F in "$@"; do
    [[ "$F" =~ ^${U}-[0-9]{8}-[0-9]{6}\.tar\.gz$ ]] || { echo "refused: $F is not a $U backup"; exit 1; }
    [[ ! -L "$B/$F" ]] || { echo "refused: $F is a symlink"; exit 1; }
  done
  # all names checked first: a bad one refuses the whole batch, nothing removed
  for F in "$@"; do
    rm -f -- "$B/$F"
    echo "deleted $F"
  done
  ;;
*) echo "usage: backup-store.sh usage [user] | delete <user> <file>…"; exit 1 ;;
esac
