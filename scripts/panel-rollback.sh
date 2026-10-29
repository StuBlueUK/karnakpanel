#!/usr/bin/env bash
# panel-rollback.sh [release] — revert panel+scripts to a kept release (default:
# the newest release that ISN'T current). Restores the matching pre-upgrade DB
# dump when one exists. DETACHED like panel-update.sh; watch update.log.
set -euo pipefail
WANT="${1:-}"
LOG="/var/log/karnakpanel/update.log"
SKY="/usr/local/skypanel"
REL="$SKY/releases"
( exec >>"$LOG" 2>&1
  echo "=== $(date -Is) rollback requested (want: ${WANT:-previous}) ==="
  [[ -L "$SKY/panel" && -L "$SKY/scripts" ]] || { echo "REFUSED: panel/scripts are not symlinks (pre-update layout?)"; exit 1; }
  CUR="$(basename "$(dirname "$(readlink -f "$SKY/panel")")")"
  if [[ -z "$WANT" ]]; then
    WANT=""
    for d in $(ls -dt $REL/*/ 2>/dev/null); do
      v="$(basename "$d")"
      [[ "$v" != "$CUR" && -d "$REL/$v/panel" ]] && { WANT="$v"; break; }
    done
  fi
  [[ -n "$WANT" && "$WANT" =~ ^[a-zA-Z0-9._-]+$ ]] || { echo "REFUSED: no such previous release"; exit 1; }
  [[ -d "$REL/$WANT/panel" && -d "$REL/$WANT/scripts" ]] || { echo "REFUSED: release $WANT incomplete"; exit 1; }
  [[ "$WANT" != "$CUR" ]] || { echo "REFUSED: already on $CUR"; exit 1; }
  # a release from before the panel-worker move only knows `nobody` (ACLs,
  # sudoers, config group): put the worker back BEFORE its scripts go live
  if [[ ! -f "$REL/$WANT/scripts/panel-id.sh" && -x "$SKY/scripts/panel-user.sh" ]]; then
    "$SKY/scripts/panel-user.sh" revert || { echo "REFUSED: could not move the panel worker back to nobody for $WANT"; exit 1; }
  fi
  DUMP=$(ls -t /backup/skypanel/paneldb-${WANT}-*.sql.gz 2>/dev/null | head -1 || true)
  ln -sfn "$REL/$WANT/panel" "$SKY/panel"
  ln -sfn "$REL/$WANT/scripts" "$SKY/scripts"
  # root-script allowlist from the code now live (an older release may call
  # scripts this one dropped); renderer from the release we left if $WANT lacks it
  PS="$REL/$WANT/scripts/panel-sudoers.sh"; [[ -f "$PS" ]] || PS="$REL/$CUR/scripts/panel-sudoers.sh"
  [[ -f "$PS" ]] && { bash "$PS" || echo "WARN: sudoers not re-rendered for $WANT"; }
  if [[ -n "$DUMP" ]]; then
    zcat "$DUMP" | mysql -uroot skypanel 2>/dev/null && echo "db restored from $DUMP" || echo "WARN: db restore failed"
  else
    echo "WARN: no db snapshot found — code rolled back, DB untouched"
  fi
  /usr/local/lsws/bin/lswsctrl restart 2>&1 | tail -1 || true
  OK=0
  for i in $(seq 1 60); do
    sleep 2
    code=$(curl -sk -o /dev/null -w "%{http_code}" --max-time 5 https://127.0.0.1:8090/login 2>/dev/null || echo 000)
    [[ "$code" == "200" ]] && { OK=1; break; }
  done
  [[ "$OK" == 1 ]] && echo "=== rollback to $WANT COMPLETE ===" || { echo "WARN: rolled back but health check failed — inspect manually"; exit 1; }
  # VERSION moved back — refresh the update-check state so the banner matches.
  "$SKY/scripts/update-check.sh" >/dev/null 2>&1 || true
) &
echo "rollback started — follow /admin/logs?log=update"
