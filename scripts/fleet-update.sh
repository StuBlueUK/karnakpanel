#!/usr/bin/env bash
# fleet-update.sh <install-id> <core|plugins|safe> [--allow-major] — run a
# panel-initiated WordPress update with a restore point first (runs as root).
# DETACHED like app-push.sh (downloads + DB work exceed the panel sudo
# timeout): validates fast, backgrounds into fleet.log, exits 0 with
# "started". The worker snapshots files+DB (newest 2 kept), runs vendored
# wp-cli as the site owner (never --allow-root), verifies the version moved,
# and rolls back on any failure. `safe` = plugins + minor core only; a major
# core bump needs --allow-major (the mass button never passes it).
set -uo pipefail
. "$(dirname "$(readlink -f "$0")")/php-versions.sh"
IID="${1:?}"; MODE="${2:-}"; EXTRA="${3:-}"
LOG="/var/log/karnakpanel/fleet.log"
Q0() { mysql -uroot -N skypanel -e "$1" 2>/dev/null; }
# mass pass: one detached worker per install, serialized by the update lock
# (children wait their turn via FLEET_QUEUE). Scope 0 = whole box (admin),
# else a reseller id whose clients only are covered. Per-install failures
# roll back that install and the loop continues.
if [[ "$IID" == "all" ]]; then
  [[ "$MODE" == "safe" && "$EXTRA" =~ ^[0-9]{1,10}$ ]] || { echo "usage: fleet-update all safe [owner-id]"; exit 1; }
  IDS="$(Q0 "SELECT s.id FROM site_installs s LEFT JOIN app_versions v ON v.install_id=s.id WHERE s.app='wordpress' AND (COALESCE(v.update_available,0)=1 OR COALESCE(v.plugins_available,0)>0) $([[ "$EXTRA" != "0" ]] && echo "AND s.user_id IN (SELECT id FROM users WHERE owner_id=$EXTRA)") ORDER BY s.id;")"
  [[ -n "$IDS" ]] || { echo "fleet mass-safe: nothing in scope"; exit 0; }
  export FLEET_QUEUE=1
  for one in $IDS; do
    [[ "$one" =~ ^[0-9]+$ ]] || continue
    "$0" "$one" safe || true
  done
  echo "fleet mass-safe started ($(echo "$IDS" | wc -w) installs) — watch Logs (fleet)"
  exit 0
fi
[[ "$IID" =~ ^[0-9]{1,10}$ ]] || { echo "bad install id"; exit 1; }
[[ "$MODE" =~ ^(core|plugins|safe)$ ]] || { echo "usage: fleet-update <install-id> core|plugins|safe [--allow-major]"; exit 1; }
[[ -z "$EXTRA" || "$EXTRA" == "--allow-major" ]] || { echo "bad flag"; exit 1; }
PHAR="$(dirname "$(readlink -f "$0")")/wp-cli.phar"
# wp-cli loads the site's own plugins = customer code: same link filter as cron
. "$(dirname "$(readlink -f "$0")")/seccomp-common.sh"
# pinned at vendor time (wp-cli 2.12.0): a swapped phar is a root-equivalent
# backdoor, so refuse to run anything else.
PIN="ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c"
[[ -f "$PHAR" ]] || { echo "REFUSED: wp-cli.phar missing — panel update needed"; exit 1; }
echo "$PIN  $PHAR" | sha256sum -c - >/dev/null 2>&1 || { echo "REFUSED: wp-cli.phar hash mismatch"; exit 1; }
ROW="$(Q0 "SELECT CONCAT_WS('|', s.app, u.username, d.domain, s.path, d.php_version, s.user_id) FROM site_installs s JOIN users u ON u.id=s.user_id JOIN domains d ON d.id=s.domain_id WHERE s.id=$IID;")"
[[ -n "$ROW" ]] || { echo "REFUSED: no install $IID"; exit 1; }
IFS='|' read -r APP U D SUB PVER OWNER <<< "$ROW"
[[ "$APP" == "wordpress" && "$U" =~ ^[a-z][a-z0-9_]{2,31}$ && "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "REFUSED: bad install row"; exit 1; }
[[ "$SUB" =~ ^[a-zA-Z0-9_-]{0,64}$ ]] || { echo "REFUSED: bad subdir"; exit 1; }
[[ "$OWNER" =~ ^[0-9]+$ ]] || { echo "REFUSED: bad owner"; exit 1; }
DOC="/home/$U/$D/public_html${SUB:+/$SUB}"
case "$DOC" in /home/*/*/public_html*) ;; *) echo "REFUSED: docroot escapes home"; exit 1;; esac
[[ -f "$DOC/wp-config.php" && -f "$DOC/wp-includes/version.php" ]] || { echo "REFUSED: not a WordPress docroot"; exit 1; }
id "$U" >/dev/null 2>&1 || { echo "REFUSED: no such system user"; exit 1; }
LDB="$(grep -oE "define\( *'DB_NAME', *'[^']+'" "$DOC/wp-config.php" | grep -oE "'[^']+'$" | tr -d "'" || true)"
[[ "$LDB" =~ ^[a-z][a-z0-9_]{2,63}$ ]] || { echo "REFUSED: cannot read live DB name"; exit 1; }
echo "fleet update $MODE install $IID started — watch Logs (fleet)"
exec 9>/var/lock/fleet-update.lock
if [[ "${FLEET_QUEUE:-}" == "1" ]]; then flock -w 7200 9 || { echo "fleet-update: lock wait timed out"; exit 1; }
else flock -n 9 || { echo "fleet-update: another update running, try again shortly"; exit 1; }; fi
( exec >>"$LOG" 2>&1
  echo "=== $(date -Is) fleet-update $MODE install $IID ($D${SUB:+/$SUB}) ==="
  fail() { echo "FAILED: $1"; exit 1; }
  # site PHP first (pool version), then any CLI php — first hit wins
  PHPBIN=""
  for cand in "php${PVER}" "php$PHP_DEFAULT" $(php_installed | sort -r | sed "s/^/php/") php; do
    for dir in /usr/bin /usr/local/bin; do
      if [[ -z "$PHPBIN" && -x "$dir/$cand" ]]; then PHPBIN="$dir/$cand"; fi
    done
  done
  for lsv in $(ls -d /usr/local/lsws/lsphp8[0-9] 2>/dev/null | sort -r); do
    if [[ -z "$PHPBIN" && -x "$lsv/bin/php" ]]; then PHPBIN="$lsv/bin/php"; fi
  done
  [[ -n "$PHPBIN" ]] || fail "no PHP CLI found"
  echo "php: $PHPBIN ($("$PHPBIN" -r 'echo PHP_VERSION;' 2>/dev/null || echo ?))"
  as_owner() { local t="$1"; shift; timeout "$t" perl -e "$SECCOMP_PL" -- su -s /bin/sh "$U" -c "cd '$DOC' && '$PHPBIN' '$PHAR' --path='$DOC' $*"; }
  LIVE="$(sed -n "s/^\$wp_version *= *'\([0-9.]*\)'.*/\1/p" "$DOC/wp-includes/version.php" | head -1)"
  [[ "$LIVE" =~ ^[0-9]+\.[0-9]+(\.[0-9]+)?$ ]] || fail "cannot read live version"
  # restore point first (same method as app-push snap_live; newest 2 kept)
  TS="$(date +%Y%m%d-%H%M%S)"
  FF="/backup/skypanel/fleet-${IID}-${TS}.tgz"; DF="/backup/skypanel/fleet-${IID}-${TS}.sql.gz"
  mkdir -p /backup/skypanel
  tar czf "$FF" -C "$(dirname "$DOC")" "$(basename "$DOC")" || fail "files snapshot failed"
  mysqldump -uroot "$LDB" 2>/dev/null | gzip > "$DF" || fail "db snapshot failed"
  SNAP="$(mysql -uroot -N skypanel -e "INSERT INTO fleet_snapshots(install_id,files_path,db_path,note) VALUES($IID,'$FF','$DF','$MODE from $LIVE'); SELECT LAST_INSERT_ID();" 2>/dev/null)" || fail "snapshot row failed"
  echo "restore point $SNAP recorded"
  for OLD in $(mysql -uroot -N skypanel -e "SELECT id FROM fleet_snapshots WHERE install_id=$IID ORDER BY id DESC LIMIT 10 OFFSET 2;" 2>/dev/null); do
    while IFS=$'\t' read -r OF ODB; do rm -f "$OF" "$ODB" 2>/dev/null; done < <(mysql -uroot -N skypanel -e "SELECT files_path, db_path FROM fleet_snapshots WHERE id=$OLD;" 2>/dev/null)
    mysql -uroot skypanel -e "DELETE FROM fleet_snapshots WHERE id=$OLD;" 2>/dev/null || true
  done
  rollback() {
    echo "ROLLING BACK to snapshot $SNAP: $1"
    rm -rf "$DOC"; mkdir -p "$DOC"; chown "$U:" "$DOC"
    tar xzf "$FF" -C "$(dirname "$DOC")" || { echo "CRITICAL: files restore failed"; exit 1; }
    zcat "$DF" | mysql -uroot "$LDB" || { echo "CRITICAL: db restore failed"; exit 1; }
    chown -R "$U:" "$DOC"
    mysql -uroot skypanel -e "UPDATE app_versions SET scanned_at=NOW(),scan_note='update $MODE failed ($1) — rolled back to $LIVE' WHERE install_id=$IID;" 2>/dev/null || true
    MYSQL_PWD="$(cat /root/.skypanel-dbpass 2>/dev/null)" mysql -uskypanel skypanel -e "INSERT INTO notifications(user_id,type,title) VALUES($OWNER,'fleet','Update $MODE on $D failed ($1) — rolled back to $LIVE.');" 2>/dev/null || true
    echo "=== rollback COMPLETE ==="
    exit 1
  }
  WANT_CORE=0; WANT_PLUGINS=0
  case "$MODE" in
    core) WANT_CORE=1;;
    plugins) WANT_PLUGINS=1;;
    safe) WANT_PLUGINS=1; WANT_CORE=1;;  # majors held below, never forced
  esac
  # pre-flight with FRESH update data (wp-cli trusts cached transients, which
  # can claim "current" for a downgraded tree — a blocked network then exits 0
  # having done nothing; seen live 2026-10-14). Never run blind: empty checks
  # stand the run down instead of snapshotting for nothing.
  EXPECT_CORE=""
  if [[ "$WANT_CORE" == "1" ]]; then
    as_owner 120 transient delete update_core >/dev/null 2>&1 || true
    TARGET="$(as_owner 120 core check-update --field=version 2>/dev/null | head -1 || true)"
    if [[ "$TARGET" =~ ^[0-9]+\.[0-9]+(\.[0-9]+)?$ && "$TARGET" != "$LIVE" ]]; then
      if [[ "${LIVE%.*}" != "${TARGET%.*}" && "$EXTRA" != "--allow-major" ]]; then
        if [[ "$MODE" == "safe" ]]; then
          WANT_CORE=0; echo "safe: major core $LIVE -> $TARGET HELD (update from the row to confirm)"
        else
          echo "REFUSED: $LIVE -> $TARGET crosses major.minor — retry with --allow-major"
          mysql -uroot skypanel -e "UPDATE app_versions SET scan_note='major core $LIVE -> $TARGET held — confirm explicitly' WHERE install_id=$IID;" 2>/dev/null || true
          exit 1
        fi
      fi
      if [[ "$WANT_CORE" == "1" ]]; then EXPECT_CORE="$TARGET"; echo "core: $LIVE -> $EXPECT_CORE expected"; fi
    else
      WANT_CORE=0; echo "core: no update reported — standing down"
    fi
  fi
  BEFORE=""; PUPD_BEFORE=0
  if [[ "$WANT_PLUGINS" == "1" ]]; then
    as_owner 120 transient delete update_plugins >/dev/null 2>&1 || true
    BEFORE="$(as_owner 120 plugin list --fields=name,version,update_version --format=csv 2>/dev/null | tail -n +2 | sort || true)"
    PUPD_BEFORE="$(echo "$BEFORE" | awk -F, '$3!=""' | wc -l | tr -cd '0-9')"
    if [[ "$PUPD_BEFORE" == "0" ]]; then WANT_PLUGINS=0; echo "plugins: none updatable — standing down"; fi
  fi
  if [[ "$WANT_CORE" == "0" && "$WANT_PLUGINS" == "0" ]]; then
    echo "fleet-update: nothing to do (core current, no updatable plugins)"
    NOTE='nothing pending'
    curl -fsS --max-time 10 https://api.wordpress.org/core/version-check/1.7/ >/dev/null 2>&1 || NOTE='wordpress.org unreachable — standing down'
    rm -f "$FF" "$DF" 2>/dev/null
    mysql -uroot skypanel -e "DELETE FROM fleet_snapshots WHERE id=$SNAP;" 2>/dev/null || true
    mysql -uroot skypanel -e "UPDATE app_versions SET scanned_at=NOW(),scan_note='update $MODE: $NOTE' WHERE install_id=$IID;" 2>/dev/null || true
    exit 0
  fi
  OUT=""
  if [[ "$WANT_CORE" == "1" ]]; then
    OUT="$(as_owner 600 core update 2>&1)" || rollback "core update failed: $(echo "$OUT" | tail -2 | tr '\n' ' ' | cut -c1-120)"
    echo "$OUT" | tail -3
    echo "core update exit ok"
  fi
  if [[ "$WANT_PLUGINS" == "1" ]]; then
    OUT="$(as_owner 600 plugin update --all 2>&1)" || rollback "plugin update failed: $(echo "$OUT" | tail -2 | tr '\n' ' ' | cut -c1-120)"
    echo "$OUT" | tail -5
    # exit 0 proves nothing (a dead API exits 0 having done nothing — seen
    # live): at least one plugin version must actually have moved.
    AFTER="$(as_owner 120 plugin list --fields=name,version --format=csv 2>/dev/null | tail -n +2 | sort || true)"
    if [[ "$(echo "$BEFORE" | awk -F, '{print $1","$2}' | sort)" == "$(echo "$AFTER" | awk -F, '{print $1","$2}' | sort)" ]]; then
      rollback "no plugin version moved (reported success, changed nothing)"
    fi
    echo "plugins updated (versions moved)"
  fi
  NEW="$(sed -n "s/^\$wp_version *= *'\([0-9.]*\)'.*/\1/p" "$DOC/wp-includes/version.php" | head -1)"
  [[ -n "$NEW" ]] || rollback "version.php unreadable after update"
  if [[ -n "$EXPECT_CORE" && "$NEW" != "$EXPECT_CORE" ]]; then
    rollback "core still $NEW, expected $EXPECT_CORE"
  fi
  NPLUG="$(find "$DOC/wp-content/plugins" -maxdepth 1 -mindepth 1 -type d 2>/dev/null | wc -l || true)"
  [[ "$NPLUG" =~ ^[[:space:]]*[0-9]+$ ]] || NPLUG=0
  NPLUG="$(echo "$NPLUG" | tr -cd '0-9')"
  # fresh updatable count so the dashboard stops offering what just ran
  # (transient deleted first — a failure-cached "no updates" would stick)
  as_owner 120 transient delete update_plugins >/dev/null 2>&1 || true
  NPUPD="$(as_owner 120 plugin list --update=available --field=name 2>/dev/null | wc -l || true)"
  NPUPD="$(echo "$NPUPD" | tr -cd '0-9')"
  [[ -n "$NPUPD" ]] || NPUPD=0
  # core may still be pending after a plugins-only run — recompute honestly
  LATEST="$(cat /var/cache/karnakpanel/wp-latest 2>/dev/null || true)"
  CUPD=0
  if [[ "$LATEST" =~ ^[0-9]+\.[0-9]+(\.[0-9]+)?$ && "$NEW" != "$LATEST" ]]; then
    [[ "$(printf '%s\n%s\n' "$NEW" "$LATEST" | sort -V | head -1)" == "$NEW" ]] && CUPD=1
  fi
  mysql -uroot skypanel -e "UPDATE app_versions SET live_version='$NEW',latest_version='$LATEST',update_available=$CUPD,plugins_total=$NPLUG,plugins_available=$NPUPD,scanned_at=NOW(),scan_note='updated $MODE $LIVE -> $NEW' WHERE install_id=$IID;" 2>/dev/null || true
  MYSQL_PWD="$(cat /root/.skypanel-dbpass 2>/dev/null)" mysql -uskypanel skypanel -e "INSERT INTO notifications(user_id,type,title) VALUES($OWNER,'fleet','Updated $MODE on $D: $LIVE -> $NEW (restore point kept).');" 2>/dev/null || true
  echo "=== fleet-update $MODE COMPLETE: $LIVE -> $NEW ==="
) &
exit 0
