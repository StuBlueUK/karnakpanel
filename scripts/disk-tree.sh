#!/usr/bin/env bash
# disk-tree.sh <username> [relpath] — one capped listing for the Disk page.
# Sizes are MB with 2 decimals from du -k (du -m rounds every tiny folder up
# to "1 MB", so an empty ~/backup read as 1 MB next to the real backups).
# No relpath (overview), one line each:
#   top<TAB>name<TAB>MB       every top-level folder, hidden ones included
#                             (biggest 60; the rest summed as "more")
#   loose<TAB>files<TAB>MB    files sitting directly in the home
#   mail<TAB>domain<TAB>MB    /var/mail/virtual/<domain> for each home folder
#                             that is a mail domain (counts toward quota)
#   backups<TAB>count<TAB>MB  this account's tarballs in /backup/skypanel
#   snapshots<TAB>inc<TAB>MB  its incremental day-dirs (hardlinked: unique)
#   total<TAB>home<TAB>MB<TAB>files   (files truncated at 100k)
# With relpath: children of that folder ("dir|file<TAB>name<TAB>MB", top-30).
# Backups/snapshots live outside the home and never count toward quota.
# The controller passes the session username, never a POST field; relpath
# must resolve inside the home (symlink escapes refused, never followed).
# Never follows symlinks (-P). Fails loudly.
set -euo pipefail
U="${1:?}"; REL="${2:-}"
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad user"; exit 1; }
H="/home/$U"
[[ -d "$H" ]] || { echo "no home for $U"; exit 1; }
mb() { awk -v k="${1:-0}" 'BEGIN{printf "%.2f", k/1024}'; }
safe() { local s="${1//$'\t'/?}"; printf '%s' "${s//$'\n'/?}"; }
files() { find -P "$1" -type f 2>/dev/null | head -100001 | wc -l; }

if [[ -z "$REL" ]]; then
  # one walk: every child's size plus the home total (last line)
  TOTAL=0; SUM=0; N=0; MORE=0; NAMES=()
  while IFS=$'\t' read -r -d '' KB P; do
    if [[ "$P" == "$H" ]]; then TOTAL="$KB"; continue; fi
    SUM=$((SUM + KB)); N=$((N + 1))
    if (( N > 60 )); then MORE=$((MORE + KB)); continue; fi
    NM="${P#$H/}"; NAMES+=("$NM")
    echo -e "top\t$(safe "$NM")\t$(mb "$KB")"
  done < <(du -k -P -0 --max-depth=1 "$H" 2>/dev/null | sort -z -t$'\t' -k1,1rn)
  (( MORE > 0 )) && echo -e "top\t(more folders)\t$(mb "$MORE")"
  # files directly in the home = total - folders - the home dir's own block
  LOOSE=$(( TOTAL - SUM - 4 )); (( LOOSE < 0 )) && LOOSE=0
  echo -e "loose\tfiles\t$(mb "$LOOSE")"
  for NM in "${NAMES[@]}"; do
    [[ "$NM" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ && -d "/var/mail/virtual/$NM" ]] || continue
    KB=$(du -sk -P "/var/mail/virtual/$NM" 2>/dev/null | cut -f1 || echo 0)
    echo -e "mail\t$NM\t$(mb "$KB")"
  done
  BN=0; BB=0
  for f in /backup/skypanel/"$U"-*.tar.gz; do
    [[ -f "$f" ]] || continue
    BN=$((BN + 1)); BB=$((BB + $(stat -c %s "$f")))
  done
  echo -e "backups\t$BN\t$(mb $((BB / 1024)))"
  if [[ -d "/backup/skypanel/inc/$U" ]]; then
    # capped: 7 hardlinked day-trees can be slow to walk on a big account
    KB=$(timeout 8 du -sk -P "/backup/skypanel/inc/$U" 2>/dev/null | cut -f1) && echo -e "snapshots\tinc\t$(mb "$KB")"
  fi
  echo -e "total\thome\t$(mb "$TOTAL")\t$(files "$H")"
  exit 0
fi
# drilldown: strict relpath (no leading /, no .., safe chars per segment)
[[ "$REL" =~ ^[a-zA-Z0-9_.-]+(/[a-zA-Z0-9_.-]+)*$ ]] || { echo "bad path"; exit 1; }
[[ "$REL" != *"/../"* && "$REL" != "../"* && "$REL" != *"/.." ]] || { echo "bad path"; exit 1; }
D="$H/$REL"
[[ -d "$D" ]] || { echo "no such folder"; exit 1; }
RD="$(realpath -m "$D")"
case "$RD" in "$H"/*) ;; *) echo "escapes home"; exit 1;; esac
# children, biggest first (-a: files too; the folder itself is skipped).
# The trailing `|| true`: read hits EOF (nonzero), which under `set -e`
# would kill the script after a healthy run.
# Sorted biggest first, so the folder itself (>= any child) comes first:
# stop after 30 children instead of resolving thousands of small files.
TOTAL=; N=0
while IFS=$'\t' read -r -d '' KB SP; do
  if [[ "$SP" == "$D" ]]; then TOTAL="$KB"; continue; fi
  if (( N >= 30 )); then [[ -n "$TOTAL" ]] && break; continue; fi
  RP="$(realpath -m "$SP")"
  case "$RP" in "$H"/*) ;; *) continue;; esac
  N=$((N + 1))
  NAME="$(safe "${RP#$H/}")"
  if [[ -d "$SP" && ! -L "$SP" ]]; then echo -e "dir\t$NAME\t$(mb "$KB")";
  else echo -e "file\t$NAME\t$(mb "$KB")"; fi
done < <(du -k -a -P -0 --max-depth=1 "$D" 2>/dev/null | sort -z -t$'\t' -k1,1rn) || true
[[ -n "$TOTAL" ]] || TOTAL=$(du -sk -P "$D" 2>/dev/null | cut -f1)
echo -e "total\t$REL\t$(mb "$TOTAL")\t$(files "$D")"
