#!/usr/bin/env bash
# transfer-common.sh — SOURCED by transfer-pair.sh / transfer-serve.sh /
# transfer-pull.sh (Account Transfer, docs/account-transfer-spec.md).
#
# Who holds what (the owner's rule: no person ever handles a private key):
#   TARGET makes a one-time ed25519 pair per pairing in $KT_DIR/<id>/ (root
#   700, key 600). The private half never leaves that directory and is
#   shredded on revoke/expiry. Only PUBLIC data travels, in two typed codes:
#     KT1-TARGET-…  made on the target, pasted on the SOURCE
#     KT1-SOURCE-…  made on the source, pasted back on the TARGET
#   Each carries a checksum (typos/truncation refused) and both panels show
#   the same pairing words, derived from both public keys.
#   SOURCE puts the target's public key in $KT_USER's authorized_keys only
#   (never root's), as restrict + from=<target IP> + expiry-time + a forced
#   command into transfer-serve.sh <id> (read-only verbs).
# shellcheck disable=SC2034  # read by the scripts that source this
KT_DIR=/root/.karnak-transfer            # target side: one dir per pairing
KT_SRC=/var/lib/karnakpanel/transfer     # source side: one record per pairing
KT_CUT=/var/lib/karnakpanel/transfer-cut # source side: one record per account being cut over
KT_USER=karnaktransfer
KT_HOME=/home/$KT_USER
KT_SERVE=/usr/local/skypanel/scripts/transfer-serve.sh
KT_SUDOERS=/etc/sudoers.d/karnaktransfer # no dot: sudo skips dotted names
KT_LOG=/var/log/karnakpanel/transfer.log
KT_MAX_HOURS=72
KT_ID_RE='^[0-9a-f]{16}$'
KT_KEY_RE='^AAAAC3NzaC1lZDI1NTE5AAAAI[A-Za-z0-9+/]{43}$'   # ed25519 public key blob
KT_EXP_RE='^20[0-9]{10}$'                                 # YYYYmmddHHMM, UTC

kt_log() { install -d -m 750 "$(dirname "$KT_LOG")"; printf '%s %s\n' "$(date -u '+%F %T')" "$*" >> "$KT_LOG"; }
kt_ip_ok() {
  local IFS=. o; local -a p
  [[ "${1:-}" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] || return 1
  read -ra p <<<"$1"
  for o in "${p[@]}"; do (( 10#$o <= 255 )) || return 1; done
  [[ "$1" != 0.* && "$1" != 127.* && "$1" != 255.255.255.255 ]]
}
kt_now() { date -u +%Y%m%d%H%M; }
kt_exp_ok() {   # in the future and no further than KT_MAX_HOURS ahead
  [[ "${1:-}" =~ $KT_EXP_RE ]] || return 1
  [[ "$1" > "$(kt_now)" ]] && ! [[ "$1" > "$(date -u -d "+$KT_MAX_HOURS hours" +%Y%m%d%H%M)" ]]
}
kt_own_ips() { hostname -I 2>/dev/null | tr ' ' '\n' | grep -E '^[0-9.]+$' || true; }
kt_is_own_ip() { local ips; ips="$(kt_own_ips)"; grep -qxF -- "$1" <<<"$ips"; }
kt_ver() { cat /usr/local/skypanel/panel/VERSION 2>/dev/null || echo 0.0.0; }

# code = KT1-<KIND>-<base64 payload, no padding>.<8 hex of sha256(prefix+body)>
kt_encode() {
  local body; body="$(printf '%s' "$2" | base64 -w0 | tr -d '=')"
  printf 'KT1-%s-%s.%s\n' "$1" "$body" "$(printf 'KT1-%s-%s' "$1" "$body" | sha256sum | cut -c1-8)"
}
# kt_decode <WANT kind> <code> → payload on stdout, or a plain reason + rc 1
kt_decode() {
  local want="$1" code="${2//[[:space:]]/}" kind body sum pad
  if [[ ! "$code" =~ ^KT1-(TARGET|SOURCE)-([A-Za-z0-9+/]{20,1200})\.([0-9a-f]{8})$ ]]; then
    echo "that is not a transfer code (they start KT1-TARGET- or KT1-SOURCE-)"; return 1
  fi
  kind="${BASH_REMATCH[1]}"; body="${BASH_REMATCH[2]}"; sum="${BASH_REMATCH[3]}"
  if [[ "$kind" != "$want" ]]; then
    [[ "$kind" == SOURCE ]] && echo "this is a SOURCE code: paste it on the TARGET server (the new one), not here" \
                            || echo "this is a TARGET code: paste it on the SOURCE server (the old one), not here"
    return 1
  fi
  [[ "$(printf 'KT1-%s-%s' "$kind" "$body" | sha256sum | cut -c1-8)" == "$sum" ]] || { echo "the code is damaged or incomplete (checksum mismatch): copy it again in one piece"; return 1; }
  pad=$(( (4 - ${#body} % 4) % 4 )); body+="$(printf '%*s' "$pad" '' | tr ' ' '=')"
  printf '%s' "$body" | base64 -d 2>/dev/null || { echo "the code is damaged (not base64)"; return 1; }
}
# pairing words: same on both panels, from both public keys (order fixed)
KT_WORDS=(amber falcon river cedar copper meadow harbor quartz willow ember
  orbit canyon maple raven summit lotus glacier violet thunder pebble saffron
  beacon coral delta fern granite hazel indigo juniper kestrel lagoon marble
  nectar onyx prairie quill ridge sierra tundra umber vesper walnut yarrow
  zephyr aspen birch comet dune echo fable grove heron iris jade koala lumen
  mist nova olive pine)
kt_words() {
  local h; h="$(printf '%s|%s' "$1" "$2" | sha256sum | cut -c1-6)"
  printf '%s-%s-%02d\n' "${KT_WORDS[$(( 16#${h:0:2} % 64 ))]}" "${KT_WORDS[$(( 16#${h:2:2} % 64 ))]}" "$(( 16#${h:4:2} % 100 ))"
}
# read one key=value from a pairing meta file (never sourced)
kt_meta() { grep -m1 -oP "^$1=\K[^\n]*" -- "$2" 2>/dev/null || true; }
kt_meta_set() {
  local f="$2" t; t="$(mktemp "$f.XXXX")"
  { grep -v "^$1=" -- "$f" 2>/dev/null || true; printf '%s=%s\n' "$1" "$3"; } > "$t"
  chmod 600 "$t"; mv -f -- "$t" "$f"
}
# ssh to the source of target-side pairing $1: ONLY the pairing's own key,
# no agent, no config files, pinned host key, never prompts. One master
# connection per pairing so dumps + rsyncs share a handshake.
kt_ssh_opts() {
  local d="$KT_DIR/$1"
  printf '%s\n' -F /dev/null -i "$d/id_ed25519" -p "$(kt_meta src_port "$d/meta")" \
    -o IdentitiesOnly=yes -o IdentityAgent=none -o PasswordAuthentication=no -o KbdInteractiveAuthentication=no \
    -o UserKnownHostsFile="$d/known_hosts" -o GlobalKnownHostsFile=/dev/null -o StrictHostKeyChecking=yes \
    -o BatchMode=yes -o ConnectTimeout=20 -o ServerAliveInterval=15 -o ServerAliveCountMax=4 \
    -o ControlMaster=auto -o ControlPath="$d/cm" -o ControlPersist=120 -o LogLevel=ERROR
}
kt_ssh() {
  local id="$1"; shift
  local -a o; mapfile -t o < <(kt_ssh_opts "$id")
  ssh "${o[@]}" "$KT_USER@$(kt_meta src_ip "$KT_DIR/$id/meta")" "$*"
}
# rsync's -e wants one string; every part here is validated (paths fixed,
# port numeric) so plain quoting is enough
kt_rsync_e() {
  local d="$KT_DIR/$1"
  printf "ssh -F /dev/null -i %s -p %s -o IdentitiesOnly=yes -o IdentityAgent=none -o PasswordAuthentication=no -o UserKnownHostsFile=%s -o GlobalKnownHostsFile=/dev/null -o StrictHostKeyChecking=yes -o BatchMode=yes -o ConnectTimeout=20 -o ServerAliveInterval=15 -o ControlMaster=auto -o ControlPath=%s -o ControlPersist=120 -o LogLevel=ERROR" \
    "$d/id_ed25519" "$(kt_meta src_port "$d/meta")" "$d/known_hosts" "$d/cm"
}
