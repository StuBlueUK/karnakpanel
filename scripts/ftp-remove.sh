#!/usr/bin/env bash
# ftp-remove.sh <login> — drop one FTP suffix account (system user + allow-list).
# Underscore REQUIRED: main accounts are never removed here (account delete owns those).
set -euo pipefail
L="${1:?}"
[[ "$L" =~ ^[a-z][a-z0-9_]{2,31}_[a-z0-9_]{1,32}$ ]] || { echo "bad ftp login (want owner_suffix)"; exit 1; }
userdel "$L" 2>/dev/null || true
sed -i "/^$L\$/d" /etc/vsftpd.userlist 2>/dev/null || true
echo "ftp $L removed"
