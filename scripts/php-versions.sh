#!/usr/bin/env bash
# php-versions.sh — SOURCED, never run. The one list of customer PHP versions
# for the scripts; mirror of app/Core/PhpVersions.php (tests/run.php fails if
# the two drift). Lives flat in scripts/ because the update package ships
# scripts/*.sh only.
#   PHP_SELECTABLE  offered for new sites (PHP_DEFAULT first)
#   PHP_LEGACY      served for sites already on it, never offered
# Known = DETECTED: any 8.x whose php-fpm binary is installed on this box, so a
# box still carrying 8.1 sites keeps them working until php-retire.sh.
PHP_SELECTABLE="8.3 8.4 8.5"
PHP_LEGACY="8.2"
PHP_DEFAULT="8.3"

# installed 8.x versions, one per line, ascending ("8.3")
php_installed() {
  local f
  for f in /usr/sbin/php-fpm8.[0-9]; do
    [[ -x "$f" ]] && echo "${f#/usr/sbin/php-fpm}"
  done
  return 0
}
# php_known 8.3 — dotted form, installed here
php_known() { [[ "${1:-}" =~ ^8\.[0-9]$ && -x "/usr/sbin/php-fpm$1" ]]; }
# php_known_short 83 — the dotless form the pool scripts take
php_known_short() { [[ "${1:-}" =~ ^8[0-9]$ ]] && php_known "8.${1:1}"; }
# php_selectable 8.4 — on the curated list AND installed
php_selectable() {
  local v
  for v in $PHP_SELECTABLE; do [[ "$v" == "${1:-}" ]] && php_known "$v" && return 0; done
  return 1
}
# pool dirs that exist (a pool left on a just-removed version still counts)
php_pool_dirs() {
  local d
  for d in /etc/php/8.[0-9]/fpm/pool.d; do [[ -d "$d" ]] && echo "$d"; done
  return 0
}
