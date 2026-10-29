#!/usr/bin/env bash
# list-sync.sh — rebuild mailing-list maps from the panel DB. AUTHORITATIVE:
# removed lists/members vanish. Layout (taint-safe lookups only):
#   /etc/exim4/virtual/lists         — "list@domain: /etc/exim4/virtual/listmembers/<domain>/<list>"
#   .../listmembers/<domain>/<list>  — "member@addr: yes" per subscriber
#   /etc/exim4/virtual/listtargets   — "list@domain: m1@x, m2@y" (redirect data)
# Posting is subscribers-only (see virtual_list routers); outsiders get a
# bounce, never silence. Idempotent. Called after every list/member change.
set -euo pipefail
PASS="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
[[ -n "$PASS" ]] || { echo "no panel db pass (/root/.skypanel-dbpass)"; exit 1; }
VDIR="/etc/exim4/virtual"
MDIR="$VDIR/listmembers"
mkdir -p "$VDIR" "$MDIR"
for f in lists listtargets; do touch "$VDIR/$f"; done
TL="$VDIR/lists.tmp"; TT="$VDIR/listtargets.tmp"
: > "$TL"; : > "$TT"
COUNT=0
while IFS=$'\t' read -r ADDR D LOCAL LID; do
  [[ -n "${ADDR:-}" ]] || continue
  [[ "$ADDR" =~ ^[a-z0-9._-]+@[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "skip bad: $ADDR"; continue; }
  [[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ && "$LOCAL" =~ ^[a-z0-9._-]+$ && "$LID" =~ ^[0-9]+$ ]] || continue
  MEMBERS="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT address FROM email_list_members WHERE list_id=$LID ORDER BY address;" 2>/dev/null || true)"
  [[ -n "$MEMBERS" ]] || { echo "skip memberless: $ADDR"; continue; }
  MF="$MDIR/$D/$LOCAL"
  mkdir -p "$MDIR/$D"
  : > "$MF.tmp"
  CLEAN=""
  while IFS= read -r m; do
    [[ "$m" =~ ^[a-z0-9._-]+@[a-z0-9.-]+\.[a-z]{2,}$ ]] || continue
    [[ "${m,,}" != "${ADDR,,}" ]] || continue
    echo "$m: yes" >> "$MF.tmp"
    CLEAN="${CLEAN:+$CLEAN, }$m"
  done <<< "$MEMBERS"
  [[ -n "$CLEAN" ]] || { rm -f "$MF.tmp"; echo "skip memberless: $ADDR"; continue; }
  mv "$MF.tmp" "$MF"
  echo "$ADDR: $MF" >> "$TL"
  echo "$ADDR: $CLEAN" >> "$TT"
  COUNT=$((COUNT+1))
done < <(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT CONCAT(l.localpart,'@',d.domain), d.domain, l.localpart, l.id FROM email_lists l JOIN domains d ON d.id=l.domain_id;")
mv "$TL" "$VDIR/lists"
mv "$TT" "$VDIR/listtargets"
# prune member files for deleted lists
while IFS= read -r -d '' f; do
  rel="${f#$MDIR/}"
  D="${rel%/*}"; LOCAL="${rel##*/}"
  grep -qxF "$LOCAL@$D: $MDIR/$D/$LOCAL" "$VDIR/lists" 2>/dev/null || rm -f "$f" || true
done < <(find "$MDIR" -type f -print0 2>/dev/null) || true
find "$MDIR" -mindepth 1 -type d -empty -delete 2>/dev/null || true
echo "lists sync done, $COUNT active"
