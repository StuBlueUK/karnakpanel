#!/usr/bin/env bash
# autoreply-sync.sh — rebuild Exim vacation-reply data from the panel DB.
# AUTHORITATIVE (unlike email-reconcile.sh): disabled/deleted mailboxes vanish
# from the maps, so call this after every autoresponder/mailbox/domain change.
# Layout (taint-safe: Exim only ever reads literal lookup results, never
# envelope-derived paths):
#   /etc/exim4/virtual/autoreplies  — "box@domain: /var/lib/skypanel/autoreply/<domain>/<box>"
#   .../autoreply/<domain>/<box>.subj  — single-line subject (≤190)
#   .../autoreply/<domain>/<box>.msg   — body with real newlines (≤2000 chars)
#   .../autoreply/<domain>/<box>.once  — Exim once-DB (one reply per sender/7d)
# Optional date window (autoresp_start/_end, inclusive, server-local): only
# replies inside it are published; cron re-runs this hourly so a window
# opens and closes on its own.
# Idempotent. Files land before the map swap; removals go map-first.
set -euo pipefail
PASS="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
[[ -n "$PASS" ]] || { echo "no panel db pass (/root/.skypanel-dbpass)"; exit 1; }
VDIR="/etc/exim4/virtual"
BASE="/var/lib/skypanel/autoreply"
mkdir -p "$VDIR" "$BASE"
# Exim (Debian-exim) must read subj/msg and WRITE once files
chown Debian-exim:Debian-exim "$BASE" 2>/dev/null || chown vmail:vmail "$BASE" 2>/dev/null || true
MAP="$VDIR/autoreplies"
TMPMAP="$MAP.tmp"
: > "$TMPMAP"
COUNT=0
while IFS=$'\t' read -r ADDR D LOCAL SUBJ MSG; do
  [[ -n "${ADDR:-}" ]] || continue
  [[ "$ADDR" =~ ^[a-z0-9._-]+@[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "skip bad address: $ADDR"; continue; }
  # belt & braces: domain/local validated shapes only (no slashes possible)
  [[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ && "$LOCAL" =~ ^[a-z0-9._-]+$ ]] || { echo "skip bad parts: $ADDR"; continue; }
  S="$(printf '%s' "$SUBJ" | tr -d '\r\n' | head -c 190)"
  M="$(printf '%s' "$MSG" | head -c 2000)"
  [[ -n "$S" && -n "$M" ]] || { echo "skip incomplete: $ADDR"; continue; }
  MD="$BASE/$D"
  mkdir -p "$MD"
  chown Debian-exim:Debian-exim "$MD" 2>/dev/null || true
  # NOTE: subject file has NO trailing newline — readfile() feeds Exim's
  # subject expansion verbatim and \n there aborts the transport
  printf '%s' "$S" > "$MD/$LOCAL.subj.tmp"
  printf '%s' "$M" > "$MD/$LOCAL.msg.tmp"
  chown Debian-exim:Debian-exim "$MD/$LOCAL.subj.tmp" "$MD/$LOCAL.msg.tmp" 2>/dev/null || true
  chmod 640 "$MD/$LOCAL.subj.tmp" "$MD/$LOCAL.msg.tmp"
  mv "$MD/$LOCAL.subj.tmp" "$MD/$LOCAL.subj"
  mv "$MD/$LOCAL.msg.tmp" "$MD/$LOCAL.msg"
  echo "$ADDR: $MD/$LOCAL" >> "$TMPMAP"
  COUNT=$((COUNT+1))
done < <(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT CONCAT(e.localpart,'@',d.domain), d.domain, e.localpart, REPLACE(e.autoresp_subject,'\t',' '), e.autoresp_text FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE e.autoresp_enabled=1 AND (e.autoresp_start IS NULL OR e.autoresp_start<=CURDATE()) AND (e.autoresp_end IS NULL OR e.autoresp_end>=CURDATE());")
mv "$TMPMAP" "$MAP"
# prune message files for mailboxes no longer enabled (stale .once would
# suppress future replies; stale .msg could answer for deleted boxes)
while IFS= read -r -d '' f; do
  rel="${f#$BASE/}"; rel="${rel%.*}"
  D="${rel%/*}"; LOCAL="${rel##*/}"
  grep -qxF "$LOCAL@$D: $BASE/$D/$LOCAL" "$MAP" 2>/dev/null || rm -f "$BASE/$D/$LOCAL.subj" "$BASE/$D/$LOCAL.msg" "$BASE/$D/$LOCAL.once" || true
done < <(find "$BASE" -type f \( -name '*.subj' -o -name '*.msg' -o -name '*.once' \) -print0 2>/dev/null) || true
find "$BASE" -mindepth 1 -type d -empty -delete 2>/dev/null || true
echo "autoreply sync done, $COUNT active"
