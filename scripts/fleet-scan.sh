#!/usr/bin/env bash
# fleet-scan.sh — nightly Fleet Updates scan (cron, root). Read-only against
# site files: records each panel-installed WordPress's live core version and
# whether wordpress.org reports a newer release. IDEMPOTENT: upserts one row
# per site_installs id, so re-runs and overlapping installs are safe.
# v1 = core version only (no wp-cli on boxes; version.php is authoritative).
# Plugin/theme per-item status + vuln feeds are phase 2.
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/php-versions.sh"
exec 9>/var/lock/fleet-scan.lock
flock -n 9 || { echo "fleet-scan: previous pass still running, skipping"; exit 0; }
PASS="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
[[ -n "$PASS" ]] || { echo "no panel db pass"; exit 1; }
Q() { MYSQL_PWD="$PASS" mysql -uskypanel --raw -N skypanel -e "$1" 2>/dev/null || true; }
# schedule gate (Fleet page, admin-only): hourly cron, runs only in the
# chosen hour unless disabled entirely. Same pattern as malware-scan.sh.
HR="$(date +%H)"
SET_HR="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT value FROM settings WHERE name='fleet_hour';" 2>/dev/null | grep -E '^[0-9]{1,2}$' | head -1 || true)"
SET_ON="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT value FROM settings WHERE name='fleet_enabled';" 2>/dev/null | head -1 || true)"
if [[ "$SET_ON" == "0" ]]; then echo "fleet-scan: disabled in settings — skipped"; exit 0; fi
WANT="${SET_HR:-3}"  # unset = legacy 03:37 slot
if [[ "$((10#$WANT))" -ne "$((10#$HR))" ]]; then exit 0; fi

# latest core: one wordpress.org call, cached 6h (offline boxes reuse cache).
CACHE="/var/cache/karnakpanel/wp-latest"
mkdir -p "$(dirname "$CACHE")" 2>/dev/null || true
LATEST=""
if [[ -f "$CACHE" ]] && [[ $(( $(date +%s) - $(stat -c %Y "$CACHE" 2>/dev/null || echo 0) )) -lt 21600 ]]; then
  LATEST="$(cat "$CACHE" 2>/dev/null || true)"
else
  FRESH="$(curl -fsS --max-time 10 https://api.wordpress.org/core/version-check/1.7/ 2>/dev/null | grep -o '"version":"[0-9.]*"' | head -1 | cut -d'"' -f4 || true)"
  if [[ "$FRESH" =~ ^[0-9]+\.[0-9]+(\.[0-9]+)?$ ]]; then LATEST="$FRESH"; echo "$LATEST" >"$CACHE" 2>/dev/null || true; fi
  [[ -z "$LATEST" && -f "$CACHE" ]] && LATEST="$(cat "$CACHE" 2>/dev/null || true)"
fi
[[ "$LATEST" =~ ^[0-9]+\.[0-9]+(\.[0-9]+)?$ ]] || LATEST=""
echo "fleet-scan: latest=${LATEST:-unknown}"

Q "SELECT s.id, u.username, d.domain, s.path FROM site_installs s JOIN users u ON u.id=s.user_id JOIN domains d ON d.id=s.domain_id WHERE s.app='wordpress';" |
while IFS=$'\t' read -r IID U D SUB; do
  [[ "$IID" =~ ^[0-9]+$ ]] || continue
  [[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || continue
  [[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || continue
  [[ "$SUB" =~ ^[a-zA-Z0-9_-]{0,64}$ ]] || SUB=""
  DOC="/home/$U/$D/public_html${SUB:+/$SUB}"
  LIVE=""; NOTE=""
  VF="$DOC/wp-includes/version.php"
  if [[ -f "$VF" ]]; then
    LIVE="$(sed -n "s/^\$wp_version *= *'\([0-9.]*\)'.*/\1/p" "$VF" | head -1 || true)"
    [[ "$LIVE" =~ ^[0-9]+\.[0-9]+(\.[0-9]+)?$ ]] || { NOTE="version.php unreadable"; LIVE=""; }
  else
    NOTE="docroot gone?"
  fi
  PLUGINS=0
  [[ -d "$DOC/wp-content/plugins" ]] && PLUGINS="$(find "$DOC/wp-content/plugins" -maxdepth 1 -mindepth 1 -type d 2>/dev/null | wc -l || true)"
  [[ "$PLUGINS" =~ ^[0-9]+$ ]] || PLUGINS=0
  # updatable plugins via wp-cli as the owner (soft-fail: a boot hiccup must
  # never blank the row — worst case the count stays 0 until tomorrow).
  PUPD=0
  if [[ -f "$DOC/wp-config.php" ]]; then
    PHAR="$(dirname "$(readlink -f "$0")")/wp-cli.phar"
    # wp-cli loads the site's own plugins = customer code: same link filter as cron
    . "$(dirname "$(readlink -f "$0")")/seccomp-common.sh"
    PHPC=""
    for cand in "php$PHP_DEFAULT" $(php_installed | sort -r | sed "s/^/php/") php; do
      [[ -x "/usr/bin/$cand" ]] && { PHPC="/usr/bin/$cand"; break; }
    done
    if [[ -n "$PHPC" && -f "$PHAR" ]]; then
      # drop the cached update list first (wp-cli trusts the transient, which
      # can claim "current" for a downgraded tree — seen live 2026-10-14)
      timeout 60 perl -e "$SECCOMP_PL" -- su -s /bin/sh "$U" -c "cd '$DOC' && '$PHPC' '$PHAR' --path='$DOC' transient delete update_plugins" >/dev/null 2>&1 || true
      PUPD="$(timeout 90 perl -e "$SECCOMP_PL" -- su -s /bin/sh "$U" -c "cd '$DOC' && '$PHPC' '$PHAR' --path='$DOC' plugin list --update=available --field=name" 2>/dev/null | wc -l || true)"
      PUPD="$(echo "$PUPD" | tr -cd '0-9')"
      [[ -n "$PUPD" ]] || PUPD=0
    fi
  fi
  UPD=0
  if [[ -n "$LIVE" && -n "$LATEST" && "$LIVE" != "$LATEST" ]]; then
    LOWEST="$(printf '%s\n%s\n' "$LIVE" "$LATEST" | sort -V | head -1)"
    [[ "$LOWEST" == "$LIVE" ]] && UPD=1
  fi
  [[ -z "$LATEST" && -z "$NOTE" ]] && NOTE="version-check unreachable"
  Q "INSERT INTO app_versions(install_id,app,live_version,latest_version,update_available,plugins_total,plugins_available,scanned_at,scan_note) VALUES($IID,'wordpress','$LIVE','$LATEST',$UPD,$PLUGINS,$PUPD,NOW(),'$NOTE') ON DUPLICATE KEY UPDATE live_version=VALUES(live_version),latest_version=VALUES(latest_version),update_available=VALUES(update_available),plugins_total=VALUES(plugins_total),plugins_available=VALUES(plugins_available),scanned_at=VALUES(scanned_at),scan_note=VALUES(scan_note);"
  echo "$D${SUB:+/$SUB}: live=${LIVE:-?} latest=${LATEST:-?} upd=$UPD plugins=$PLUGINS pupd=$PUPD ${NOTE:+($NOTE)}"
done
echo "fleet-scan: done"
