#!/usr/bin/env bash
# panel-php-patch.sh — move the LIVE panel vhost (:8090) to lsphp85 (or the
# PANEL_LSPHP=84|83 an admin pins as a rollback target). Fresh installs render
# it from installer/configs; existing boxes need this patcher (host-converge).
# Same discipline as the exim patchers: refuse odd shapes, patch a copy,
# back up, swap — and because a dead panel locks the admin out of the fix,
# PROVE it after the restart (login 200 + a worker probe that sees the LSAPI
# worker's PHP version, extensions and DB) and restore the backup on failure.
# Not ready (target lsphp missing) = exit 0, panel left as is. Re-runs no-op.
# Test hook: PANEL_PHP_FORCE_FAIL=1 fails the health check (rollback proof).
# Pin: /var/lib/karnakpanel/panel-lsphp holding "84" or "83" keeps converge
# from moving the panel again (an env PANEL_LSPHP wins for a manual run).
# A failed or refused run leaves panel-php-failed (= panel version): later
# converges of the SAME release skip instead of restarting OLS daily;
# PANEL_PHP_RETRY=1 forces a retry.
set -euo pipefail
ST=/var/lib/karnakpanel
PIN=""; [[ -f "$ST/panel-lsphp" ]] && PIN="$(tr -dc 0-9 < "$ST/panel-lsphp")"
VV="${PANEL_LSPHP:-$PIN}"
VV="${VV:-85}"  # php-ver-ok: panel runtime target
PVER="$(cat /usr/local/skypanel/panel/VERSION 2>/dev/null || echo unknown)"
if [[ "${PANEL_PHP_RETRY:-0}" != 1 && -f "$ST/panel-php-failed" && "$(cat "$ST/panel-php-failed")" == "$PVER" ]]; then
  echo "panel PHP move failed earlier in $PVER — skipped (PANEL_PHP_RETRY=1 to retry)"
  exit 0
fi
fail_mark() { mkdir -p "$ST"; echo "$PVER" > "$ST/panel-php-failed"; }
[[ "$VV" =~ ^8[3-9]$ ]] || { echo "PANEL_LSPHP must be 83..89"; exit 1; }  # php-ver-ok: lsphp rollback range
VH="/usr/local/lsws/conf/vhosts/SkyPanel/vhost.conf"
PUB="/usr/local/skypanel/panel/public"
D="/usr/local/lsws/lsphp$VV"
HERE="$(dirname "$(readlink -f "$0")")"
. "$HERE/panel-id.sh"

restart_ols() { systemctl restart lshttpd 2>/dev/null || /usr/local/lsws/bin/lswsctrl restart >/dev/null 2>&1 || true; }
# the old runtime's panel daemon survives the OLS restart (detached, ppid 1)
# and idles on its old socket: end it. Owner = the panel worker, so the
# server-level lsphp83 handler (nobody) is never touched.
kill_old() {  # <lsphp8N the panel just left>
  local bin="/usr/local/lsws/lsphp$1/bin/lsphp" p
  for p in $(pgrep -x lsphp || true); do
    [[ "$(readlink "/proc/$p/exe" 2>/dev/null)" == "$bin" && "$(stat -c %U "/proc/$p" 2>/dev/null)" == "$PANEL_U" ]] && kill "$p" 2>/dev/null || true
  done
  return 0
}
# vhost.conf.bak-php-* piles up one per move: keep the newest 3
prune_bak() { ls -1t "$VH".bak-php-* 2>/dev/null | tail -n +4 | while IFS= read -r f; do rm -f -- "$f"; done; return 0; }
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
# the only check that sees the LSAPI worker (CLI `php -m` scans other inis)
PROBE=""
cleanup() { [[ -n "$PROBE" ]] && rm -f "$PROBE"; return 0; }
trap cleanup EXIT
worker_ok() {
  local want="$1" name out
  name="php-probe-$(openssl rand -hex 16).php"
  PROBE="$PUB/$name"
  cat > "$PROBE" <<'PHPEOF'
<?php
if (($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(404); exit; }
$miss = [];
foreach (['pdo_mysql','curl','mbstring','simplexml','zip','session','json','openssl','intl'] as $e) {
    if (!extension_loaded($e)) $miss[] = $e;
}
$db = 'FAIL';
try {
    require __DIR__.'/../app/Core/Config.php';
    require __DIR__.'/../app/Core/Database.php';
    Config::load(__DIR__.'/../config/config.php');
    if (Database::conn()->query('SELECT 1')->fetchColumn() == 1) $db = 'OK';
} catch (Throwable $e) { $db = 'FAIL'; }
echo 'PHP='.PHP_MAJOR_VERSION.PHP_MINOR_VERSION.' MISSING='.implode(',', $miss).' DB='.$db;
PHPEOF
  chown "$PANEL_U" "$PROBE" 2>/dev/null || true
  chmod 600 "$PROBE"
  out="$(curl -sk --max-time 10 "https://127.0.0.1:8090/$name" 2>/dev/null || true)"
  [[ "$out" == PHP=* ]] || out="$(curl -s --max-time 10 "http://127.0.0.1:8090/$name" 2>/dev/null || true)"
  rm -f "$PROBE"; PROBE=""
  echo "worker probe: ${out:-no answer}"
  [[ "${PANEL_PHP_FORCE_FAIL:-0}" == 1 ]] && { echo "PANEL_PHP_FORCE_FAIL=1 (test hook)"; return 1; }
  [[ "$out" == "PHP=$want MISSING= DB=OK" ]]
}
repoint_cli() {
  [[ "$(readlink -f /usr/bin/skypanel-php 2>/dev/null)" == "$(readlink -f "$D/bin/php")" ]] && return 0
  ln -sf "$D/bin/php" /usr/bin/skypanel-php && echo "skypanel-php -> lsphp$VV"
}

# 1. preflight: target runtime installed and wired (php-install.sh lsphp)
if [[ ! -x "$D/bin/lsphp" ]] || ! grep -qs '^\s*include_path' "$D"/etc/php/*/litespeed/php.ini; then
  echo "lsphp$VV not ready, panel left on $(grep -v '^\s*#' "$VH" 2>/dev/null | grep -o 'lsphp8[0-9]' | head -1 || echo '?')"
  exit 0
fi
[[ -f "$VH" ]] || { echo "no $VH"; exit 1; }
# 2. shape: exactly 4 lsphp8N tokens (add / extprocessor / address / path),
# one version — anything else was hand-edited (WebAdmin) and we don't guess
# (comment lines don't count and are never rewritten)
mapfile -t TOK < <(grep -v '^\s*#' "$VH" | grep -o 'lsphp8[0-9]')
mapfile -t UNIQ < <(printf '%s\n' "${TOK[@]}" | sort -u)
if (( ${#TOK[@]} != 4 || ${#UNIQ[@]} != 1 )); then
  echo "panel vhost hand-edited (${#TOK[@]} lsphp tokens: ${UNIQ[*]:-none}) — refusing; fix $VH by hand"
  fail_mark; exit 1
fi
CUR="${UNIQ[0]#lsphp}"
# 3. already there
if [[ "$CUR" == "$VV" ]]; then
  echo "panel already on lsphp$VV"
  rm -f "$ST/panel-php-failed"
  repoint_cli
  exit 0
fi
# 4. patch a copy, back up, swap, restart
BAK="$VH.bak-php-$(date +%Y%m%d%H%M%S)"
TMP="$(mktemp "$VH.php-XXXXXX")"
cp -a "$VH" "$TMP"
sed -i "/^\s*#/!s/lsphp$CUR/lsphp$VV/g" "$TMP"
[[ "$(grep -v '^\s*#' "$TMP" | grep -o "lsphp$VV" | wc -l)" == 4 ]] || { rm -f "$TMP"; echo "rewrite produced the wrong shape — live vhost untouched"; exit 1; }
cp -a "$VH" "$BAK"; prune_bak
mv -f "$TMP" "$VH"
restart_ols; kill_old "$CUR"
# 5. prove it, or put it back
if panel_up && worker_ok "$VV"; then
  echo "panel moved lsphp$CUR -> lsphp$VV (backup $BAK)"
else
  echo "FAILED: panel on lsphp$VV did not pass the health check — restoring lsphp$CUR"
  cp -a "$BAK" "$VH"
  restart_ols; kill_old "$VV"
  if panel_up; then echo "restored: panel back on lsphp$CUR and answering"
  else echo "CRITICAL: panel still not answering after restore — check $VH and lshttpd NOW"; fi
  fail_mark; exit 1
fi
# 6. CLI follows only after the worker is proven
rm -f "$ST/panel-php-failed"
repoint_cli
# 7. the vhost is outside the tripwire, but skypanel-php is a known writer
"$HERE/integrity-check.sh" --baseline >/dev/null 2>&1 || true
