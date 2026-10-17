#!/usr/bin/env bash
# app-push.sh <clone-id> <files|full|restore> [snapshot-id] — push a staging
# clone back to live (runs as root). DETACHED like app-clone.sh (big trees).
# files: staging files -> live, preserving live wp-config.php (DB creds
# differ!) and dropping staging-only bits (mail gate, robots deny). full:
# snapshot live first (restore point, newest 2 kept), then files, then
# staging DB -> live DB with URLs rewritten back. restore: roll live back to
# a snapshot of this clone. WordPress v1 (pack switch by app column).
set -uo pipefail
CLID="${1:?}"; MODE="${2:-}"; SNAP="${3:-}"
LOG="/var/log/karnakpanel/apptoolkit.log"
[[ "$CLID" =~ ^[0-9]{1,10}$ ]] || { echo "bad clone id"; exit 1; }
[[ "$MODE" =~ ^(files|full|restore)$ ]] || { echo "usage: app-push <clone-id> files|full|restore [snapshot-id]"; exit 1; }
if [[ "$MODE" == "restore" ]]; then [[ "$SNAP" =~ ^[0-9]{1,10}$ ]] || { echo "bad snapshot id"; exit 1; }; fi
Q() { mysql -uroot -N skypanel -e "$1" 2>/dev/null; }
# NOTE: read with IFS='|' over CONCAT_WS — a TAB here would squeeze empty
# fields (IFS whitespace), silently shifting every column (lost a debug hour).
ROW="$(Q "SELECT CONCAT_WS('|', c.app, c.staging_domain_id, c.staging_db, s.path, d.domain, ld.domain, u.username) FROM staging_clones c JOIN site_installs s ON s.id=c.live_install_id JOIN domains d ON d.id=c.staging_domain_id JOIN domains ld ON ld.id=s.domain_id JOIN users u ON u.id=c.user_id WHERE c.id=$CLID AND c.status='ready';")"
[[ -n "$ROW" ]] || { echo "REFUSED: no ready clone $CLID"; exit 1; }
IFS='|' read -r APP SDID SDB SUBURL SDOM LD UNAME <<< "$ROW"
[[ "$APP" == "wordpress" && "$UNAME" =~ ^[a-z][a-z0-9_]{2,31}$ && "$LD" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "REFUSED: bad clone row ($APP/$LD/$UNAME)"; exit 1; }
[[ "$SUBURL" =~ ^[a-zA-Z0-9_-]{0,64}$ ]] || { echo "REFUSED: bad live subdir"; exit 1; }
[[ "$SDOM" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ && "$SDB" =~ ^[a-z][a-z0-9_]{2,63}$ ]] || { echo "REFUSED: bad staging refs"; exit 1; }
[[ "$SUBURL" =~ ^[a-zA-Z0-9_-]{0,64}$ ]] || { echo "REFUSED: bad live subdir"; exit 1; }
LDOC="/home/$UNAME/$LD/public_html${SUBURL:+/$SUBURL}"
case "$LDOC" in /home/*/*/public_html*) ;; *) echo "REFUSED: docroot escapes home"; exit 1;; esac
SDOC="/home/$UNAME/$SDOM/public_html"
[[ -f "$LDOC/wp-config.php" && -f "$SDOC/wp-config.php" ]] || { echo "REFUSED: endpoints not both WordPress"; exit 1; }
LPREFIX="$(grep -oE "\\\$table_prefix *= *'[^']+'" "$LDOC/wp-config.php" | grep -oE "'[^']+'$" | tr -d "'" || true)"
[[ "$LPREFIX" =~ ^[A-Za-z0-9_]{1,20}$ ]] || LPREFIX="wp_"
LDB="$(grep -oE "define\( *'DB_NAME', *'[^']+'" "$LDOC/wp-config.php" | grep -oE "'[^']+'$" | tr -d "'" || true)"
[[ "$LDB" =~ ^[a-z][a-z0-9_]{2,63}$ ]] || { echo "REFUSED: cannot read live DB name"; exit 1; }
if [[ -z "$SUBURL" ]]; then
  SCHEME="$([ "$(Q "SELECT ssl_enabled FROM domains WHERE domain='$LD';")" == "1" ] && echo https || echo http)"
  LURL="$SCHEME://$LD"
else
  LURL="https://$LD/$SUBURL"
fi
STGURL="https://$SDOM"
URLR="$(readlink -f /usr/local/skypanel/panel)/cli/url-replace.php"
[[ -x "$URLR" ]] || { echo "REFUSED: url-replace.php missing — panel update needed"; exit 1; }
echo "push $MODE clone $CLID started — watch Logs (apptoolkit)"
( exec >>"$LOG" 2>&1
  echo "=== $(date -Is) app-push $MODE clone $CLID ==="
  fail() { echo "FAILED: $1"; exit 1; }
  snap_live() {
    TS="$(date +%Y%m%d-%H%M%S)"
    FF="/backup/skypanel/staging-${CLID}-${TS}.tgz"; DF="/backup/skypanel/staging-${CLID}-${TS}.sql.gz"
    tar czf "$FF" -C "$(dirname "$LDOC")" "$(basename "$LDOC")" 2>>"$LOG" || fail "files snapshot failed"
    mysqldump -uroot "$LDB" 2>>"$LOG" | gzip > "$DF" || fail "db snapshot failed"
    mysql -uroot -N skypanel -e "INSERT INTO staging_snapshots(clone_id,files_path,db_path) VALUES($CLID,'$FF','$DF'); SELECT LAST_INSERT_ID();" 2>>"$LOG" || fail "snapshot row failed"
    for OLD in $(mysql -uroot -N skypanel -e "SELECT id FROM staging_snapshots WHERE clone_id=$CLID ORDER BY id DESC LIMIT 10 OFFSET 2;" 2>/dev/null); do
      while IFS=$'\t' read -r OF ODB; do rm -f "$OF" "$ODB" 2>/dev/null; done < <(mysql -uroot -N skypanel -e "SELECT files_path, db_path FROM staging_snapshots WHERE id=$OLD;" 2>/dev/null)
      mysql -uroot skypanel -e "DELETE FROM staging_snapshots WHERE id=$OLD;" 2>/dev/null || true
    done
    echo "restore point recorded"
  }
  # files push that preserves the DESTINATION wp-config.php (DB creds differ
  # per copy) and drops staging-only bits (mail gate, robots deny).
  push_files_keep_config() {
    local SRC="$1" DST="$2" KEEP
    KEEP="$(mktemp)" # 600 root-only; live DB password inside, removed below
    cp "$DST/wp-config.php" "$KEEP" || fail "cannot back up live config"
    cp -a "$SRC/." "$DST/" || { cp "$KEEP" "$DST/wp-config.php"; fail "file copy failed"; }
    cp "$KEEP" "$DST/wp-config.php"; rm -f "$KEEP"
    rm -f "$DST/wp-content/mu-plugins/staging-mail-gate.php" "$DST/robots.txt"
    chown -R "$UNAME:" "$DST"
  }
  if [[ "$MODE" == "restore" ]]; then
    SP="$(Q "SELECT files_path, db_path FROM staging_snapshots WHERE id=$SNAP AND clone_id=$CLID;")"
    [[ -n "$SP" ]] || fail "no snapshot $SNAP for clone $CLID"
    SFF="$(echo "$SP" | cut -f1)"; SDF="$(echo "$SP" | cut -f2)"
    [[ -f "$SFF" && -f "$SDF" ]] || fail "snapshot files missing"
    snap_live
    rm -rf "$LDOC"; mkdir -p "$LDOC"; chown "$UNAME:" "$LDOC"
    tar xzf "$SFF" -C "/home/$UNAME/$LD" 2>>"$LOG" || fail "files restore failed"
    zcat "$SDF" | mysql -uroot "$LDB" 2>>"$LOG" || fail "db restore failed"
    chown -R "$UNAME:" "$LDOC"
    mysql -uroot skypanel -e "UPDATE staging_clones SET last_push_at=NOW() WHERE id=$CLID;" 2>>"$LOG" || true
    echo "=== restore COMPLETE ==="
    exit 0
  fi
  [[ "$MODE" == "full" ]] && snap_live
  push_files_keep_config "$SDOC" "$LDOC"
  if [[ "$MODE" == "full" ]]; then
    mysqldump -uroot "$SDB" 2>>"$LOG" | php "$URLR" "$STGURL" "$LURL" 2>>"$LOG" | mysql -uroot "$LDB" 2>>"$LOG" || fail "database push failed"
    mysql -uroot "$LDB" -e "UPDATE ${LPREFIX}options SET option_value='$LURL' WHERE option_name IN ('siteurl','home');" 2>>"$LOG" || fail "live options failed"
  fi
  mysql -uroot skypanel -e "UPDATE staging_clones SET last_push_at=NOW() WHERE id=$CLID;" 2>>"$LOG" || true
  echo "=== push $MODE COMPLETE ==="
) &
