#!/usr/bin/env bash
# disk-tree.sh <username> [relpath] — one capped listing for the Disk page.
# No relpath: per-top-level-dir totals ("domain<TAB>name<TAB>MB").
# With relpath: children of that folder ("dir|file<TAB>name<TAB>MB", top-30).
# Plus one "total<TAB>label<TAB>MB<TAB>files" line (files truncated at 100k).
# The controller passes the session username, never a POST field; relpath
# must resolve inside the home (symlink escapes refused, never followed).
# Capped: max-depth 1, whole script runs under the panel's `timeout 15`.
# Never follows symlinks (-P). Fails loudly.
set -euo pipefail
U="${1:?}"; REL="${2:-}"
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad user"; exit 1; }
H="/home/$U"
[[ -d "$H" ]] || { echo "no home for $U"; exit 1; }
if [[ -z "$REL" ]]; then
  N=0
  for d in "$H"/*/; do
    [[ -d "$d" ]] || continue
    N=$((N+1)); [[ $N -gt 30 ]] && { echo "truncated at 30 dirs"; break; }
    DN="${d%/}"; DN="${DN#$H/}"
    MB=$(du -sm -P "$d" 2>/dev/null | cut -f1 || echo 0)
    echo -e "domain\t$DN\t$MB"
  done
  TOTAL=$(du -sm -P "$H" 2>/dev/null | cut -f1)
  echo -e "total\t$H\t$TOTAL\t$(find -P "$H" -type f 2>/dev/null | head -100001 | wc -l)"
  exit 0
fi
# drilldown: strict relpath (no leading /, no .., safe chars per segment)
[[ "$REL" =~ ^[a-zA-Z0-9_.-]+(/[a-zA-Z0-9_.-]+)*$ ]] || { echo "bad path"; exit 1; }
[[ "$REL" != *"/../"* && "$REL" != "../"* && "$REL" != *"/.." ]] || { echo "bad path"; exit 1; }
D="$H/$REL"
[[ -d "$D" ]] || { echo "no such folder"; exit 1; }
case "$(realpath -m "$D")" in "$H"/*) ;; *) echo "escapes home"; exit 1;; esac
# children, biggest first. The trailing `|| true`: read hits EOF (nonzero),
# which under `set -e` would kill the script after a healthy run.
# (-m without -s: --summarize conflicts with --max-depth.)
du -m -P --max-depth=1 "$D" 2>/dev/null | sort -rn | head -31 | while read -r SMB SP; do
  [[ -z "${SP:-}" ]] && continue
  RP="$(realpath -m "$SP")"
  case "$RP" in "$H"/*) ;; *) continue;; esac
  [[ "$RP" == "$(realpath -m "$D")" ]] && continue
  NAME="${RP#$H/}"
  if [[ -d "$SP" && ! -L "$SP" ]]; then echo -e "dir\t$NAME\t$SMB";
  else echo -e "file\t$NAME\t$SMB"; fi
done || true
TOTAL=$(du -sm -P "$D" 2>/dev/null | cut -f1)
echo -e "total\t$REL\t$TOTAL\t$(find -P "$D" -type f 2>/dev/null | head -100001 | wc -l)"
