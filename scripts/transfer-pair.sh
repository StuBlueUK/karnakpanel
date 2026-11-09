#!/usr/bin/env bash
# transfer-pair.sh — Account Transfer pairing (docs/account-transfer-spec.md §1).
#   target-new [hours]        TARGET: new one-time key pair → prints CODE KT1-TARGET-…
#   source-allow <code>       SOURCE: accept a TARGET code → locked transfer
#                             login for that key; prints CODE KT1-SOURCE-… + WORDS
#   target-link <id> <code>   TARGET: accept the SOURCE code → pins its host
#                             key, handshake check; prints WORDS + VERSION
#   list                      both sides: one tab-separated line per pairing
#   revoke <id>               whichever side(s) hold <id>: key/line removed
#   prune                     revoke every expired pairing (cron)
#   source-drop <id>          SOURCE: remove one pairing (transfer-serve 'done')
# Panel-callable: every input is regex-checked HERE, not only by the caller.
# Both codes are PUBLIC data; the private key never leaves $KT_DIR/<id>/.
set -euo pipefail
HERE="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
. "$HERE/transfer-common.sh"
umask 077
CMD="${1:-}"

die() { echo "refused: $*"; exit 3; }

# ---------------------------------------------------------------- source side
src_lock() { install -d -m 700 -o root -g root "$KT_SRC"; exec 9>"$KT_SRC/.lock"; flock -w 30 9 || die "another pairing change is running"; }
src_user() {
  local ent uid
  if ent="$(getent passwd "$KT_USER")"; then
    uid="$(cut -d: -f3 <<<"$ent")"
    # ours = the system account this script made, nothing else of that name
    [[ "$uid" =~ ^[0-9]+$ ]] && (( uid < 1000 )) && [[ "$(cut -d: -f6 <<<"$ent")" == "$KT_HOME" ]] \
      || die "a user called $KT_USER already exists and is not the transfer login (uid $uid)"
  else
    useradd -r -M -d "$KT_HOME" -s /bin/sh -c "KarnakPanel account transfer (read-only)" "$KT_USER"
  fi
  # '*' not '!': a '!'-locked account is refused for keys when UsePAM is off.
  # No password exists either way; the shell only runs the forced command.
  usermod -p '*' -s /bin/sh "$KT_USER"
  gpasswd -r "$KT_USER" >/dev/null 2>&1 || true
  # root-owned: the transfer login can't edit its own keys
  install -d -m 755 -o root -g root "$KT_HOME" "$KT_HOME/.ssh"
  [[ -f "$KT_HOME/.ssh/authorized_keys" ]] || install -m 644 -o root -g root /dev/null "$KT_HOME/.ssh/authorized_keys"
}
src_sudoers() {
  local t
  t="$(mktemp /etc/sudoers.d/.karnaktransfer.XXXXXX)"
  # sudo regex args (1.9.10+): the forced command may pass ONE pairing id.
  # !use_pty keeps rsync/dump streams binary-clean; the client's command
  # reaches the script in SSH_ORIGINAL_COMMAND only.
  cat > "$t" <<EOF
# managed by $HERE/transfer-pair.sh — Account Transfer source access (read-only)
Defaults!$KT_SERVE env_keep += "SSH_ORIGINAL_COMMAND SSH_CLIENT", !use_pty, !log_output, !log_input
$KT_USER ALL=(root) NOPASSWD: $KT_SERVE ^[0-9a-f]{16}\$
EOF
  chmod 440 "$t"
  visudo -cqf "$t" || { rm -f "$t"; die "sudo rule failed its check (sudo 1.9.10+ needed)"; }
  if cmp -s "$t" "$KT_SUDOERS"; then rm -f "$t"; else mv -f "$t" "$KT_SUDOERS"; fi
}
src_sshd_ok() {   # $1 = target IP: would sshd let the transfer login in from there?
  local cfg w
  cfg="$(sshd -T -C "user=$KT_USER,addr=$1,host=$1,laddr=0.0.0.0,lport=22" 2>/dev/null)" || die "sshd -T failed"
  grep -qx 'pubkeyauthentication yes' <<<"$cfg" || die "sshd has key logins switched off"
  if grep -q '^allowusers ' <<<"$cfg"; then
    w="$(grep '^allowusers ' <<<"$cfg" | cut -d' ' -f2- | tr ' ' '\n')"
    grep -qxE "$KT_USER(@.*)?" <<<"$w" || die "sshd AllowUsers doesn't list $KT_USER: add it, or add 'AllowUsers $KT_USER@$1'"
  fi
  if grep -q '^allowgroups ' <<<"$cfg"; then
    grep -q "^allowgroups .*\b$KT_USER\b" <<<"$cfg" || die "sshd AllowGroups doesn't include group $KT_USER"
  fi
  grep -qE "^denyusers .*\b$KT_USER\b" <<<"$cfg" && die "sshd DenyUsers blocks $KT_USER"
  return 0
}
src_drop() {   # remove pairing $1 (caller holds the lock)
  rm -f -- "${KT_SRC:?}/$1"
  src_tidy
  kt_log "source: pairing $1 removed"
}
src_tidy() {   # key lines only for pairings that still have a record; none left = close
  local ak="$KT_HOME/.ssh/authorized_keys" t l id
  if [[ -f "$ak" ]]; then
    t="$(mktemp "$ak.XXXX")"
    while IFS= read -r l; do
      id="${l##* kt-}"
      [[ "$id" =~ $KT_ID_RE && -f "$KT_SRC/$id" ]] && printf '%s\n' "$l"
    done < "$ak" > "$t" || true
    chmod 644 "$t"; chown root:root "$t"; mv -f -- "$t" "$ak"
  fi
  [[ -s "$ak" ]] || src_close
}
src_close() {  # no pairing left: no login, no sudo rule, no sessions (caller holds the lock)
  rm -f -- "$KT_SUDOERS"
  local ent; ent="$(getent passwd "$KT_USER")" || return 0
  # only the system login this script made (same test as src_user)
  [[ "$(cut -d: -f6 <<<"$ent")" == "$KT_HOME" ]] && (( $(cut -d: -f3 <<<"$ent") < 1000 )) || return 0
  pkill -u "$KT_USER" 2>/dev/null || true; sleep 1; pkill -9 -u "$KT_USER" 2>/dev/null || true
  userdel "$KT_USER" 2>/dev/null || true
  rm -rf -- "${KT_HOME:?}"
}

# ---------------------------------------------------------------- target side
tgt_drop() {
  local id="$1" d="$KT_DIR/$1"
  [[ -d "$d" ]] || return 0
  if [[ "$(kt_meta state "$d/meta")" == linked ]]; then
    timeout 25 bash -c '. "$1"; kt_ssh "$2" done' _ "$HERE/transfer-common.sh" "$id" >/dev/null 2>&1 || true
    local -a o; mapfile -t o < <(kt_ssh_opts "$id")
    ssh "${o[@]}" -O exit "$KT_USER@$(kt_meta src_ip "$d/meta")" >/dev/null 2>&1 || true
  fi
  [[ -f "$d/id_ed25519" ]] && shred -u -- "$d/id_ed25519"
  rm -rf -- "${KT_DIR:?}/$id"
  kt_log "target: pairing $id removed"
}

case "$CMD" in
target-new)
  H="${2:-24}"; [[ "$H" =~ ^[0-9]{1,2}$ ]] && (( H >= 1 && H <= KT_MAX_HOURS )) || die "hours must be 1-$KT_MAX_HOURS"
  IP="$("$HERE/server-ip.sh" 2>/dev/null || true)"; kt_ip_ok "$IP" || die "can't tell this server's IPv4 address"
  ID="$(openssl rand -hex 8)"; D="$KT_DIR/$ID"; EXP="$(date -u -d "+$H hours" +%Y%m%d%H%M)"
  install -d -m 700 -o root -g root "$KT_DIR" "$D"
  ssh-keygen -q -t ed25519 -N '' -C "kt-$ID" -f "$D/id_ed25519"
  KEY="$(cut -d' ' -f2 "$D/id_ed25519.pub")"; [[ "$KEY" =~ $KT_KEY_RE ]] || { tgt_drop "$ID"; die "key generation failed"; }
  printf 'state=waiting\ntarget_ip=%s\nexp=%s\ncreated=%s\n' "$IP" "$EXP" "$(kt_now)" > "$D/meta"; chmod 600 "$D/meta"
  kt_log "target: pairing $ID created (expires $EXP UTC)"
  echo "ID $ID"; echo "EXPIRES $EXP"
  echo "CODE $(kt_encode TARGET "1 $ID $IP $EXP $KEY")"
  ;;

source-allow)
  P="$(kt_decode TARGET "${2:-}")" || die "$P"
  read -r V ID TIP EXP KEY EXTRA <<<"$P" || true
  [[ "$V" == 1 && -z "${EXTRA:-}" ]] || die "code from a newer panel version: update this panel first"
  [[ "$ID" =~ $KT_ID_RE && "$KEY" =~ $KT_KEY_RE ]] || die "the code is damaged"
  kt_ip_ok "$TIP" || die "the code carries no usable target IP"
  kt_is_own_ip "$TIP" && die "this code was made on THIS server: paste it on the OLD server (where the accounts are now)"
  [[ -d "$KT_DIR/$ID" ]] && die "this code was made on THIS server: paste it on the OLD server (where the accounts are now)"
  kt_exp_ok "$EXP" || die "the code has expired: make a new one on the target"
  [[ -f /etc/ssh/ssh_host_ed25519_key.pub ]] || die "this server has no ed25519 SSH host key"
  # everything that can refuse runs before anything is written
  HK="$(cut -d' ' -f2 /etc/ssh/ssh_host_ed25519_key.pub)"; [[ "$HK" =~ $KT_KEY_RE ]] || die "odd host key"
  PORT="$(sshd -T 2>/dev/null | awk '$1=="port" && !p{print $2; p=1}')"; [[ "$PORT" =~ ^[0-9]{1,5}$ ]] || PORT=22
  SIP="$("$HERE/server-ip.sh" 2>/dev/null || true)"; kt_ip_ok "$SIP" || die "can't tell this server's IPv4 address"
  W="$(kt_words "$KEY" "$HK")"
  src_lock
  [[ -e "$KT_SRC/$ID" ]] && die "this code was already used: make a new one on the target"
  src_sshd_ok "$TIP"
  src_user
  src_sudoers
  # record first: a key line is only ever kept while its record exists
  printf 'target_ip=%s\nexp=%s\nwords=%s\ncreated=%s\n' "$TIP" "$EXP" "$W" "$(kt_now)" > "$KT_SRC/$ID"; chmod 600 "$KT_SRC/$ID"
  AK="$KT_HOME/.ssh/authorized_keys"; T="$(mktemp "$AK.XXXX")"
  { grep -v " kt-$ID\$" -- "$AK" || true
    # expiry-time with Z = UTC; sshd refuses the key after it on its own
    printf 'restrict,from="%s",expiry-time="%sZ",command="sudo -n %s %s" ssh-ed25519 %s kt-%s\n' "$TIP" "$EXP" "$KT_SERVE" "$ID" "$KEY" "$ID"
  } > "$T"
  chmod 644 "$T"; chown root:root "$T"; mv -f -- "$T" "$AK"
  kt_log "source: pairing $ID allowed for $TIP until $EXP UTC ($W)"
  echo "ID $ID"; echo "WORDS $W"; echo "TARGET $TIP"; echo "EXPIRES $EXP"
  echo "CODE $(kt_encode SOURCE "1 $ID $SIP $PORT $HK $(kt_ver)")"
  ;;

target-link)
  ID="${2:-}"; [[ "$ID" =~ $KT_ID_RE ]] || die "bad pairing id"
  D="$KT_DIR/$ID"; [[ -f "$D/meta" && -f "$D/id_ed25519" ]] || die "no such pairing on this server (expired or revoked?)"
  kt_exp_ok "$(kt_meta exp "$D/meta")" || die "this pairing has expired: start a new one"
  P="$(kt_decode SOURCE "${3:-}")" || die "$P"
  read -r V CID SIP PORT HK SVER EXTRA <<<"$P" || true
  [[ "$V" == 1 && -z "${EXTRA:-}" ]] || die "code from a newer panel version: update this panel first"
  [[ "$CID" == "$ID" ]] || die "this SOURCE code belongs to a different pairing: paste the code made from THIS pairing's target code"
  kt_ip_ok "$SIP" && [[ "$PORT" =~ ^[0-9]{1,5}$ ]] && (( PORT >= 1 && PORT <= 65535 )) && [[ "$HK" =~ $KT_KEY_RE && "$SVER" =~ ^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,4}$ ]] || die "the code is damaged"
  kt_is_own_ip "$SIP" && die "that code points at this same server"
  if [[ "$PORT" == 22 ]]; then HOSTPAT="$SIP"; else HOSTPAT="[$SIP]:$PORT"; fi
  printf '%s ssh-ed25519 %s\n' "$HOSTPAT" "$HK" > "$D/known_hosts"; chmod 600 "$D/known_hosts"
  kt_meta_set src_ip "$D/meta" "$SIP"; kt_meta_set src_port "$D/meta" "$PORT"; kt_meta_set src_ver "$D/meta" "$SVER"
  OUT="$(timeout 40 bash -c '. "$1"; kt_ssh "$2" version' _ "$HERE/transfer-common.sh" "$ID" 2>&1)" || true
  if [[ ! "$OUT" =~ ^ok\ ([0-9.]+)\ ([0-9.]+)\ ([0-9a-f]{16})$ ]]; then
    case "$OUT" in
      *"Host key verification failed"*|*"REMOTE HOST IDENTIFICATION"*) die "the source's SSH host key doesn't match the code: not connecting (wrong server, or someone in between)";;
      *"Permission denied"*) die "the source refused the key: was the TARGET code pasted on that server, and does it see this server as $(kt_meta target_ip "$D/meta")?";;
      *"timed out"*|*"No route"*|*"refused"*) die "can't reach $SIP port $PORT over SSH: check its firewall allows this server";;
      *) die "handshake failed: $(head -c 200 <<<"$OUT" | tr '\n' ' ')";;
    esac
  fi
  [[ "${BASH_REMATCH[3]}" == "$ID" ]] || die "handshake answered for another pairing"
  SEEN="${BASH_REMATCH[2]}"
  kt_meta_set state "$D/meta" linked
  W="$(kt_words "$(cut -d' ' -f2 "$D/id_ed25519.pub")" "$HK")"; kt_meta_set words "$D/meta" "$W"
  kt_log "target: pairing $ID linked to $SIP:$PORT ($W)"
  echo "ID $ID"; echo "WORDS $W"; echo "SOURCE $SIP:$PORT"; echo "SEEN_AS $SEEN"
  echo "VERSION $SVER $(kt_ver)"
  [[ "$SVER" == "$(kt_ver)" ]] || echo "WARN panel versions differ (source $SVER, here $(kt_ver)): update both to the same version before moving accounts"
  ;;

list)
  for d in "$KT_DIR"/*/; do
    [[ -f "$d/meta" ]] || continue
    id="$(basename "$d")"; [[ "$id" =~ $KT_ID_RE ]] || continue
    st="$(kt_meta state "$d/meta")"; kt_exp_ok "$(kt_meta exp "$d/meta")" || st=expired
    printf 'target\t%s\t%s\t%s\t%s\t%s\t%s\n' "$id" "$st" "$(kt_meta src_ip "$d/meta")" "$(kt_meta exp "$d/meta")" "$(kt_meta words "$d/meta")" "$(kt_meta created "$d/meta")"
  done
  for f in "$KT_SRC"/*; do
    [[ -f "$f" ]] || continue
    id="$(basename "$f")"; [[ "$id" =~ $KT_ID_RE ]] || continue
    st=active; kt_exp_ok "$(kt_meta exp "$f")" || st=expired
    printf 'source\t%s\t%s\t%s\t%s\t%s\t%s\n' "$id" "$st" "$(kt_meta target_ip "$f")" "$(kt_meta exp "$f")" "$(kt_meta words "$f")" "$(kt_meta created "$f")"
  done
  ;;

revoke|source-drop)
  ID="${2:-}"; [[ "$ID" =~ $KT_ID_RE ]] || die "bad pairing id"
  [[ -e "$KT_DIR/$ID" || -e "$KT_SRC/$ID" ]] || die "no such pairing"
  [[ "$CMD" == revoke ]] && tgt_drop "$ID"
  if [[ -e "$KT_SRC/$ID" ]]; then src_lock; src_drop "$ID"; fi
  echo "revoked $ID"
  ;;

prune)
  for d in "$KT_DIR"/*/; do
    [[ -f "$d/meta" ]] || continue; id="$(basename "$d")"; [[ "$id" =~ $KT_ID_RE ]] || continue
    kt_exp_ok "$(kt_meta exp "$d/meta")" || { tgt_drop "$id"; echo "pruned target $id"; }
  done
  for f in "$KT_SRC"/*; do
    [[ -f "$f" ]] || continue; id="$(basename "$f")"; [[ "$id" =~ $KT_ID_RE ]] || continue
    kt_exp_ok "$(kt_meta exp "$f")" || { src_lock; src_drop "$id"; echo "pruned source $id"; }
  done
  # key lines without a pairing record (a source-allow that died half-way)
  # are dropped; with none left the login + sudo rule go too
  if [[ -e "$KT_SUDOERS" ]] || getent passwd "$KT_USER" >/dev/null; then
    src_lock; src_tidy
    getent passwd "$KT_USER" >/dev/null || echo "closed the transfer login (no pairings left)"
  fi
  ;;

*) echo "usage: transfer-pair.sh target-new [hours] | source-allow <code> | target-link <id> <code> | list | revoke <id> | prune"; exit 1;;
esac
