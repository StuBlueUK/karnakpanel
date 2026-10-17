#!/usr/bin/env bash
# php-retire.sh <8.x> — remove a PHP version from this box, but ONLY when
# nothing uses it (data-driven: fleet boxes from the public mirror may still
# host sites on it). In use =
#   pool files in /etc/php/<v>/fpm/pool.d (customer + app pools, including
#     quota-parked *.conf.suspended; the distro www.conf doesn't count),
#   domains/packages rows naming it,
#   lsphp<vv> in any live OLS *.conf (older vhosts may use lsapi).
# All zero: back up /etc/php/<v> to /root/php<v>-retired-<ts>.tgz, stop +
# disable php<v>-fpm, purge php<v>-* and lsphp<vv>*, restore the module
# links/state the purge strips from OTHER versions (verified), re-baseline.
# Otherwise: purge nothing, WARN with the accounts, admin notification (the
# PHP page offers the bulk move). Refusal exits 0 — it is a normal state,
# re-checked on every converge. Never retires a selectable version.
set -euo pipefail
HERE="$(dirname "$(readlink -f "$0")")"
. "$HERE/php-versions.sh"
V="${1:-}"
[[ "$V" =~ ^8\.[0-9]$ ]] || { echo "usage: $0 <8.x>"; exit 1; }
for s in $PHP_SELECTABLE; do [[ "$s" == "$V" ]] && { echo "PHP $V is selectable — refusing to retire it"; exit 1; }; done
VV="${V/./}"
export DEBIAN_FRONTEND=noninteractive

mapfile -t PKGS < <(dpkg-query -W -f '${db:Status-Abbrev} ${Package}\n' "php$V-*" "lsphp$VV*" 2>/dev/null | awk '$1 ~ /^[hi]i$|^rc$/ {print $2}')
if (( ${#PKGS[@]} == 0 )) && [[ ! -e "/usr/sbin/php-fpm$V" && ! -d "/etc/php/$V" ]]; then
  echo "PHP $V not present — nothing to retire"
  exit 0
fi

POOLS=()
if [[ -d "/etc/php/$V/fpm/pool.d" ]]; then
  for f in /etc/php/"$V"/fpm/pool.d/*.conf /etc/php/"$V"/fpm/pool.d/*.conf.suspended; do
    [[ -f "$f" && "$(basename "$f")" != www.conf ]] && POOLS+=("$(basename "$f")")
  done
fi
DB_DOM="$(mysql -uroot -N skypanel -e "SELECT CONCAT(u.username,':',d.domain) FROM domains d LEFT JOIN users u ON u.id=d.user_id WHERE d.php_version='$V' ORDER BY u.username, d.domain;" 2>/dev/null)" \
  || { echo "cannot read the panel DB — refusing to retire PHP $V"; exit 1; }
DB_PKG="$(mysql -uroot -N skypanel -e "SELECT name FROM packages WHERE php_version='$V';" 2>/dev/null || true)"
# live configs only: OLS keeps *.conf0 / RCS ,v copies and we keep .bak-* ones;
# plus fcgi-bin wrappers that resolve into the lsphp tree (a symlink is
# invisible to the conf grep)
LS_REFS="$(grep -rlw --include='*.conf' "lsphp$VV" /usr/local/lsws/conf 2>/dev/null || true)"
for f in /usr/local/lsws/fcgi-bin/*; do
  [[ -L "$f" && "$(readlink -f "$f")" == /usr/local/lsws/lsphp$VV/* ]] && LS_REFS+="${LS_REFS:+$'\n'}$f"
done

if (( ${#POOLS[@]} )) || [[ -n "$DB_DOM$DB_PKG$LS_REFS" ]]; then
  echo "WARN: PHP $V still in use — nothing removed"
  (( ${#POOLS[@]} )) && echo "  pools: ${POOLS[*]}"
  [[ -n "$DB_DOM" ]] && echo "  sites: $(paste -sd' ' <<<"$DB_DOM")"
  [[ -n "$DB_PKG" ]] && echo "  plans: $(paste -sd' ' <<<"$DB_PKG")"
  [[ -n "$LS_REFS" ]] && echo "  lsphp$VV referenced by: $(paste -sd' ' <<<"$LS_REFS")"
  ACCTS="$(cut -d: -f1 <<<"$DB_DOM" | sort -u | paste -sd' ' -)"
  TITLE="PHP $V is retiring: ${ACCTS:-some configs} still on it — move them on the PHP page"
  TITLE="${TITLE:0:190}"; TITLE="${TITLE//\'/}"
  mysql -uroot skypanel -e "INSERT INTO notifications(user_id,type,title) SELECT NULL,'php-retire','$TITLE' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM notifications WHERE user_id IS NULL AND type='php-retire' AND created_at > (NOW() - INTERVAL 7 DAY));" 2>/dev/null || true
  exit 0
fi

# apt removes reverse dependencies without asking: simulate first and
# refuse if the purge would take anything that isn't this version's own
if (( ${#PKGS[@]} )); then
  SIM="$(apt-get -s -o DPkg::Lock::Timeout=600 purge "${PKGS[@]}" 2>&1)" || { echo "purge simulation failed — nothing removed"; echo "$SIM" | tail -5; exit 1; }
  EXTRA="$(awk '$1=="Purg"||$1=="Remv"{print $2}' <<<"$SIM" | grep -vE "^(php${V//./\.}-|lsphp$VV)" || true)"
  if [[ -n "$EXTRA" ]]; then
    echo "WARN: purging PHP $V would also remove: $(paste -sd' ' <<<"$EXTRA") — refusing; remove by hand if intended"
    exit 0
  fi
fi
# LiteSpeed packaging bug: an lsphpNN-<ext> purged AFTER lsphpNN-common
# (which owns lsphpNN/bin/php-maintscript-helper) falls back to the SYSTEM
# helper and runs `phpdismod ALL ALL <ext>` = that module off for every
# sury version and SAPI (seen on test: pdo_mysql/mysqli/curl/intl/opcache
# off on 8.2-8.5 FPM + CLI, hidden by running FPM masters until the next
# pool restart). Prevent: purge lsphp extensions first, while -common is
# still there. Guard anyway: snapshot the other versions' conf.d links,
# helper state and loaded modules; restore exactly after, then prove it.
SNAP="$(mktemp -d)"
find /etc/php -path '*/conf.d/*' -type l ! -path "/etc/php/$V/*" -printf '%p\t%l\n' 2>/dev/null | sort > "$SNAP/links"
find /var/lib/php/modules -type f ! -path "/var/lib/php/modules/$V/*" ! -path "/var/lib/php/modules/registry/$V" 2>/dev/null | sort > "$SNAP/state"
OTHERS=(); for f in /usr/sbin/php-fpm8.[0-9]; do [[ -x "$f" && "$f" != "/usr/sbin/php-fpm$V" ]] && OTHERS+=("${f#/usr/sbin/php-fpm}"); done
for o in "${OTHERS[@]}"; do
  "/usr/sbin/php-fpm$o" -m > "$SNAP/fpm-$o" 2>/dev/null || true
  [[ -x "/usr/bin/php$o" ]] && "/usr/bin/php$o" -m > "$SNAP/cli-$o" 2>/dev/null || true
done
restore_mods() {
  local l t f n=0 bad=""
  while IFS=$'\t' read -r l t; do
    [[ -L "$l" || ! -e "$t" ]] && continue
    ln -s "$t" "$l" && n=$((n+1))
  done < "$SNAP/links"
  # helper state: drop entries the purge added, recreate ones it removed
  find /var/lib/php/modules -type f ! -path "/var/lib/php/modules/$V/*" ! -path "/var/lib/php/modules/registry/$V" 2>/dev/null | sort > "$SNAP/state.after"
  comm -13 "$SNAP/state" "$SNAP/state.after" | while read -r f; do rm -f "$f"; done
  comm -23 "$SNAP/state" "$SNAP/state.after" | while read -r f; do mkdir -p "$(dirname "$f")" && touch "$f"; done
  (( n )) && echo "restored $n module links on other PHP versions (maintscript side effect)"
  for o in "${OTHERS[@]}"; do
    cmp -s "$SNAP/fpm-$o" <("/usr/sbin/php-fpm$o" -m 2>/dev/null) || bad+="fpm$o "
    [[ -f "$SNAP/cli-$o" ]] && { cmp -s "$SNAP/cli-$o" <("/usr/bin/php$o" -m 2>/dev/null) || bad+="cli$o "; }
  done
  rm -f "$SNAP"/*; rmdir "$SNAP"
  [[ -z "$bad" ]] || { echo "CRITICAL: module sets changed on $bad after purging PHP $V — fix with phpenmod BEFORE any pool restart"; return 1; }
}
TGZ="/root/php$V-retired-$(date +%Y%m%d%H%M%S).tgz"
if [[ -d "/etc/php/$V" ]]; then
  tar -czf "$TGZ" -C /etc/php "$V" && chmod 600 "$TGZ" && echo "config backup: $TGZ" \
    || { echo "backup of /etc/php/$V failed — nothing removed"; rm -f "$SNAP"/*; rmdir "$SNAP"; exit 1; }
fi
systemctl disable --now "php$V-fpm" >/dev/null 2>&1 || true
PRC=0
LS_EXT=(); REST=()
for p in "${PKGS[@]}"; do
  if [[ "$p" == lsphp$VV-* && "$p" != "lsphp$VV-common" ]]; then LS_EXT+=("$p"); else REST+=("$p"); fi
done
if (( ${#LS_EXT[@]} )); then
  apt-get -y -o DPkg::Lock::Timeout=600 purge "${LS_EXT[@]}" || PRC=$?
fi
if (( PRC == 0 && ${#REST[@]} )); then
  apt-get -y -o DPkg::Lock::Timeout=600 purge "${REST[@]}" || PRC=$?
fi
# restore runs even after a failed purge (the helper may have half-run)
restore_mods || exit 1
(( PRC == 0 )) || { echo "purge of PHP $V failed (apt above)"; exit 1; }
# dpkg leaves the lsphp tree as empty dirs — drop those (empty only)
[[ -d "/usr/local/lsws/lsphp$VV" ]] && find "/usr/local/lsws/lsphp$VV" -depth -type d -empty -delete 2>/dev/null || true
"$HERE/integrity-check.sh" --baseline >/dev/null 2>&1 || true
echo "PHP $V retired (${#PKGS[@]} packages purged)"
