#!/usr/bin/env bash
# fpm-harden.sh [--all | username] — re-apply pool hardening (disable_functions,
# open_basedir, memory cap, nice) by regenerating pool files via php-pool.sh.
# Idempotent: regenerating an already-hardened pool is a no-op reload.
# Weekly via cron + on demand from Admin > PHP pools.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
. "$HERE/php-versions.sh"
[[ "${1:-}" =~ ^([a-z][a-z0-9_]{2,31}|--all)$ ]] || { echo "usage: $0 [--all|username]"; exit 1; }
TARGET="$1"
harden_one() {
    local U="$1" CONF="" VER=""
    local PD
    while IFS= read -r PD; do
        if [[ -f "$PD/skypanel-$U.conf" ]]; then
            CONF="$PD/skypanel-$U.conf"; VER="${PD#/etc/php/}"; VER="${VER%%/*}"; VER="${VER/./}"; break
        fi
    done < <(php_pool_dirs)
    [[ -n "$CONF" ]] || { echo "$U: no pool (skipped)"; return 0; }
    local C
    C="$(grep -E '^pm\.max_children' "$CONF" | grep -oE '[0-9]+' | head -1 || true)"
    [[ "$C" =~ ^[0-9]{1,2}$ ]] || C=10
    "$HERE/php-pool.sh" "$U" "$VER" "$C" >/dev/null
    if grep -q '^php_admin_value\[disable_functions\]' "$CONF"; then
        echo "$U: hardened (php8.${VER:1}, children=$C)"
    else
        echo "$U: FAILED to harden"; return 1
    fi
}
if [[ "$TARGET" == "--all" ]]; then
    FAIL=0
    while IFS= read -r f; do
        U="$(basename "$f" | sed 's/^skypanel-//; s/\.conf$//')"
        harden_one "$U" || FAIL=1
    done < <(ls /etc/php/8.*/fpm/pool.d/skypanel-*.conf 2>/dev/null || true)
    [[ $FAIL -eq 0 ]] || exit 1
    echo "all pools hardened"
else
    harden_one "$TARGET"
fi
