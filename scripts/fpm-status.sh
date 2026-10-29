#!/usr/bin/env bash
# fpm-status.sh — TSV per user pool for Admin > PHP pools (panel runs as
# nobody and can't read pool files; this runs privileged via ServiceManager).
# Columns: user, php-version, max_children, hardened(yes/no), memory_limit, nice
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/php-versions.sh"
while IFS= read -r PD; do
    OV="${PD#/etc/php/}"; OV="${OV%%/*}"
    for f in "$PD"/skypanel-*.conf; do
        [[ -f "$f" ]] || continue
        U="$(basename "$f" | sed 's/^skypanel-//; s/\.conf$//')"
        C="$(grep -E '^pm\.max_children' "$f" | grep -oE '[0-9]+' | head -1 || true)"
        M="$(grep -E '^php_admin_value\[memory_limit\]' "$f" | sed 's/.*= *//; s/ //g' | head -1 || true)"
        N="$(grep -E '^process\.priority' "$f" | grep -oE '[0-9]+' | head -1 || true)"
        H=no
        if grep -q '^php_admin_value\[disable_functions\].*exec' "$f" \
        && grep -q "^php_admin_value\[open_basedir\] = /home/$U" "$f"; then H=yes; fi
        printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$U" "$OV" "${C:-?}" "$H" "${M:-?}" "${N:-?}"
    done
done < <(php_pool_dirs)
