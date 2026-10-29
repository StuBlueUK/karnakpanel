#!/usr/bin/env bash
# panel-user.sh [ensure-user|revert] — run the panel's PHP worker (:8090) as
# its own system user, karnak-panel, instead of `nobody`. OLS's own workers
# run as nobody too, so while they shared the uid an OLS bug was the panel's
# sudo rights; after the move nobody gets no root-script rights at all.
#   karnak-panel: system user, own group, supplementary `nogroup` (it still
#   reads what the panel read through that group: OLS-written logs,
#   root:nogroup 640 logs, config.php of older rollback releases).
# What moves with it (and back on revert):
#   - the SkyPanel vhost's lsphp extprocessor gets extUser/extGroup
#     (panel-id.sh reads that back — the single source of truth);
#   - panel/storage + panel.log owned by the worker; config.php group = the
#     worker group (the OLS workers can no longer read the panel DB pass);
#   - live PHP session files (/var/lib/php/sessions) re-owned, so nobody is
#     logged out;
#   - customer files: every u:nobody ACL gets a u:karnak-panel twin
#     (home-perms.sh mirrors them; the file manager, stats and PHP-log pages
#     read customer files directly); once the switch is proven, nobody (OLS)
#     loses every read outside docroots (web-acl.sh "split") — revert gives
#     them back BEFORE the worker returns to nobody;
#   - sudoers: both uids while switching, then karnak-panel only.
# PROVEN after the restart (login 200 + a worker probe reporting uid, DB,
# sudo and storage); any failure restores the vhost and everything above.
# GOTCHA: the panel's lsphp daemon survives an OLS restart (detached,
# ppid 1) and keeps answering as the OLD uid — killed explicitly here.
# Modes:  (none)  converge: switch if not yet switched (installer + host-converge)
#         ensure-user  only create the user (post-update runs it FIRST: new
#                      scripts write u:karnak-panel ACL specs, which setfacl
#                      rejects whole while the user doesn't exist)
#         revert       back to nobody (rollback to a pre-switch release)
# Pin: /var/lib/karnakpanel/panel-user-nobody keeps the panel on nobody.
# A failed switch leaves panel-user-failed (= panel version): later
# converges of the SAME release skip it; PANEL_USER_RETRY=1 forces a retry.
# Test hook: PANEL_USER_FORCE_FAIL=1 fails the health check (rollback proof).
set -euo pipefail
HERE="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
ST=/var/lib/karnakpanel
VH=/usr/local/lsws/conf/vhosts/SkyPanel/vhost.conf
PANEL=/usr/local/skypanel/panel
SESS=/var/lib/php/sessions
PVER="$(cat "$PANEL/VERSION" 2>/dev/null || echo unknown)"
MODE="${1:-}"
[[ -z "$MODE" || "$MODE" == ensure-user || "$MODE" == revert ]] || { echo "usage: panel-user.sh [ensure-user|revert]"; exit 1; }

ensure_user() {
  local ent
  if ent="$(getent passwd karnak-panel)"; then
    # never adopt a stranger: it is about to get the panel's root-script rights
    [[ "$(cut -d: -f3 <<<"$ent")" -lt 1000 && "$(cut -d: -f7 <<<"$ent")" == /usr/sbin/nologin ]] \
      || { echo "REFUSED: existing user karnak-panel is not a nologin system user"; exit 1; }
  fi
  if [[ -z "$ent" ]]; then
    useradd --system --user-group --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin --groups nogroup karnak-panel
    echo "panel-user: created system user karnak-panel"
  elif ! id -nG karnak-panel | tr ' ' '\n' | grep -qx nogroup; then
    usermod -aG nogroup karnak-panel
  fi
}
ensure_user
[[ "$MODE" == ensure-user ]] && exit 0

restart_ols() { systemctl restart lshttpd 2>/dev/null || /usr/local/lsws/bin/lswsctrl restart >/dev/null 2>&1 || true; }
# the panel lsphp binary = the extprocessor's `path` (only the panel uses it)
panel_bin() { awk '/^extprocessor lsphp8[0-9]/{f=1} f && $1=="path"{print $2; exit}' "$VH"; }
kill_stale() {  # <uid the worker must run as>: end panel lsphp daemons running as anyone else
  local want="$1" bin p
  bin="$(panel_bin)"; [[ -n "$bin" ]] || return 0
  for p in $(pgrep -x lsphp || true); do
    [[ "$(readlink "/proc/$p/exe" 2>/dev/null)" == "$bin" && "$(stat -c %U "/proc/$p" 2>/dev/null)" != "$want" ]] && kill "$p" 2>/dev/null || true
  done
  sleep 1
}
# vhost.conf.bak-user-* piles up one per switch/revert: keep the newest 3
prune_bak() { ls -1t "$VH".bak-user-* 2>/dev/null | tail -n +4 | while IFS= read -r f; do rm -f -- "$f"; done; return 0; }
panel_up() {
  local i c
  for i in 1 2 3 4 5; do
    c="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 10 https://127.0.0.1:8090/login 2>/dev/null || true)"
    [[ "$c" == "200" ]] || c="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 http://127.0.0.1:8090/login 2>/dev/null || true)"
    [[ "$c" == "200" ]] && return 0
    sleep 3
  done
  return 1
}
PROBE=""
cleanup() { [[ -n "$PROBE" ]] && rm -f "$PROBE"; return 0; }
trap cleanup EXIT
# the only check that sees the LSAPI worker: who it is, can it read
# config.php + reach the DB, may it sudo a panel script, can it write storage
worker_ok() {
  local want="$1" name out
  name="uid-probe-$(openssl rand -hex 16).php"
  PROBE="$PANEL/public/$name"
  cat > "$PROBE" <<'PHPEOF'
<?php
if (($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(404); exit; }
$u = function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : '?';
$db = 'FAIL';
try {
    require __DIR__.'/../app/Core/Config.php';
    require __DIR__.'/../app/Core/Database.php';
    Config::load(__DIR__.'/../config/config.php');
    if (Database::conn()->query('SELECT 1')->fetchColumn() == 1) $db = 'OK';
} catch (Throwable $e) { $db = 'FAIL'; }
exec('sudo -n -l /usr/local/skypanel/scripts/mail-queue.sh 2>/dev/null', $o, $c);
$st = is_writable(__DIR__.'/../storage/tmp-uploads') ? 'OK' : 'FAIL';
echo 'USER='.$u.' DB='.$db.' SUDO='.($c === 0 ? 'OK' : 'FAIL').' STORAGE='.$st;
PHPEOF
  chown "$want" "$PROBE"; chmod 600 "$PROBE"
  out="$(curl -sk --max-time 10 "https://127.0.0.1:8090/$name" 2>/dev/null || true)"
  [[ "$out" == USER=* ]] || out="$(curl -s --max-time 10 "http://127.0.0.1:8090/$name" 2>/dev/null || true)"
  rm -f "$PROBE"; PROBE=""
  echo "worker probe: ${out:-no answer}"
  [[ "${PANEL_USER_FORCE_FAIL:-0}" == 1 ]] && { echo "PANEL_USER_FORCE_FAIL=1 (test hook)"; return 1; }
  [[ "$out" == "USER=$want DB=OK SUDO=OK STORAGE=OK" ]]
}
# runtime state follows the worker: <user> <group>
own_state() {
  local u="$1" g="$2" c s
  # every release's storage (rollback targets go live with theirs)
  for s in "$PANEL/storage" /usr/local/skypanel/releases/*/panel/storage; do
    [[ -d "$s" && ! -L "$s" ]] && chown -R "$u:$g" "$s/"
  done
  [[ -f /var/log/karnakpanel/panel.log ]] && chown "$u:$g" /var/log/karnakpanel/panel.log
  while IFS= read -r -d '' c; do chown root:"$g" "$c"; chmod 640 "$c"; done \
    < <(find /usr/local/skypanel -path '*/storage' -prune -o -path '*/config/config.php' -type f -print0 2>/dev/null)
  # live sessions: files only, never through a link (the dir is 1733)
  [[ -d "$SESS" ]] && find "$SESS" -maxdepth 1 -type f -name 'sess_*' ! -user "$u" \( -user nobody -o -user karnak-panel \) -exec chown -h "$u:$g" {} + 2>/dev/null || true
  # the 3s dashboard cache moved into storage/; the old /tmp copy is dead
  rm -f /tmp/karnakpanel-live.json
}
# vhost: add/remove extUser+extGroup on the panel's extprocessor (a copy,
# checked, then swapped — the backup is the caller's)
vhost_set() {  # <on|off>
  local TMP
  TMP="$(mktemp "$VH.user-XXXXXX")"
  grep -vE '^[[:space:]]*ext(User|Group)[[:space:]]' "$VH" > "$TMP"
  if [[ "$1" == on ]]; then
    sed -i -E '/^extprocessor lsphp8[0-9] \{$/a\  extUser                 karnak-panel\n  extGroup                karnak-panel' "$TMP"
    [[ "$(grep -cE '^[[:space:]]*ext(User|Group)[[:space:]]+karnak-panel$' "$TMP")" == 2 ]] || { rm -f "$TMP"; echo "panel-user: vhost has no single 'extprocessor lsphp8N {' block — refusing"; return 1; }
  fi
  chmod --reference="$VH" "$TMP"; chown --reference="$VH" "$TMP"
  mv -f "$TMP" "$VH"
}
. "$HERE/panel-id.sh"

if [[ "$MODE" == revert ]]; then
  [[ "$PANEL_U" == nobody ]] && { echo "panel-user: panel already runs as nobody"; exit 0; }
  "$HERE/panel-sudoers.sh" both
  # nobody is about to be the panel again: its home-wide reads first
  WEB_ACL_PANEL=nobody "$HERE/home-perms.sh" all || echo "WARN: home-perms reported a problem giving nobody back its reads — check the file manager after the revert"
  WEB_ACL_PANEL=nobody "$HERE/perms-fix.sh" >/dev/null 2>&1 || true
  cp -a "$VH" "$VH.bak-user-$(date +%Y%m%d%H%M%S)"; prune_bak
  vhost_set off
  own_state nobody nogroup
  restart_ols; kill_stale nobody
  "$HERE/panel-sudoers.sh" nobody
  panel_up && echo "panel-user: reverted — panel runs as nobody" || { echo "CRITICAL: panel not answering after revert — check $VH"; exit 1; }
  exit 0
fi

if [[ -f "$ST/panel-user-nobody" ]]; then echo "panel-user: pinned to nobody ($ST/panel-user-nobody)"; exit 0; fi
if [[ "$PANEL_U" == karnak-panel ]]; then
  # already moved: keep state ownership + sudoers right (quiet), and make
  # sure no stale daemon still answers as nobody
  own_state karnak-panel karnak-panel
  kill_stale karnak-panel
  exit 0
fi
if [[ "${PANEL_USER_RETRY:-0}" != 1 && -f "$ST/panel-user-failed" && "$(cat "$ST/panel-user-failed")" == "$PVER" ]]; then
  echo "panel-user: switch failed earlier in $PVER — skipped (PANEL_USER_RETRY=1 to retry)"
  exit 0
fi
[[ -f "$VH" ]] || { echo "panel-user: no $VH"; exit 1; }
fail_mark() { mkdir -p "$ST"; echo "$PVER" > "$ST/panel-user-failed"; }

# 1. the new uid can already read what nobody reads (ACL twins), and both
#    uids may sudo while the worker restarts
"$HERE/home-perms.sh" all || true
"$HERE/perms-fix.sh" >/dev/null 2>&1 || true
"$HERE/panel-sudoers.sh" both
# 2. switch
BAK="$VH.bak-user-$(date +%Y%m%d%H%M%S)"
cp -a "$VH" "$BAK"; prune_bak
vhost_set on || { "$HERE/panel-sudoers.sh" nobody; fail_mark; exit 1; }
own_state karnak-panel karnak-panel
restart_ols; kill_stale karnak-panel
# 3. prove it, or put everything back
if panel_up && worker_ok karnak-panel; then
  "$HERE/panel-sudoers.sh" karnak-panel
  rm -f "$ST/panel-user-failed"
  echo "panel-user: panel worker now runs as karnak-panel (backup $BAK)"
  # 4. the web server keeps only docroots (split) — now, not at the next nightly run
  "$HERE/home-perms.sh" all || true
  "$HERE/perms-fix.sh" >/dev/null 2>&1 || true
else
  echo "FAILED: panel as karnak-panel did not pass the health check — restoring nobody"
  cp -a "$BAK" "$VH"
  own_state nobody nogroup
  restart_ols; kill_stale nobody
  "$HERE/panel-sudoers.sh" nobody
  if panel_up; then echo "restored: panel back on nobody and answering"
  else echo "CRITICAL: panel still not answering after restore — check $VH and lshttpd NOW"; fi
  fail_mark; exit 1
fi
