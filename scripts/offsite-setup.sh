#!/usr/bin/env bash
# offsite-setup.sh provider=v k=v ... — configure the offsite backup remote
# (admin, Backups page). Providers: s3 (any S3-compatible: AWS, Backblaze B2,
# Wasabi, Hetzner, R2, MinIO) | sftp | local (USB mount etc.).
# Layout in rclone.conf: [offsite] raw remote + [offsite-crypt] crypt wrapper
# (filename encryption on) so the provider only ever sees ciphertext.
# The crypt password is generated here, stored obscured in the root-only
# config, and printed ONCE — without it offsite data is unreadable, same
# rule as the backup encryption key. Connection is verified (rclone lsd)
# before anything is saved. Non-secret facts (provider/bucket) go to the
# settings table for the panel UI; secrets never touch the DB.
#
# Secrets (S3 key/secret, SFTP password) arrive as "@stdin" placeholders and
# are read from stdin — never argv (sudo auth.log, panel.log, /proc/cmdline).
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/secret-args.sh"
secret_argv "$@"
set -- "${SECRET_ARGV[@]}"
RCLONE="$(command -v rclone || echo /usr/bin/rclone)"
[[ -x "$RCLONE" ]] || { echo "rclone missing (apt install rclone)"; exit 1; }
CONF_DIR="/root/.config/rclone"; CONF="$CONF_DIR/rclone.conf"
declare -A A
SECRETS=()
SECRET_MODE=0
for kv in "$@"; do
  # k=v options come first, then a literal '--' terminator, then the secret
  # values (which must never be parsed as options even if they look like one).
  if (( !SECRET_MODE )) && [[ "$kv" == "--" ]]; then
    SECRET_MODE=1; continue
  fi
  if (( !SECRET_MODE )) && [[ "$kv" =~ ^[a-z_]+= ]]; then
    A["${kv%%=*}"]="${kv#*=}"
  else
    SECRET_MODE=1
    SECRETS+=("$kv")
  fi
done
# SQL string escaping for the settings INSERT (backslashes first, then quotes)
sqlq() { local s="${1//\\/\\\\}"; printf '%s' "${s//\'/\'\'}"; }
PROV="${A[provider]:-}"
mkdir -p "$CONF_DIR"; chmod 700 "$CONF_DIR"
touch "$CONF"; chmod 600 "$CONF"
# drop any previous offsite config (fresh stanza written below)
python3 - "$CONF" <<'PYEOF' 2>/dev/null || true
import re, sys
p = sys.argv[1]
src = open(p).read() if __import__('os').path.exists(p) else ''
src = re.sub(r'(?ms)^\[offsite\].*?(?=^\[|\Z)', '', src)
src = re.sub(r'(?ms)^\[offsite-crypt\].*?(?=^\[|\Z)', '', src)
open(p, 'w').write(src.strip() + '\n')
PYEOF
case "$PROV" in
  s3)
    [[ ${#SECRETS[@]} -eq 2 ]] || { echo "missing key/secret"; exit 1; }
    KEY="${SECRETS[0]:-}"; SECRET="${SECRETS[1]:-}"
    # written verbatim into root's rclone.conf: charset-gate against INI injection
    [[ "$KEY" =~ ^[A-Za-z0-9+/=_.,-]{4,512}$ ]] || { echo "bad key"; exit 1; }
    [[ "$SECRET" =~ ^[A-Za-z0-9+/=_.,-]{4,512}$ ]] || { echo "bad secret"; exit 1; }
    for k in preset bucket; do [[ -n "${A[$k]:-}" ]] || { echo "missing $k"; exit 1; }; done
    [[ "${A[preset]}" =~ ^(AWS|Backblaze|Wasabi|Hetzner|Cloudflare|Other)$ ]] || { echo "bad preset"; exit 1; }
    [[ "${A[bucket]}" =~ ^[a-z0-9.-]{3,63}$ ]] || { echo "bad bucket"; exit 1; }
    [[ -z "${A[region]:-}" || "${A[region]}" =~ ^[a-zA-Z0-9-]{0,32}$ ]] || { echo "bad region"; exit 1; }
    [[ -z "${A[endpoint]:-}" || "${A[endpoint]}" =~ ^https?://[a-zA-Z0-9.-]+(:[0-9]{1,5})?/?$ ]] || { echo "bad endpoint"; exit 1; }
    {
      echo "[offsite]"; echo "type = s3"; echo "provider = ${A[preset]}"
      [[ -n "${A[endpoint]:-}" ]] && echo "endpoint = ${A[endpoint]}"
      [[ -n "${A[region]:-}" ]] && echo "region = ${A[region]}"
      echo "access_key_id = $KEY"
      echo "secret_access_key = $SECRET"
    } >> "$CONF"
    REMOTE_PATH="${A[bucket]}"
    ;;
  sftp)
    [[ ${#SECRETS[@]} -eq 1 ]] || { echo "missing password"; exit 1; }
    PASS="${SECRETS[0]:-}"
    [[ -n "$PASS" ]] || { echo "missing password"; exit 1; }
    for k in host user; do [[ -n "${A[$k]:-}" ]] || { echo "missing $k"; exit 1; }; done
    [[ "${A[host]}" =~ ^[a-zA-Z0-9.-]+$ ]] || { echo "bad host"; exit 1; }
    PORT="${A[port]:-22}"; [[ "$PORT" =~ ^[0-9]{1,5}$ ]] || { echo "bad port"; exit 1; }
    [[ "${A[user]}" =~ ^[a-zA-Z0-9_.-]{1,64}$ ]] || { echo "bad user"; exit 1; }
    REMOTE_PATH="${A[path]:-/backups}"
    [[ "$REMOTE_PATH" =~ ^/[a-zA-Z0-9_./-]{0,120}$ && "$REMOTE_PATH" != *..* ]] || { echo "bad path"; exit 1; }
    # obscure via stdin: the password never reaches rclone's argv either
    OBSC="$(printf '%s' "$PASS" | "$RCLONE" obscure -)"
    {
      echo "[offsite]"; echo "type = sftp"; echo "host = ${A[host]}"; echo "port = $PORT"
      echo "user = ${A[user]}"; echo "pass = $OBSC"
    } >> "$CONF"
    ;;
  local)
    [[ "${A[path]:-}" =~ ^/[a-zA-Z0-9_./-]{1,120}$ && "${A[path]}" != *..* ]] || { echo "bad path (want absolute)"; exit 1; }
    mkdir -p "${A[path]}" || { echo "cannot create ${A[path]}"; exit 1; }
    { echo "[offsite]"; echo "type = local"; } >> "$CONF"
    REMOTE_PATH="${A[path]}"
    ;;
  *) echo "provider must be s3|sftp|local"; exit 1 ;;
esac
# crypt wrapper (everything the sync sends goes through here)
CRYPT_PASS="$(openssl rand -hex 32)"
CRYPT_OBSC="$(printf '%s' "$CRYPT_PASS" | "$RCLONE" obscure -)"
{ echo "[offsite-crypt]"; echo "type = crypt"; echo "remote = offsite:$REMOTE_PATH"
  echo "filename_encryption = standard"; echo "password = $CRYPT_OBSC"; } >> "$CONF"
chmod 600 "$CONF"
# verify BEFORE saving: the target itself must answer (not the account root —
# bucket-scoped keys, e.g. Backblaze, can't list buckets, only their bucket).
# Missing bucket/path is created on first setup (fresh B2 bucket, USB mount).
if ! "$RCLONE" --config "$CONF" lsd "offsite:$REMOTE_PATH" >/dev/null 2>&1; then
  "$RCLONE" --config "$CONF" mkdir "offsite:$REMOTE_PATH" >/dev/null 2>&1 || true
  "$RCLONE" --config "$CONF" lsd "offsite:$REMOTE_PATH" >/dev/null 2>&1 || {
    echo "CONNECTION FAILED — check credentials/endpoint (nothing saved to panel settings)"
    exit 1
  }
fi
# publish non-secret facts for the panel UI
if [[ -f /root/.skypanel-dbpass ]]; then
  P="$(cat /root/.skypanel-dbpass)"
  MYSQL_PWD="$P" mysql -uskypanel skypanel -e "INSERT INTO settings(name,value) VALUES('offsite_provider','$(sqlq "$PROV")'),('offsite_target','$(sqlq "$REMOTE_PATH")') ON DUPLICATE KEY UPDATE value=VALUES(value);" 2>/dev/null || true
fi
echo "offsite configured ($PROV -> $REMOTE_PATH), connection verified"
echo "CRYPT_PASSWORD=$CRYPT_PASS"
echo "WRITE IT DOWN NOW (shown once, never again) — without it offsite backups can never be read back"
