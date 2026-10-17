#!/usr/bin/env bash
# mail-queue.sh <summary|flush|frozen|thaw-frozen|drop-frozen|drop-all|list|retry-one|drop-one|show-one|errors|retry-when>
# Exim queue ops for the panel (runs as root).
# summary: counts; flush: one queue run; frozen: list frozen ids ( capped );
# thaw-frozen: retry frozen; drop-frozen: delete frozen (dead bounces);
# drop-all: EMPTIES the queue (spam floods) — panel confirms twice.
# list: one "id|age|size|sender|recipients|frozen" line per message (cap 500,
# prints truncated=1 when capped); retry-one/drop-one/show-one take one Exim
# message id (strict shape, enforced here AND in the panel).
set -euo pipefail
A="${1:-summary}"
IDRE='^[A-Za-z0-9]{6}-[A-Za-z0-9]{2,}-[A-Za-z0-9]{2,}$'
case "$A" in
  summary)
    echo "queued: $(exim4 -bpc 2>/dev/null)"
    echo "frozen: $(exim4 -bp 2>/dev/null | grep -c '\*\*\* frozen \*\*\*')"
    ;;
  flush)
    exim4 -qff 2>&1 | tail -1
    echo "flushed, queued now: $(exim4 -bpc 2>/dev/null)"
    ;;
  frozen)
    exim4 -bp 2>/dev/null | grep -B1 '\*\*\* frozen \*\*\*' | grep -oE '[0-9A-Za-z]{6}-[0-9A-Za-z-]{6,}' | head -20
    ;;
  thaw-frozen)
    n=0
    while IFS= read -r id; do
      [[ "$id" =~ ^[0-9A-Za-z]{6}-[0-9A-Za-z-]{6,}$ ]] || continue
      exim4 -M "$id" 2>/dev/null && n=$((n+1)) || true
    done < <(exim4 -bp 2>/dev/null | grep -B1 '\*\*\* frozen \*\*\*' | grep -oE '[0-9A-Za-z]{6}-[0-9A-Za-z-]{6,}' | head -50)
    echo "thawed $n frozen message(s) for retry"
    ;;
  drop-frozen)
    n=0
    while IFS= read -r id; do
      [[ "$id" =~ ^[0-9A-Za-z]{6}-[0-9A-Za-z-]{6,}$ ]] || continue
      exim4 -Mrm "$id" 2>/dev/null && n=$((n+1)) || true
    done < <(exim4 -bp 2>/dev/null | grep -B1 '\*\*\* frozen \*\*\*' | grep -oE '[0-9A-Za-z]{6}-[0-9A-Za-z-]{6,}' | head -50)
    echo "removed $n frozen message(s), queued now: $(exim4 -bpc 2>/dev/null)"
    ;;
  drop-all)
    # mktemp, not a PID name: /tmp is shared and PIDs are guessable.
    DROPLIST="$(mktemp)"
    exim4 -bp 2>/dev/null | grep -oE '[0-9A-Za-z]{6}-[0-9A-Za-z-]{6,}' | head -500 > "$DROPLIST" || true
    n=0
    while IFS= read -r id; do
      exim4 -Mrm "$id" 2>/dev/null && n=$((n+1)) || true
    done < "$DROPLIST"; rm -f "$DROPLIST"
    echo "removed $n message(s), queued now: $(exim4 -bpc 2>/dev/null)"
    ;;
  list)
    # parse `exim4 -bp`: "<age> <size> <id> <sender> [*** frozen ***]" then
    # indented recipient lines until the blank line. Fields are scrubbed of
    # the | delimiter (addresses never contain it; belt and braces anyway).
    exim4 -bp 2>/dev/null | awk '
      /^ *[0-9]+[smhd] / {
        if (id != "") print rec
        # positional fields lie: ages can be "2h 15m" (two fields). Find the
        # message id by shape; age = clock bits before it, size = bare number.
        age=""; size=""; id=""; sender=""; frozen=0
        for (i=1; i<=NF; i++) {
          if ($i ~ /^[A-Za-z0-9]{6}-[A-Za-z0-9]{2,}-[A-Za-z0-9]{2,}$/) id=$i
          else if ($i ~ /^<.*>$/) { sender=$i; gsub(/[<>|]/, "", sender) }
          else if ($i == "***") frozen=1
          else if (id == "" && $i ~ /^[0-9]+[smhd]$/) age=(age == "" ? $i : age " " $i)
          else if (id == "" && size == "" && $i ~ /^[0-9.]+[KM]?$/) size=$i
        }
        if (id == "") next
        gsub(/\|/, "", age); gsub(/\|/, "", size)
        n++; if (n > 500) { print "truncated=1"; exit }
        nrc=0; rcpts=""
        rec=id "|" age "|" size "|" sender "||" frozen
        next
      }
      id != "" && /^ +[^ *]/ {
        r=$0; gsub(/^ +/, "", r); gsub(/\|/, "", r)
        nrc++
        if (nrc <= 3) rcpts=(rcpts == "" ? r : rcpts "," r)
        disp=rcpts; if (nrc > 3) disp=disp " (+" (nrc-3) " more)"
        rec=id "|" age "|" size "|" sender "|" disp "|" frozen
      }
      /^$/ { if (id != "") { print rec; id="" } }
      END { if (id != "") print rec }
    '
    ;;
  retry-one|drop-one)
    ID="${2:-}"
    [[ "$ID" =~ $IDRE ]] || { echo "REFUSED: bad message id"; exit 1; }
    if [[ "$A" == "retry-one" ]]; then
      # -Mt (thaw, requeue) NOT -M: a forced delivery attempt runs inside the
      # web request and hangs it on dead routes (blackhole connects ignore
      # TERM past the sudo timeout). The -q30m runner attempts within 30 min;
      # "Run queue now" forces it sooner.
      exim4 -Mt "$ID" 2>&1 | tail -1 && echo "requeued $ID — next queue run attempts delivery"
    else
      exim4 -Mrm "$ID" 2>&1 | tail -1 && echo "deleted $ID, queued now: $(exim4 -bpc 2>/dev/null)"
    fi
    ;;
  show-one)
    ID="${2:-}"
    [[ "$ID" =~ $IDRE ]] || { echo "REFUSED: bad message id"; exit 1; }
    exim4 -Mvh "$ID" 2>&1 | head -60
    echo "---LOG---"
    grep -F "$ID" /var/log/exim4/mainlog 2>/dev/null | tail -8 || true
    ;;
  # errors <id>... (cap 25): last stuck reason per message as "id|text"
  # (bounced/deferred mainlog lines, newest wins). Read-only, one call.
  errors)
    shift
    n=0
    for ID in "$@"; do
      [[ "$ID" =~ $IDRE ]] || continue
      n=$((n+1)); [[ "$n" -gt 25 ]] && break
      line="$(grep -F "$ID" /var/log/exim4/mainlog 2>/dev/null | grep -E " (=>|\*\*|==) " | tail -1 || true)"
      if [[ -z "$line" ]]; then echo "$ID|"; continue; fi
      if [[ "$line" == *" ** "* ]]; then kind="bounced";
      elif [[ "$line" == *" == "* ]]; then kind="deferred"; else kind="delivered"; fi
      text="$(echo "$line" | sed -E 's/^[^ ]+ [^ ]+ [^ ]+ (=>|\*\*|==) //')"
      text="${text//|/}"; echo "$ID|$kind: ${text:0:120}"
    done
    ;;
  # retry-when <addr>: next delivery attempt for a recipient (View card).
  retry-when)
    ADDR="${2:-}"
    [[ "$ADDR" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[a-zA-Z]{2,}$ ]] || { echo "REFUSED: bad address"; exit 1; }
    exim4 -brt "$ADDR" 2>&1 | head -3
    ;;
  *) echo "usage: summary|flush|frozen|thaw-frozen|drop-frozen|drop-all|list|retry-one|drop-one|show-one|errors|retry-when"; exit 1;;
esac
