#!/usr/bin/env bash
# LSPHP for the panel (:8090, lsphp85) and webmail/phpMyAdmin (lsphp83).
# Customer sites never use LSPHP — they run on per-user PHP-FPM pools.
# NOTE: mbstring/xml/zip/bcmath/soap/gd are compiled INTO lsphp core — there are
# NO separate packages for them; php-install.sh probes every name it uses.
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
REPO_SCRIPTS="$(cd "$(dirname "$0")/../../scripts" && pwd)"
# panel tier first choice, then fallbacks; webmail tier (php-ver-ok below)
PANEL_LSPHP_ORDER="8.5 8.4 8.3"  # php-ver-ok: panel runtime preference
bash "$REPO_SCRIPTS/php-install.sh" lsphp 8.3 8.4 8.5 || echo "WARN: not every lsphp version installed — see above"  # php-ver-ok: webmail 8.3 + panel 8.5 (8.4 fallback)
# Debian php libs (roundcube/phpmyadmin) for any lsphp already on the box
for INI in /usr/local/lsws/lsphp[0-9][0-9]/etc/php/*/litespeed/php.ini; do
  [[ -f "$INI" ]] || continue
  grep -q '^\s*include_path' "$INI" || echo 'include_path = ".:/usr/share/php"' >> "$INI"
done
# CLI for panel maintenance scripts: the default version's (/usr/bin/php is
# pinned to it later); the FPM module installs the same packages.
. "$REPO_SCRIPTS/php-versions.sh"
apt-get install -y "php$PHP_DEFAULT-cli" "php$PHP_DEFAULT-mysql" "php$PHP_DEFAULT-mbstring" "php$PHP_DEFAULT-xml" "php$PHP_DEFAULT-curl" "php$PHP_DEFAULT-zip" \
  || apt-get install -y php-cli php-mysql || true
# skypanel-php = the panel's own runtime; the vhost template is rendered to
# the same version (openlitespeed.sh runs earlier and is re-pointed by
# panel-php-patch.sh on first converge if the preferred one was missing)
for V in $PANEL_LSPHP_ORDER; do
  if [[ -x "/usr/local/lsws/lsphp${V/./}/bin/lsphp" ]]; then
    ln -sf "/usr/local/lsws/lsphp${V/./}/bin/php" /usr/bin/skypanel-php
    # fresh install: the vhost was just rendered from the template (lsphp85);
    # follow a fallback so the panel never points at a missing binary
    sed -i "/^\s*#/!s/lsphp8[0-9]/lsphp${V/./}/g" /usr/local/lsws/conf/vhosts/SkyPanel/vhost.conf 2>/dev/null || true
    echo "panel runtime: lsphp${V/./}"
    break
  fi
done
/usr/local/lsws/bin/lswsctrl restart >/dev/null 2>&1 || true
