#!/usr/bin/env bash
# email-reconcile.sh — repair virtual-mail maps/dirs from the panel DB + migrate
# legacy ~/Maildir mail into the right virtual boxes. IDEMPOTENT, additive-only:
# it never deletes map lines or mail (orphans are REPORTED for manual review).
#
# Migration: pre-virtual mail landed in the owner's ~/Maildir (localuser router).
# Each Maildir file carries Envelope-to/X-Original-To headers, so messages are
# routed per-recipient into /var/mail/virtual/<domain>/<box>/new.
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/as-user.sh"   # ~/Maildir is read/removed as the owner
PASS="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
[[ -n "$PASS" ]] || { echo "no panel db pass (/root/.skypanel-dbpass)"; exit 1; }
# SQL string body: backslashes too (\' would otherwise escape the closing quote)
sqlq() { local s="${1//\\/\\\\}"; printf '%s' "${s//\'/\'\'}"; }
MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "SELECT 1 FROM email_accounts LIMIT 1;" >/dev/null 2>&1 \
  || { echo "panel mail tables not ready — skipping"; exit 0; }

mkdir -p /etc/exim4/virtual /var/mail/virtual
touch /etc/exim4/virtual/domains /etc/exim4/virtual/aliases /etc/exim4/virtual/maildirs /etc/exim4/virtual/forwarders /etc/exim4/virtual/remote /etc/exim4/virtual/reports
chown vmail:vmail /var/mail/virtual 2>/dev/null || true

# remote-handling domains own exactly one line each in the remote map
# (delete-then-add per row, same pattern as forwarders below — converge,
# never accumulate; orphans reported, never auto-deleted... except a row
# that flipped back to local, whose line MUST go or mail misroutes)
while IFS=$'\t' read -r D MODE; do
  [[ -n "$D" ]] || continue
  ESC="${D//./\\.}"
  sed -i "/^$ESC: /d" /etc/exim4/virtual/remote 2>/dev/null || true
  if [[ "$MODE" == remote ]]; then echo "$D: remote" >> /etc/exim4/virtual/remote; echo "ensured remote-route $D"; fi
done < <(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT domain, COALESCE(mail_handling,'local') FROM domains;" 2>/dev/null) || true
while IFS= read -r line; do RD="${line%%:*}"; RQ="$(sqlq "$RD")"
  MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT 1 FROM domains WHERE domain='$RQ' AND mail_handling='remote' LIMIT 1;" | grep -q 1 \
    || echo "orphan remote-route (manual review): $line"
done < /etc/exim4/virtual/remote

MOVED=0
while IFS=$'\t' read -r ADDR D LOCAL OWNER; do
  [[ -n "$ADDR" ]] || continue
  grep -qxF "$D: ok" /etc/exim4/virtual/domains 2>/dev/null || { echo "$D: ok" >> /etc/exim4/virtual/domains; echo "added domain $D"; }
  grep -qxF "$ADDR: virtual" /etc/exim4/virtual/aliases 2>/dev/null || { echo "$ADDR: virtual" >> /etc/exim4/virtual/aliases; echo "added alias $ADDR"; }
  grep -qxF "$ADDR: /var/mail/virtual/$D/$LOCAL" /etc/exim4/virtual/maildirs 2>/dev/null || { echo "$ADDR: /var/mail/virtual/$D/$LOCAL" >> /etc/exim4/virtual/maildirs; echo "added maildir map $ADDR"; }
  VDIR="/var/mail/virtual/$D/$LOCAL"
  mkdir -p "$VDIR"/{new,cur,tmp}
  chown -R vmail:vmail "$VDIR"; chmod 700 "$VDIR"
  # migrate legacy mail for this address out of the owner's ~/Maildir
  ESC="${ADDR//./\\.}"
  LEGACY="/home/$OWNER/Maildir"
  # the home is the customer's: root never opens a path in it (a folder swapped
  # for a link between find and mv would steer root). The owner reads and
  # removes; root only writes the copy into the vmail-owned box.
  if [[ ! -L "/home/$OWNER" ]] && as_user "$OWNER" test -d "$LEGACY"; then
    while IFS= read -r -d '' f; do
      as_user "$OWNER" grep -qiE "^(envelope-to|x-original-to):[[:space:]]*$ESC$" -- "$f" 2>/dev/null || continue
      n="$(basename "$f")"; T="$VDIR/new/$n"
      [[ -e "$T" ]] && continue
      if as_user "$OWNER" cat -- "$f" > "$VDIR/tmp/$n" && chown vmail:vmail "$VDIR/tmp/$n" && chmod 600 "$VDIR/tmp/$n" && mv -T -- "$VDIR/tmp/$n" "$T"; then
        as_user "$OWNER" rm -f -- "$f" || echo "WARN: copied but could not remove $f"
        echo "migrated $n -> $ADDR"
        MOVED=$((MOVED+1))
      else
        rm -f -- "$VDIR/tmp/$n"; echo "WARN: could not migrate $n"
      fi
    done < <(as_user "$OWNER" find "$LEGACY"/new "$LEGACY"/cur -maxdepth 1 -type f -print0 2>/dev/null)
  fi
done < <(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT CONCAT(e.localpart,'@',d.domain), d.domain, e.localpart, u.username FROM email_accounts e JOIN domains d ON d.id=e.domain_id JOIN users u ON u.id=e.user_id;")

# rebuild forwarder lines from the DB (additive — same orphan policy as aliases)
while IFS=$'\t' read -r SRC TGTS; do
  [[ -n "$SRC" ]] || continue
  SESQ="${SRC//./\\.}"
  sed -i "/^$SESQ: /d" /etc/exim4/virtual/forwarders 2>/dev/null || true
  echo "$SRC: $TGTS" >> /etc/exim4/virtual/forwarders
  echo "ensured forwarder $SRC"
done < <(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT CONCAT(f.localpart,'@',d.domain), f.targets FROM email_forwards f JOIN domains d ON d.id=f.domain_id;" 2>/dev/null) || true

# report orphans (map lines with no DB row) — never auto-delete
while IFS= read -r line; do  A="${line%%:*}"; AQ="$(sqlq "$A")"
  MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT 1 FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE CONCAT(e.localpart,'@',d.domain)='$AQ' LIMIT 1;" | grep -q 1 \
    || echo "orphan alias (manual review): $line"
done < /etc/exim4/virtual/aliases
echo "reconcile done, migrated $MOVED message(s)"
