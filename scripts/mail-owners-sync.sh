#!/usr/bin/env bash
# mail-owners-sync.sh — rebuild two Exim maps from the panel DB:
#   domainowners  "<domain>: <account>" per hosted domain, subdomain, alias
#   phpmail       "<account>: off|none|<n>" — PHP mail switched off, no cap,
#                 or n recipients/hour (users.php_mail / php_mail_cap, else
#                 settings php_mail_cap, else smtp_default, else 200)
# domainowners: Exim reads it to DKIM-sign locally submitted mail (PHP mail(),
# sendmail) with its From: domain — but only when the submitting unix account
# ($sender_ident) owns that domain, so one tenant can never get mail signed
# as another tenant's domain (exim-dkim-from-patch.sh).
# Full atomic rebuild every run (derived data: never edited in place, so a
# deleted or moved domain can't linger). Run by vhost-add/-remove/-alias/
# -unalias and create/delete-account (each after the DB row change), by the
# panel's PHP mail switch, by cron every 5 minutes as the backstop, and by
# host-converge. A DB failure leaves the old map alone and fails loudly.
set -euo pipefail
PASS="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
[[ -n "$PASS" ]] || { echo "no panel db pass (/root/.skypanel-dbpass)"; exit 1; }
MAP=/etc/exim4/virtual/domainowners
mkdir -p /etc/exim4/virtual
ROWS="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -B -e "
  SELECT LOWER(d.domain), u.username FROM domains d JOIN users u ON u.id=d.user_id
  UNION
  SELECT LOWER(a.alias), u.username FROM domain_aliases a JOIN domains d ON d.id=a.domain_id JOIN users u ON u.id=d.user_id;")" \
  || { echo "db query failed — $MAP left as it was"; exit 1; }
TMP="$(mktemp "$MAP.XXXXXX")"
trap 'rm -f "$TMP"' EXIT
N=0; SKIP=0
declare -A SEEN=()
while IFS=$'\t' read -r D U; do
  [[ -n "$D" ]] || continue
  # same shapes the panel accepts; anything else is skipped, never mapped
  if [[ ! "$D" =~ ^[a-z0-9]([a-z0-9-]{0,62}\.)+[a-z][a-z0-9-]{1,62}$ ]] || [[ ! "$U" =~ ^[a-z][a-z0-9_-]{0,31}$ ]]; then
    SKIP=$((SKIP + 1)); continue
  fi
  [[ -z "${SEEN[$D]:-}" ]] || continue   # UNIQUE in the DB; first row wins if not
  SEEN[$D]=1
  printf '%s: %s\n' "$D" "$U" >> "$TMP"
  N=$((N + 1))
done <<<"$ROWS"
chmod 644 "$TMP"
if [[ -f "$MAP" ]] && cmp -s "$TMP" "$MAP"; then
  echo "domainowners unchanged ($N domains)"
else
  mv -f "$TMP" "$MAP"
  echo "domainowners rebuilt ($N domains)"
fi
[[ $SKIP -eq 0 ]] || echo "skipped $SKIP row(s) with an unexpected domain/account shape"

# phpmail: Exim's local-submission ACL (exim-phpmail-patch.sh) refuses "off"
# accounts and caps the rest per account. An account missing from the map
# (created since the last run) falls back to the old shared per-domain cap.
PMAP=/etc/exim4/virtual/phpmail
PROWS="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -B -e "
  SELECT u.username, u.php_mail, COALESCE(u.php_mail_cap,
    (SELECT CAST(value AS SIGNED) FROM settings WHERE name='php_mail_cap'),
    (SELECT CAST(value AS SIGNED) FROM settings WHERE name='smtp_default'), 200)
  FROM users u;")" || { echo "db query failed (migration 066 not applied?) — $PMAP left as it was"; exit 1; }
PTMP="$(mktemp "$PMAP.XXXXXX")"
trap 'rm -f "$TMP" "$PTMP"' EXIT
PN=0
while IFS=$'\t' read -r U ON CAP; do
  [[ "$U" =~ ^[a-z][a-z0-9_-]{0,31}$ ]] || continue
  if [[ "$ON" != 1 ]]; then V=off
  elif [[ "$CAP" =~ ^[0-9]{1,7}$ ]]; then V="$CAP"; [[ "$CAP" == 0 ]] && V=none
  else V=200; fi
  printf '%s: %s\n' "$U" "$V" >> "$PTMP"; PN=$((PN + 1))
done <<<"$PROWS"
chmod 644 "$PTMP"
if [[ -f "$PMAP" ]] && cmp -s "$PTMP" "$PMAP"; then
  echo "phpmail unchanged ($PN accounts)"
else
  mv -f "$PTMP" "$PMAP"
  echo "phpmail rebuilt ($PN accounts)"
fi
