#!/usr/bin/env bash
# fpm-status.sh — TSV per user pool for Admin > PHP pools (panel runs as
# nobody and can't read pool files; this runs privileged via ServiceManager).
# Columns: user, php-version, max_children, hardened(yes/no), memory_limit, nice,
# then master (own|shared), state, RAM used MB, RAM cap MB, CPU cap % (fpm-account.sh)
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/php-versions.sh"
. "$(dirname "$(readlink -f "$0")")/fpm-account.sh"
declare -A OWN=()
while IFS=$'\t' read -r u st mem max cpu; do
    [[ "$st" == shared || "$st" == off ]] || OWN["$u"]="own	$st	$mem	$max	$cpu"
done < <(fpm_status)
while IFS= read -r PD; do
    OV="${PD#/etc/php/}"; OV="${OV%%/*}"
    # one awk over every pool file of the version (was ~15 processes per
    # account: 4 s for the page at 200 accounts)
    compgen -G "$PD/skypanel-*.conf" >/dev/null || continue
    while IFS=$'\t' read -r U C M N H; do
        printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$U" "$OV" "$C" "$H" "$M" "$N" "${OWN[$U]:-shared	-	-	-	-}"
    done < <(awk -v OFS='\t' '
        function out() { if (u != "") print u, (c == "" ? "?" : c), (m == "" ? "?" : m), (n == "" ? "?" : n), (de && ob ? "yes" : "no") }
        FNR == 1 { out(); u = FILENAME; sub(/.*\/skypanel-/, "", u); sub(/\.conf$/, "", u); c = m = n = ""; de = ob = 0 }
        /^pm\.max_children/ && c == "" { if (match($0, /[0-9]+/)) c = substr($0, RSTART, RLENGTH) }
        /^php_admin_value\[memory_limit\]/ && m == "" { v = $0; sub(/[^=]*= */, "", v); gsub(/ /, "", v); m = v }
        /^process\.priority/ && n == "" { if (match($0, /[0-9]+/)) n = substr($0, RSTART, RLENGTH) }
        /^php_admin_value\[disable_functions\].*exec/ { de = 1 }
        index($0, "php_admin_value[open_basedir] = /home/" u) == 1 { ob = 1 }
        END { out() }' "$PD"/skypanel-*.conf)
done < <(php_pool_dirs)
