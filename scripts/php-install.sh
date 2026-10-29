#!/usr/bin/env bash
# php-install.sh <fpm|lsphp|wire> <8.x>... — install PHP versions, ONE apt
# transaction per version, then verify what the runtime really loads.
#   fpm    sury php8.x-fpm + the extension set customer apps need (per-user
#          pools, php-pool.sh). Verified via `php-fpm8.x -m` (the FPM SAPI).
#   lsphp  LiteSpeed lsphpNN for the panel/webmail tier, plus the ini wiring
#          the LSAPI workers need (intl/curl lines only where no package ini loads them).
#   wire   only that ini wiring, on lsphp trees already present.
# Package names are PROBED, never templated blindly: lsphp85-opcache and
# php8.5-opcache do not exist (OPcache is built into 8.5), and one unknown
# name fails a whole apt line. A version that fails is a loud WARN naming
# it; the others still install. Exit 1 if any requested version failed.
# Called by the installer (php-fpm / php-multiphp modules) and by
# host-converge (new versions on existing boxes). Idempotent.
set -uo pipefail
export DEBIAN_FRONTEND=noninteractive
MODE="${1:-}"; shift || true
[[ "$MODE" == fpm || "$MODE" == lsphp || "$MODE" == wire ]] || { echo "usage: $0 <fpm|lsphp|wire> <8.x>..."; exit 1; }
(( $# )) || { echo "no versions given"; exit 1; }
# converge runs on boxes whose installer predates 99karnakpanel-lock: wait
# for the dpkg lock (unattended-upgrades) instead of failing instantly
APT=(apt-get -y -o DPkg::Lock::Timeout=600 install)
# apt-cache output is captured, never piped into grep -q (SIGPIPE, see
# installer/lib/php-fpm.sh)
has_pkg() { local out; out="$(apt-cache show "$1" 2>/dev/null || true)"; [[ "$out" == *"Package: $1"* ]]; }
# missing names from a module list ("" = all present); case-insensitive
missing_mods() {
  local have="$1" m out=""; shift
  for m in "$@"; do grep -qix "$m" <<<"$have" || out+="$m "; done
  echo "$out"
}

fpm_one() {
  local V="$1" p pk=()
  for p in fpm cli mysql curl mbstring xml zip intl gd bcmath; do pk+=("php$V-$p"); done
  has_pkg "php$V-fpm" || { echo "WARN: php$V-fpm has no install candidate (repo missing it?) — PHP $V skipped"; return 1; }
  has_pkg "php$V-opcache" && pk+=("php$V-opcache")
  "${APT[@]}" "${pk[@]}" || { echo "WARN: PHP $V FPM install failed (apt above) — PHP $V not available"; return 1; }
  # installed is not enabled (seen live: gd present but off)
  phpenmod -v "$V" gd 2>/dev/null || true
  systemctl enable "php$V-fpm" >/dev/null 2>&1 || true
  systemctl start "php$V-fpm" 2>/dev/null || systemctl restart "php$V-fpm" || true
  local miss
  miss="$(missing_mods "$("/usr/sbin/php-fpm$V" -m 2>/dev/null)" pdo_mysql curl mbstring xml zip intl gd 'Zend OPcache')"
  if [[ -n "$miss" ]]; then echo "WARN: php$V-fpm lacks: $miss"; return 1; fi
  systemctl is-active --quiet "php$V-fpm" || { echo "WARN: php$V-fpm installed but not running"; return 1; }
  # the admin's server-wide extensions (Admin > PHP extensions) follow every
  # new version, so versions never drift apart; a miss warns, never fails PHP
  "$(dirname "$(readlink -f "$0")")/php-ext.sh" sync "$V" || echo "WARN: PHP $V lacks some server-wide extensions (Admin > PHP extensions)"
  echo "php$V-fpm OK"
}

# LSAPI ini wiring for one lsphp tree (include_path for /usr/share/php libs,
# intl + curl as explicit extension= lines when nothing else loads them).
# The workers and the CLI scan the same compiled-in dir (mods-available/,
# no PHP_INI_SCAN_DIR from OLS — checked on live boxes), so once the
# lsphpNN-intl / -curl package's own ini is there our line loads the module
# twice: a "Module already loaded" warning on every worker start (hundreds
# in stderr.log). Our line then comes out again. The CLI can't vouch for the
# worker, so panel-php-patch.sh's worker probe checks curl.
lsphp_wire() {
  local D="$1" INI m
  for INI in "$D"/etc/php/*/litespeed/php.ini; do
    [[ -f "$INI" ]] || continue
    grep -q '^\s*include_path' "$INI" || echo 'include_path = ".:/usr/share/php"' >> "$INI"
    for m in intl curl; do
      if grep -qsE "^\s*extension\s*=\s*\"?$m(\.so)?\"?\s*$" "$(dirname "$(dirname "$INI")")"/mods-available/*.ini; then
        if grep -qx "extension=$m.so" "$INI"; then
          sed -i "/^extension=$m\.so$/d" "$INI"; echo "wired: $INI drops duplicate extension=$m.so"
        fi
      elif [[ "$m" == intl ]]; then
        if ls "$D"/lib/php/*/intl.so >/dev/null 2>&1 && ! grep -q '^extension=intl' "$INI"; then
          echo "extension=intl.so" >> "$INI"
        fi
      elif ! "$D/bin/php" -r 'exit(function_exists("curl_init")?0:1);' 2>/dev/null \
         && ls "$D"/lib/php/*/curl.so >/dev/null 2>&1 && ! grep -q '^extension=curl' "$INI"; then
        echo "extension=curl.so" >> "$INI"
      fi
    done
    # sessions: lsphp82-84 compile in /var/lib/php/sessions, lsphp85 compiles
    # in NOTHING (= shared /tmp, never GC'd: gc_probability=0 expects the
    # distro phpsessionclean cron, which only sweeps /var/lib/php/sessions)
    if [[ -d /var/lib/php/sessions ]] && ! grep -q '^\s*session\.save_path' "$INI" \
       && [[ -z "$("$D/bin/php" -r 'echo ini_get("session.save_path");' 2>/dev/null)" ]]; then
      echo 'session.save_path = "/var/lib/php/sessions"' >> "$INI"
      echo "wired: $INI session.save_path"
    fi
  done
}

lsphp_one() {
  local V="$1" VV="${1/./}" p pk=() opt=()
  for p in "" -common -mysql -curl -intl; do pk+=("lsphp$VV$p"); done
  has_pkg "lsphp$VV" || { echo "WARN: lsphp$VV has no install candidate (LiteSpeed repo missing it?) — skipped"; return 1; }
  has_pkg "lsphp$VV-opcache" && pk+=("lsphp$VV-opcache")
  "${APT[@]}" "${pk[@]}" || { echo "WARN: lsphp$VV install failed (apt above)"; return 1; }
  for p in imap sqlite3 redis; do has_pkg "lsphp$VV-$p" && opt+=("lsphp$VV-$p"); done
  (( ${#opt[@]} )) && { "${APT[@]}" "${opt[@]}" || echo "WARN: lsphp$VV optional extensions not installed (${opt[*]})"; }
  local D="/usr/local/lsws/lsphp$VV"
  [[ -x "$D/bin/lsphp" ]] || { echo "WARN: lsphp$VV installed but $D/bin/lsphp missing"; return 1; }
  lsphp_wire "$D"
  # CLI view only (the worker is proven by a served request, panel-php-patch)
  local miss
  miss="$(missing_mods "$("$D/bin/php" -m 2>/dev/null)" pdo_mysql curl mbstring intl SimpleXML zip openssl)"
  if [[ -n "$miss" ]]; then echo "WARN: lsphp$VV lacks: $miss"; return 1; fi
  echo "lsphp$VV OK"
}

rc=0
# wire = ini wiring only on lsphp trees already installed (converge re-runs
# it every time so wiring fixes reach existing boxes); idempotent
if [[ "$MODE" == wire ]]; then
  for V in "$@"; do
    [[ "$V" =~ ^8\.[0-9]$ && -x "/usr/local/lsws/lsphp${V/./}/bin/lsphp" ]] && lsphp_wire "/usr/local/lsws/lsphp${V/./}"
  done
  exit 0
fi
for V in "$@"; do
  [[ "$V" =~ ^8\.[0-9]$ ]] || { echo "bad version $V"; rc=1; continue; }
  if [[ "$MODE" == fpm ]]; then fpm_one "$V" || rc=1; else lsphp_one "$V" || rc=1; fi
done
# new FPM units get the no-symlink filter (restarts them once if added)
if [[ "$MODE" == fpm ]]; then
  bash "$(dirname "$(readlink -f "$0")")/symlink-guard.sh" fpm || rc=1
fi
exit $rc
