#!/usr/bin/env bash
# password-sync.sh <username> <newpass> — re-sync the SYSTEM password after a
# panel password change (same credential by design; piped to chpasswd, never argv).
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/secret-args.sh"; secret_argv "$@"; set -- "${SECRET_ARGV[@]}"  # @stdin secrets
U="${1:?}"; P="${2:?}"
. "$(dirname "$(readlink -f "$0")")/account-check.sh"
valid_account_name "$U" || exit 1
[[ "${#P}" -ge 8 ]] || { echo "password too short"; exit 1; }
id "$U" >/dev/null 2>&1 || { echo "no such user"; exit 1; }
# hosting accounts only: never root, a distro login user or a sudo-group user
panel_account "$U" || { echo "refused: $ACCOUNT_CHECK_WHY"; exit 3; }
# pre-hashed (-e): bypasses provider-image pam_pwquality dictionary checks
# (panel enforces length + HIBP instead).
H="$(printf '%s' "$P" | openssl passwd -6 -stdin)" || { echo "hash failed"; exit 1; }
echo "$U:$H" | chpasswd -e
echo "system password synced for $U"
