#!/usr/bin/env bash
# mail-clean.sh [--dry-run] [address] — auto-empty Trash and Spam (feature:
# Email -> Manage -> "Empty Trash & Spam after"). For every mailbox with
# email_accounts.clean_days > 0, messages in its Trash/Spam folders that were
# moved there more than clean_days ago (doveadm "savedbefore": the time the
# message landed in THAT folder, not its sent date) are expunged. Only the
# folder names below, top level, exact — never INBOX, Sent or user folders.
# Existing mailboxes start at 0 (off, migration 064); new ones at 30.
# Nightly from the shipped cron table; prints one line per mailbox it cleaned
# plus a total. --dry-run counts without deleting. Idempotent.
set -uo pipefail
DRY=0; ONLY=""
for a in "$@"; do
  case "$a" in
    --dry-run) DRY=1 ;;
    *@*) [[ "$a" =~ ^[a-z0-9._+-]{1,64}@[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad address: $a"; exit 1; }; ONLY="$a" ;;
    *) echo "usage: $0 [--dry-run] [address]"; exit 1 ;;
  esac
done
PASS="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
[[ -n "$PASS" ]] || { echo "no panel db pass"; exit 1; }
command -v doveadm >/dev/null || { echo "doveadm missing"; exit 1; }
# Trash: Roundcube/Thunderbird "Trash", Outlook "Deleted Items", Apple "Deleted
# Messages". Spam: Roundcube/Thunderbird "Junk", Outlook "Junk E-mail"/"Junk
# Email", others "Spam", Yahoo-style "Bulk Mail".
FOLDERS=("Trash" "Deleted Items" "Deleted Messages" "Junk" "Junk E-mail" "Junk Email" "Spam" "Bulk Mail")
Q=("(")
for i in "${!FOLDERS[@]}"; do
  (( i > 0 )) && Q+=("OR")
  Q+=("mailbox" "${FOLDERS[$i]}")
done
Q+=(")")
TOTAL=0; BOXES=0; ERR=0
while IFS=$'\t' read -r ADDR DAYS; do
  [[ "$ADDR" =~ ^[a-z0-9._+-]{1,64}@[a-z0-9.-]+\.[a-z]{2,}$ && "$DAYS" =~ ^[0-9]+$ ]] || continue
  (( DAYS > 0 )) || continue
  [[ -z "$ONLY" || "$ADDR" == "$ONLY" ]] || continue
  N="$(doveadm search -u "$ADDR" "${Q[@]}" savedbefore "${DAYS}d" 2>/dev/null | wc -l)"
  (( N > 0 )) || continue
  if (( DRY )); then
    echo "$(date '+%F %T') $ADDR: $N message(s) older than ${DAYS}d in Trash/Spam (dry run)"
  elif doveadm expunge -u "$ADDR" "${Q[@]}" savedbefore "${DAYS}d" 2>/dev/null; then
    echo "$(date '+%F %T') $ADDR: removed $N message(s) older than ${DAYS}d from Trash/Spam"
  else
    echo "$(date '+%F %T') $ADDR: expunge FAILED"; ERR=1; continue
  fi
  TOTAL=$((TOTAL + N)); BOXES=$((BOXES + 1))
done < <(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT CONCAT(e.localpart,'@',d.domain), e.clean_days FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE e.clean_days > 0 ORDER BY d.domain, e.localpart;" 2>/dev/null)
echo "$(date '+%F %T') mail-clean: $TOTAL message(s) from $BOXES mailbox(es)$( (( DRY )) && echo ' (dry run)')"
exit "$ERR"
