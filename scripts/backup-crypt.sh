#!/usr/bin/env bash
# backup-crypt.sh — transparent backup encryption for panel backups.
# Model: per-user random keys (users.backup_pass), shown ONCE at enable time.
# Threat model: protects backups AT REST (stolen disk, leaked downloads,
# offsite copies) — NOT against root/DB compromise (automation needs the key
# on the box). Filenames never change; encryption is detected by magic bytes
# (openssl "Salted__" vs gzip), so old plaintext backups restore forever.
# Commands (never print keys to logs):
#   user-key <user>            — print key or empty
#   encrypt-file <user> <file> — openssl-enc in place if key set AND plaintext
#   encrypt-existing <user>    — encrypt all owned plaintext backups (detached)
#   disable <user>             — decrypt everything back, clear key (detached)
#   stage <file>               — print usable path: decrypted tmp copy if
#                                encrypted (caller traps rm), else the original
set -euo pipefail
CMD="${1:?}"; shift || true
PASS="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
[[ -n "$PASS" ]] || { echo "no panel db pass"; exit 1; }
BDIR="/backup/skypanel"
user_key() {
  # mysql -N prints literal NULL for NULL — a passphrase is never that string
  MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT backup_pass FROM users WHERE username='$1' LIMIT 1;" 2>/dev/null | grep -v '^NULL$' || true
}
is_enc() { [[ "$(head -c 8 "$1" 2>/dev/null | tr -d '\000')" == Salted__ ]]; }
# owner rule shared by writers and readers: account tarballs embed the owner
# (<user>-<ts>.tar.gz, usernames can't contain '-'); global db dumps use the
# first admin holding a key.
owner_of() {
  local F; F="$(basename "$1")"
  if [[ "$F" == db-*.sql.gz ]]; then
    MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT username FROM users WHERE role='admin' AND backup_pass IS NOT NULL AND backup_pass != '' ORDER BY id LIMIT 1;" 2>/dev/null || true
  else
    echo "${F%%-*}"
  fi
}
case "$CMD" in
  user-key) user_key "${1:?}" ;;
  admin-user)
    MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT username FROM users WHERE role='admin' AND backup_pass IS NOT NULL AND backup_pass != '' ORDER BY id LIMIT 1;" 2>/dev/null || true
    ;;
  encrypt-file)
    U="${1:?}"; F="${2:?}"
    [[ -f "$F" ]] || { echo "no such file"; exit 1; }
    K="$(user_key "$U")"
    [[ -n "$K" ]] || exit 0
    is_enc "$F" && exit 0
    export K
    openssl enc -aes-256-cbc -pbkdf2 -pass env:K -in "$F" -out "$F.crypt" 2>/dev/null || { echo "encrypt failed"; exit 1; }
    mv "$F.crypt" "$F"
    ;;
  encrypt-existing)
    # detached: GBs of re-encryption outlive the panel sudo window
    U="${1:?}"
    ( for f in "$BDIR/$U"-*.tar.gz; do [[ -f "$f" ]] || continue; "$0" encrypt-file "$U" "$f" || true; done
      echo "$(date -Is) backup-crypt: encrypt-existing done for $U" >> /var/log/karnakpanel/panel.log ) >>/var/log/karnakpanel/panel.log 2>&1 &
    echo "encryption of existing backups started in background"
    ;;
  disable)
    U="${1:?}"
    ( for pass in 1 2 3; do
      LEFT=0
      shopt -s nullglob
      FILES=("$BDIR/$U"-*.tar.gz)
      if [[ "$U" == "$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT username FROM users WHERE role='admin' ORDER BY id LIMIT 1;" 2>/dev/null)" ]]; then
        FILES+=("$BDIR"/db-*.sql.gz)
      fi
      for f in "${FILES[@]}"; do
        [[ -f "$f" ]] || continue
        is_enc "$f" || continue
        K="$(user_key "$U")"; [[ -n "$K" ]] || { echo "key gone mid-disable"; exit 1; }
        export K
        if openssl enc -d -aes-256-cbc -pbkdf2 -pass env:K -in "$f" -out "$f.plain" 2>/dev/null; then
          mv "$f.plain" "$f"; else rm -f "$f.plain"; LEFT=$((LEFT+1))
        fi
      done
      shopt -u nullglob
      [[ "$LEFT" == 0 ]] || continue
      MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "UPDATE users SET backup_pass=NULL WHERE username='$U';" 2>/dev/null || true
      echo "$(date -Is) backup-crypt: encryption disabled for $U" >> /var/log/karnakpanel/panel.log
      exit 0
    done
    echo "disable incomplete: files created during the run stayed encrypted (re-run disable)" >> /var/log/karnakpanel/panel.log ) >>/var/log/karnakpanel/panel.log 2>&1 &
    echo "decryption started in background — key stays until files are clean"
    ;;
  stage)
    F="${1:?}"
    [[ -f "$F" ]] || { echo "no such file"; exit 1; }
    is_enc "$F" || { echo "$F"; exit 0; }
    U="$(owner_of "$F")"
    K="$(user_key "$U")"
    [[ -n "$K" ]] || { echo "encrypted but no key for owner $U (key shown once at enable)"; exit 1; }
    export K
    T="$(mktemp)"
    openssl enc -d -aes-256-cbc -pbkdf2 -pass env:K -in "$F" -out "$T" 2>/dev/null || { rm -f "$T"; echo "decrypt failed (wrong key?)"; exit 1; }
    echo "$T"
    ;;
esac
