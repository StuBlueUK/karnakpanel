#!/usr/bin/env bash
# panel-vhost-dotfiles.sh — harden the PANEL's own vhost (:8090). It served
# any existing file under public/, dot-paths included: a stray .env/.git/.bak
# dropped in the docroot was returned as a static file (sweep Low 2026-11-08).
#
# Inserts a 403 for dot-path segments BEFORE the front controller, keeping
# /.well-known open (ACME + CalDAV/CardDAV probes). allowBrowse stays 1 on
# purpose: 0 makes OLS deny the whole context ("Context [/] is not
# accessible", even /index.php), which breaks every clean URL — and directory
# requests already 404 here. The installer template ships the same rule; this
# backfills existing boxes: backup, render, reload, prove it live (panel 200,
# static asset 200, dot-path 403, .well-known not 403) and restore on failure.
# Idempotent; quiet when nothing changes. Called by host-converge.sh.
set -euo pipefail
VH=/usr/local/lsws/conf/vhosts/SkyPanel/vhost.conf
[[ -f "$VH" && ! -L "$VH" ]] || exit 0
DOT='RewriteCond %{REQUEST_URI} (^|/)\. [NC]'
grep -qxF "$DOT" "$VH" && exit 0
BAK="$VH.pre-dotfiles"
cp -a "$VH" "$BAK"
fail() {
  cp -a "$BAK" "$VH"; rm -f "$BAK"
  /usr/local/lsws/bin/lswsctrl reload >/dev/null 2>&1 || systemctl reload lsws 2>/dev/null || true
  echo "panel-vhost-dotfiles: $1 — vhost restored"; exit 1
}
# insert before the front-controller CONDITIONS (a rule must not inherit the
# !-f/!-d checks, or static files get routed to index.php and 404)
cp -a "$VH" "$VH.tmp"
awk '
  !done && $0 == "RewriteCond %{REQUEST_FILENAME} !-f" {
    print "RewriteCond %{REQUEST_URI} !^/\\.well-known/"
    print "RewriteCond %{REQUEST_URI} (^|/)\\. [NC]"
    print "RewriteRule ^ - [F]"
    done = 1
  }
  { print }
' "$VH" > "$VH.tmp"
if [[ "$(grep -cF "$DOT" "$VH.tmp")" == 1 && "$(wc -l < "$VH.tmp")" == "$(( $(wc -l < "$VH") + 3 ))" ]]; then
  chown --reference="$BAK" "$VH.tmp"; chmod --reference="$BAK" "$VH.tmp"
  mv -f "$VH.tmp" "$VH"
else
  rm -f "$VH.tmp"; fail "rewrite block not found (unexpected vhost shape)"
fi
rm -f "$VH.tmp"
/usr/local/lsws/bin/lswsctrl reload >/dev/null 2>&1 || systemctl reload lsws 2>/dev/null || true
# OLS applies a vhost change asynchronously and may restart the worker: give it
# up to 20 s before judging, or a healthy panel gets rolled back (seen live).
for _ in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20; do
  [[ "$(curl -sk -o /dev/null -w '%{http_code}' --max-time 5 https://127.0.0.1:8090/login 2>/dev/null || true)" == 200 ]] && break
  sleep 1
done
code() {
  local p="$1" c
  c="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 10 "https://127.0.0.1:8090$p" 2>/dev/null || true)"
  if [[ -z "$c" || "$c" == 000 ]]; then
    c="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "http://127.0.0.1:8090$p" 2>/dev/null || true)"
  fi
  echo "$c"
}
[[ "$(code /login)" == 200 ]] || fail "panel does not answer 200 on /login"
[[ "$(code /.git/config)" == 403 ]] || fail "dot-path is not 403"
[[ "$(code /.well-known/)" != 403 ]] || fail ".well-known is blocked"
[[ "$(code /assets/css/karnakpanel.css)" != 403 ]] || fail "static assets are blocked"
rm -f "$BAK"
echo "panel-vhost-dotfiles: panel vhost refuses dot-paths (/.well-known stays open)"
