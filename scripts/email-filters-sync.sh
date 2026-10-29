#!/usr/bin/env bash
# email-filters-sync.sh — rebuild sieve scripts + Exim filter map from the DB.
# Boxes WITH active rules get /var/lib/skypanel/sieve/<domain>/<box>.sieve
# (compiled) and a line in /etc/exim4/virtual/filtered, which routes their
# mail through Dovecot LMTP (sieve runs there). Boxes WITHOUT rules keep the
# direct Exim->Maildir path untouched. A failed sievec compile SKIPS the box
# (mail still delivers; the error is loud in the panel log path).
# Idempotent. Called after every filter/mailbox change.
set -euo pipefail
PASS="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
[[ -n "$PASS" ]] || { echo "no panel db pass (/root/.skypanel-dbpass)"; exit 1; }
VDIR="/etc/exim4/virtual"
SIEVE_BASE="/var/lib/skypanel/sieve"
mkdir -p "$VDIR" "$SIEVE_BASE"
MAP="$VDIR/filtered"
TMPMAP="$MAP.tmp"
: > "$TMPMAP"
# all boxes that have at least one ACTIVE rule
while IFS=$'\t' read -r ADDR D LOCAL; do
  [[ -n "${ADDR:-}" ]] || continue
  [[ "$ADDR" =~ ^[a-z0-9._-]+@[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "skip bad: $ADDR"; continue; }
  [[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ && "$LOCAL" =~ ^[a-z0-9._-]+$ ]] || continue
  SD="$SIEVE_BASE/$D"
  mkdir -p "$SD"
  {
    echo 'require ["fileinto"];'
    echo "# KarnakPanel filters for $ADDR (managed — do not hand-edit)"
    # column order matters: IFS=tab collapses empty fields, so the one that
    # can be empty (target, for discard) stays last
    MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT field, match_type, match_text, action, target FROM email_filters WHERE domain_id=(SELECT id FROM domains WHERE domain='$D' LIMIT 1) AND localpart='$LOCAL' AND active=1 ORDER BY id;" 2>/dev/null | \
    while IFS=$'\t' read -r F MT M A T; do
      [[ "$F" == subject || "$F" == from || "$F" == to || "$F" == cc || "$F" == spam ]] || continue
      [[ "$MT" == contains || "$MT" == is || "$MT" == starts || "$MT" == ge ]] || continue
      [[ "$A" == folder || "$A" == discard || "$A" == redirect ]] || continue
      M="$(printf '%s' "$M" | tr -d '\r\n' | head -c 160)"
      # starts-with is a :matches glob: the user's own * ? \ are literals
      [[ "$MT" == starts ]] && M="$(printf '%s' "$M" | sed 's/\\/\\\\/g; s/\*/\\*/g; s/?/\\?/g')"
      ME="$(printf '%s' "$M" | sed 's/\\/\\\\/g; s/"/\\"/g')"
      TE="$(printf '%s' "$T" | tr -d '\r\n' | sed 's/\\/\\\\/g; s/"/\\"/g' | head -c 190)"
      [[ -n "$ME" ]] || continue
      # discard needs no target; folder/redirect do
      if [[ "$A" != discard ]]; then [[ -n "$TE" ]] || continue; fi
      # folder targets must EXIST as Maildir++ dirs — sieve fileinto does not
      # create them (fails to implicit keep instead). Dots nest (.a.b).
      if [[ "$A" == folder ]]; then
        [[ "$TE" =~ ^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)*$ ]] || continue
        VMB="/var/mail/virtual/$D/$LOCAL"
        CUR="$VMB"
        IFS='.' read -ra PARTS <<< "$TE"
        # Maildir++ subfolders live DOT-prefixed INSIDE the box (.Bulk),
        # nesting as .a/.b — never dot-suffixed siblings (flt1.Bulk).
        for pp in "${PARTS[@]}"; do
          CUR="$CUR/.$pp"
          mkdir -p "$CUR"/{cur,new,tmp} 2>/dev/null || true
          touch "$CUR/maildirfolder" 2>/dev/null || true
        done
        chown -R vmail:vmail "$VMB" 2>/dev/null || true
      fi
      # the test: spam = at least N "+" in the X-Spam-Score bar ("3.6 (+++)";
      # negative scores carry "-", so never match). from/to/cc "is" and
      # "starts" compare the bare address; "contains" searches the header.
      if [[ "$F" == spam ]]; then
        [[ "$M" =~ ^[0-9]{1,2}$ ]] || continue
        TEST="header :contains \"X-Spam-Score\" \"($(printf '+%.0s' $(seq 1 "$M"))\""
      elif [[ "$MT" == contains ]]; then TEST="header :contains \"$F\" \"$ME\""
      elif [[ "$F" == subject && "$MT" == is ]]; then TEST="header :is \"subject\" \"$ME\""
      elif [[ "$F" == subject ]]; then TEST="header :matches \"subject\" \"$ME*\""
      elif [[ "$MT" == is ]]; then TEST="address :is \"$F\" \"$ME\""
      else TEST="address :matches \"$F\" \"$ME*\""; fi
      case "$A" in
        folder) echo "if $TEST { fileinto \"$TE\"; stop; }" ;;
        discard) echo "if $TEST { discard; stop; }" ;;
        redirect) echo "if $TEST { redirect \"$TE\"; stop; }" ;;
      esac
    done
    echo "keep;"
  } > "$SD/$LOCAL.sieve.tmp"
  if sievec "$SD/$LOCAL.sieve.tmp" "$SD/$LOCAL.svbin.tmp" 2>/dev/null; then
    mv "$SD/$LOCAL.sieve.tmp" "$SD/$LOCAL.sieve"
    # recompile from the FINAL path: the binary records its source script
    # name, and Dovecot 2.4 treats a .tmp-named one as stale and recompiles
    # on every delivery (vmail can't write the root-owned dir: error per mail)
    sievec "$SD/$LOCAL.sieve" "$SD/$LOCAL.svbin.tmp" 2>/dev/null || true
    mv "$SD/$LOCAL.svbin.tmp" "$SD/$LOCAL.svbin"
    chown vmail:vmail "$SD/$LOCAL.sieve" "$SD/$LOCAL.svbin" 2>/dev/null || true
    chmod 640 "$SD/$LOCAL.sieve" "$SD/$LOCAL.svbin"
  echo "$ADDR: yes" >> "$TMPMAP"
  else
    rm -f "$SD/$LOCAL.sieve.tmp" "$SD/$LOCAL.svbin.tmp"
    echo "SIEVE COMPILE FAILED for $ADDR — direct delivery kept"
  fi
done < <(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT DISTINCT CONCAT(e.localpart,'@',d.domain), d.domain, e.localpart FROM email_filters e JOIN domains d ON d.id=e.domain_id WHERE e.active=1;")
mv "$TMPMAP" "$MAP"
COUNT="$(grep -c . "$MAP" 2>/dev/null || echo 0)"
# prune sieve files for boxes with no active rules left
while IFS= read -r -d '' f; do
  rel="${f#$SIEVE_BASE/}"; rel="${rel%.*}"
  D="${rel%/*}"; LOCAL="${rel##*/}"
  grep -qxF "$LOCAL@$D: yes" "$MAP" 2>/dev/null || rm -f "$SIEVE_BASE/$D/$LOCAL.sieve" "$SIEVE_BASE/$D/$LOCAL.svbin" || true
done < <(find "$SIEVE_BASE" -type f \( -name '*.sieve' -o -name '*.svbin' \) -print0 2>/dev/null) || true
find "$SIEVE_BASE" -mindepth 1 -type d -empty -delete 2>/dev/null || true
echo "filters sync done, $COUNT boxed"
