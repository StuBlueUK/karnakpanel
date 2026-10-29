#!/usr/bin/env bash
# php-ext.sh <status | install EXT | remove EXT | sync 8.x> — curated PHP
# extensions for the customer FPM versions (Admin > PHP extensions).
#   status   one line per catalog extension: "EXT<TAB>8.3=on 8.4=missing …"
#            (on = installed + loaded by FPM, off = installed, not loaded,
#            missing = not installed, na = no package for that version),
#            then "job<TAB>running|idle" and "list<TAB><server-wide set>".
#   install  every installed 8.x at once, DETACHED (apt across four versions
#            outlives the panel's sudo timeout): log in
#            /var/log/karnakpanel/php-ext.log (Logs → PHP extensions).
#   remove   same, but refused up front when apt would take any other PHP
#            package with it (igbinary would remove redis + memcached).
#   sync     foreground, for php-install.sh: give a newly installed version
#            the server-wide set, so versions never drift apart again.
# Server-wide only: FPM pools share one master per version, and an
# extension= line in one pool was seen loaded in other pools (tested live),
# so there is no per-account switch. Catalog names = the ondrej package
# suffix = the module name `php-fpm8.x -m` prints. Mirror of
# app/Core/PhpExtensions.php (tests/run.php fails if the two drift).
# Never offered: xdebug/pcov/xhprof/excimer (debug, slow, leak data),
# uopz/swoole/openswoole (redefine functions, around disable_functions),
# apcu (one shared-memory cache per FPM master = every account on that
# version reads, wipes and plants each other's entries),
# ioncube and other closed loaders.
set -uo pipefail
export DEBIAN_FRONTEND=noninteractive
. "$(dirname "$(readlink -f "$0")")/php-versions.sh"
PHP_EXTS="imagick redis memcached igbinary msgpack soap gmp bz2 sqlite3 pgsql ldap tidy imap enchant mongodb mailparse ssh2 yaml uuid xmlrpc maxminddb"
LIST=/var/lib/skypanel/php-ext.list
LOG=/var/log/karnakpanel/php-ext.log
LOCK=/run/lock/skypanel-php-ext.lock
APT=(apt-get -y -q -o DPkg::Lock::Timeout=300)
MODE="${1:-}"; EXT="${2:-}"

in_catalog() { [[ " $PHP_EXTS " == *" $1 "* ]]; }
busy() { ! flock -n "$LOCK" true 2>/dev/null; }
# apt-cache output captured, never piped into grep -q (SIGPIPE)
has_pkg() { local out; out="$(apt-cache show "$1" 2>/dev/null || true)"; [[ "$out" == *"Package: $1"* ]]; }
pkg_installed() { [[ "$(dpkg-query -W -f='${db:Status-Abbrev}' "$1" 2>/dev/null)" == ii* ]]; }
fpm_has() { grep -qix "$2" <<<"$("/usr/sbin/php-fpm$1" -m 2>/dev/null)"; }
list_add() { install -d -m 755 "${LIST%/*}"; touch "$LIST"; grep -qx "$1" "$LIST" || echo "$1" >> "$LIST"; }
list_del() { [[ -f "$LIST" ]] && sed -i "/^$1\$/d" "$LIST"; return 0; }
# installed is not enabled (seen live: gd present but off) — every ini the
# package ships, every SAPI
enable_pkg() {
  local ini
  while IFS= read -r ini; do phpenmod -v "$1" "$(basename "$ini" .ini)" 2>/dev/null || true
  done < <(dpkg -L "php$1-$2" 2>/dev/null | grep -E '/mods-available/[a-z0-9_]+\.ini$')
}
reload_fpm() { systemctl reload "php$1-fpm" 2>/dev/null || systemctl restart "php$1-fpm"; }

# one version, one extension; quiet when already there
add_one() {
  local V="$1" E="$2"
  if ! pkg_installed "php$V-$E"; then
    has_pkg "php$V-$E" || { echo "  $V: no package (php$V-$E) — skipped"; return 2; }
    "${APT[@]}" install "php$V-$E" 2>&1 | grep -E '^(E:|Setting up php)' | sed 's/^/  /'
    pkg_installed "php$V-$E" || { echo "  $V: FAILED (apt, see above)"; return 1; }
  fi
  enable_pkg "$V" "$E"
  return 0
}
check_one() {
  if fpm_has "$1" "$2"; then echo "  $1: $2 loaded"; return 0; fi
  echo "  $1: FAILED — installed but php-fpm$1 -m does not list $2"; return 1
}

case "$MODE" in
status)
  declare -A AV IN MODS
  for V in $(php_installed); do
    AV[$V]=" $(apt-cache pkgnames "php$V-" 2>/dev/null | sed "s/^php$V-//" | tr '\n' ' ') "
    IN[$V]=" $(dpkg-query -W -f='${db:Status-Abbrev} ${Package}\n' "php$V-*" 2>/dev/null | awk '$1=="ii"{sub(/^php[0-9.]+-/,"",$2); print $2}' | tr '\n' ' ') "
    MODS[$V]=" $("/usr/sbin/php-fpm$V" -m 2>/dev/null | tr 'A-Z\n' 'a-z ') "
  done
  for E in $PHP_EXTS; do
    L="$E	"
    for V in $(php_installed); do
      if [[ "${MODS[$V]}" == *" $E "* ]]; then S=on
      elif [[ "${IN[$V]}" == *" $E "* ]]; then S=off
      elif [[ "${AV[$V]}" == *" $E "* ]]; then S=missing
      else S=na; fi
      L+="$V=$S "
    done
    echo "${L% }"
  done
  busy && echo "job	running" || echo "job	idle"
  printf 'list\t%s\n' "$([[ -f "$LIST" ]] && tr '\n' ' ' < "$LIST" | sed 's/ $//')"
  ;;
install|remove)
  [[ "$EXT" =~ ^[a-z0-9_]{2,20}$ ]] && in_catalog "$EXT" || { echo "unknown extension: $EXT"; exit 1; }
  busy && { echo "another extension job is still running — try again when it finishes"; exit 1; }
  VERS="$(php_installed | xargs)"
  [[ -n "$VERS" ]] || { echo "no PHP-FPM versions installed"; exit 1; }
  if [[ "$MODE" == remove ]]; then
    PK=(); for V in $VERS; do pkg_installed "php$V-$EXT" && PK+=("php$V-$EXT"); done
    (( ${#PK[@]} )) || { list_del "$EXT"; echo "$EXT is not installed on any version"; exit 0; }
    SIM="$(apt-get -s remove "${PK[@]}" 2>&1)"
    OTHER="$(grep -oE '^Remv [^ ]+' <<<"$SIM" | awk '{print $2}' | grep -vxE "php8\.[0-9]-$EXT" | sort -u | xargs)"
    [[ -z "$OTHER" ]] || { echo "refused: removing $EXT would also remove $OTHER — remove those first"; exit 1; }
  fi
  install -d -m 755 "${LOG%/*}"; touch "$LOG"; chmod 640 "$LOG"
  (
    flock -w 600 9 || { echo "$(date -Is) $MODE $EXT: lock busy, gave up"; exit 1; }
    echo "=== $(date -Is) $MODE $EXT on PHP $VERS ==="
    apt-get update -qq 2>&1 | tail -2
    OK=0; BAD=0
    for V in $VERS; do
      if [[ "$MODE" == install ]]; then
        add_one "$V" "$EXT"; rc=$?
        (( rc == 2 )) && continue
        (( rc == 0 )) || { BAD=1; continue; }
        reload_fpm "$V"
        if check_one "$V" "$EXT"; then OK=1; else BAD=1; fi
      else
        pkg_installed "php$V-$EXT" || continue
        "${APT[@]}" remove "php$V-$EXT" 2>&1 | grep -E '^(E:|Removing php)' | sed 's/^/  /'
        reload_fpm "$V"
        if pkg_installed "php$V-$EXT"; then echo "  $V: FAILED — still installed"; BAD=1
        else echo "  $V: removed"; OK=1; fi
      fi
    done
    if [[ "$MODE" == install ]]; then (( OK )) && list_add "$EXT"; else list_del "$EXT"; fi
    (( BAD )) && echo "=== $(date -Is) finished WITH ERRORS ===" || echo "=== $(date -Is) done ==="
  ) 9>"$LOCK" >>"$LOG" 2>&1 </dev/null &
  echo "started: $MODE $EXT on PHP $VERS"
  ;;
sync)
  V="$EXT"
  php_known "$V" || { echo "bad version: $V"; exit 1; }
  [[ -s "$LIST" ]] || exit 0
  exec 9>"$LOCK"; flock -w 600 9 || { echo "WARN: php-ext lock busy — $V not synced"; exit 1; }
  BAD=0; DID=0
  while IFS= read -r E; do
    in_catalog "$E" || continue
    pkg_installed "php$V-$E" && fpm_has "$V" "$E" && continue
    add_one "$V" "$E"; rc=$?
    (( rc == 0 )) && DID=1
    (( rc == 1 )) && BAD=1
  done < "$LIST"
  (( DID )) && reload_fpm "$V"
  if (( DID )); then while IFS= read -r E; do
    in_catalog "$E" && pkg_installed "php$V-$E" && { check_one "$V" "$E" >/dev/null || { echo "WARN: php$V-fpm lacks $E"; BAD=1; }; }
  done < "$LIST"; fi
  (( BAD )) && exit 1
  (( DID )) && echo "php-ext: PHP $V given the server-wide extension set"
  exit 0
  ;;
*) echo "usage: $0 <status | install EXT | remove EXT | sync 8.x>"; exit 1 ;;
esac
