#!/usr/bin/env bash
# ols-autofix503.sh — turn off OpenLiteSpeed's autoFix503 (stock: 1). With it
# on, OLS gracefully restarts the WHOLE server when it counts ~30 503s in 30 s.
# Our 503s are deliberate: a customer's FPM pool at max_children with its small
# listen.backlog (php-pool.sh) refuses overflow fast. So one scanner flooding
# one WordPress site restarted every site on the box and mailed the admin
# "restarts automatically to fix 503 Errors" (prod 2026-11-01). The pool
# recovers on its own; a restart fixes nothing. Called by the installer
# (openlitespeed lib) and host-converge. Idempotent; quiet when already off.
set -euo pipefail
CONF=/usr/local/lsws/conf/httpd_config.conf
[[ -f "$CONF" ]] || exit 0
grep -Eq '^autoFix503[[:space:]]+0[[:space:]]*$' "$CONF" && exit 0
cp -a "$CONF" "$CONF.pre-autofix503"
if grep -Eq '^autoFix503[[:space:]]' "$CONF"; then
  sed -i -E 's/^autoFix503[[:space:]].*$/autoFix503                       0/' "$CONF"
elif grep -Eq '^autoRestart[[:space:]]' "$CONF"; then
  # top-level key: beside autoRestart, never inside a block
  sed -i -E '0,/^autoRestart[[:space:]]/{/^autoRestart[[:space:]]/a autoFix503                       0
}' "$CONF"
else
  rm -f "$CONF.pre-autofix503"; echo "ols-autofix503: no autoFix503/autoRestart line — config left alone"; exit 1
fi
if [[ "$(grep -Ec '^autoFix503[[:space:]]+0[[:space:]]*$' "$CONF")" != 1 ]]; then
  cat "$CONF.pre-autofix503" > "$CONF"; rm -f "$CONF.pre-autofix503"
  echo "ols-autofix503: edit failed — httpd_config.conf unchanged"; exit 1
fi
rm -f "$CONF.pre-autofix503"
# server-level key: needs a (graceful) restart to take effect
if [[ -x /usr/local/lsws/bin/lswsctrl ]] && pgrep -f 'lshttpd - main' >/dev/null 2>&1; then
  /usr/local/lsws/bin/lswsctrl restart >/dev/null 2>&1 || echo "ols-autofix503: lsws restart failed (applies at next restart)"
fi
echo "ols-autofix503: autoFix503 off (no whole-server restarts on 503 bursts)"
