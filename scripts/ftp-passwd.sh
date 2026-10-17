#!/usr/bin/env bash
# ftp-passwd.sh <login> <newpass> — rotate one FTP suffix account's password.
# Underscore REQUIRED: main-account passwords sync via password-sync.sh instead.
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/secret-args.sh"; secret_argv "$@"; set -- "${SECRET_ARGV[@]}"  # @stdin secrets
L="${1:?}"; P="${2:?}"
[[ "$L" =~ ^[a-z][a-z0-9_]{2,31}_[a-z0-9_]{1,32}$ ]] || { echo "bad ftp login (want owner_suffix)"; exit 1; }
[[ "${#P}" -ge 8 ]] || { echo "password too short"; exit 1; }
id "$L" >/dev/null 2>&1 || { echo "no such ftp user"; exit 1; }
# pre-hashed (-e): bypasses provider-image pam_pwquality dictionary checks
# (panel enforces length + HIBP instead).
H="$(printf '%s' "$P" | openssl passwd -6 -stdin)" || { echo "hash failed"; exit 1; }
echo "$L:$H" | chpasswd -e
echo "ftp password set for $L"
