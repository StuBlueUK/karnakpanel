#!/usr/bin/env bash
# transfer-serve.sh <pairing id> — Account Transfer, SOURCE side. Never run
# by the panel (it is deliberately not named in app/, so panel-sudoers.sh
# never allowlists it). Reached only as the forced command of a pairing key
# in karnaktransfer's authorized_keys:
#   restrict,from="<target>",expiry-time=…,command="sudo -n …/transfer-serve.sh <id>"
# The target's request arrives in SSH_ORIGINAL_COMMAND (kept by the
# pairing's own sudo rule). READ-ONLY apart from suspend/unsuspend/done.
# Verbs (each argument regex-checked, every user/domain/db must be a moved-
# able panel account's own):
#   version · list · export <user> · dbauth <user> · dbdump <user> <db>
#   shadow <user> · dkim <user> <domain>
#   rsync-home <user> <rsync --server --sender …>   (read-only, rrsync)
#   rsync-mail <user> <domain> <rsync --server --sender …>
#   suspend <user> · unsuspend <user> · done
# Cutover (the only verbs that change anything beyond suspend; each acts on
# ONE movable account, and "the new server" is always this link's own peer,
# ${SSH_CLIENT%% *}: never an address the request names):
#   cut-hold <user>   holding page on its sites, its mail held in the queue,
#                     account suspended. Remembers what it changed ($KT_CUT/<user>).
#   cut-undo <user>   puts back exactly that (only before cut-mail)
#   cut-mail <user>   held + new mail passed on to the new server by IP
#   cut-dns <user>    this server's zones for the account answer with the new IP
# Logged: verb + arguments only, never what is sent back.
set -euo pipefail
HERE="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
. "$HERE/transfer-common.sh"
. "$HERE/panel-php.sh"
ID="${1:-}"
REQ="${SSH_ORIGINAL_COMMAND:-}"
refuse() { kt_log "serve $ID: REFUSED ($1): ${REQ:0:160}"; echo "refused: $1" >&2; exit 2; }
[[ "$ID" =~ $KT_ID_RE ]] || refuse "bad pairing id"
# the pairing must still be live (revoked/expired = nothing served, even if
# a session outlived the key line)
[[ -f "$KT_SRC/$ID" ]] && grep -qE " kt-$ID\$" "$KT_HOME/.ssh/authorized_keys" 2>/dev/null || refuse "pairing revoked"
kt_exp_ok "$(kt_meta exp "$KT_SRC/$ID")" || refuse "pairing expired"
[[ "$REQ" =~ ^[A-Za-z0-9\ ._/=,:+-]{1,600}$ ]] || refuse "bad characters"
read -ra A <<<"$REQ"
V="${A[0]:-}"
kt_log "serve $ID: $V ${A[*]:1:2}"

UNAME_RE='^[a-z][a-z0-9_]{2,31}$'
DOM_RE='^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$'
DB_RE='^[a-z0-9][a-z0-9_]{0,63}$'
movable() {   # panel account (role user) with a real hosting system user
  [[ "${1:-}" =~ $UNAME_RE ]] || refuse "bad user"
  KP_U="$1" PANEL_PHP 'exit(Transfer::movable((string)getenv("KP_U")) ? 0 : 1);' || refuse "not a movable account: $1"
}
owns_domain() {
  [[ "${2:-}" =~ $DOM_RE ]] || refuse "bad domain"
  KP_U="$1" KP_D="$2" PANEL_PHP 'exit(Transfer::ownsDomain((string)getenv("KP_U"), (string)getenv("KP_D")) ? 0 : 1);' || refuse "domain not owned by $1"
}
rsync_ok() {  # the client half of an rsync pull: --server --sender, nothing else
  [[ "${A[$1]:-}" == --server && "${A[$(($1+1))]:-}" == --sender ]] || refuse "not an rsync pull"
  local t f; for t in "${A[@]:$1}"; do
    [[ "$t" =~ ^[-A-Za-z0-9.,_=/]+$ ]] || refuse "odd rsync argument"
    # the sender (root here) must never FOLLOW links: a link the account
    # planted in its home (to /root, another home…) would be read as a file.
    # Short bundles: only the flags before "e" — after it comes the
    # capability string (".iLsfxCIvu"), whose L is not --copy-links.
    case "$t" in
      --copy-links|--copy-dirlinks|--copy-unsafe-links|--keep-dirlinks|--copy-links=*|--copy-unsafe-links=*) refuse "rsync link-following refused" ;;
      --*) ;;
      -*) f="${t#-}"; f="${f%%e*}"; [[ "$f" == *[LkK]* ]] && refuse "rsync link-following refused" ;;
    esac
  done
}
n() { (( ${#A[@]} == $1 )) || refuse "wrong argument count for $V"; }
MOVED=/etc/exim4/virtual/moved
moved_del() { [[ -f "$MOVED" ]] && sed -i "/^${1//./\\.}: /d" "$MOVED" || true; }
moved_set() { moved_del "$1"; echo "$1: $2" >> "$MOVED"; }

case "$V" in
  version) n 1; echo "ok $(kt_ver) ${SSH_CLIENT%% *} $ID" ;;
  list)    n 1; PANEL_PHP 'echo json_encode(Transfer::accounts(), JSON_UNESCAPED_SLASHES), "\n";' ;;
  export)  n 2; movable "${A[1]}"
           KP_U="${A[1]}" PANEL_PHP 'echo json_encode(Transfer::export((string)getenv("KP_U")), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR), "\n";' ;;
  dbauth)  n 2; movable "${A[1]}"
           # one line per db login: <user> TAB <plugin> TAB <auth string>.
           # Structured on purpose: the target never runs SQL text from here.
           KP_U="${A[1]}" PANEL_PHP 'foreach (Transfer::dbUsers((string)getenv("KP_U")) as $u) echo $u, "\n";' | while IFS= read -r U; do
             [[ "$U" =~ ^[a-z0-9_]{1,64}$ ]] || continue
             mysql -uroot -N -B -e "SELECT User, JSON_VALUE(Priv,'\$.plugin'), JSON_VALUE(Priv,'\$.authentication_string') FROM mysql.global_priv WHERE Host='localhost' AND User='$U';"
           done ;;
  dbdump)  n 3; movable "${A[1]}"; DB="${A[2]}"
           [[ "$DB" =~ $DB_RE ]] || refuse "bad db"
           case "$DB" in skypanel|mysql|roundcube|information_schema|performance_schema|sys|phpmyadmin) refuse "system database";; esac
           KP_U="${A[1]}" KP_B="$DB" PANEL_PHP 'exit(Transfer::ownsDb((string)getenv("KP_U"), (string)getenv("KP_B")) ? 0 : 1);' || refuse "db not owned by ${A[1]}"
           exec mysqldump -uroot --single-transaction --quick --routines --triggers --events --hex-blob \
             --default-character-set=utf8mb4 --no-tablespaces --skip-comments "$DB" ;;
  shadow)  n 2; movable "${A[1]}"
           # the account's own hash + each of its FTP logins'; travel only
           # inside this SSH channel, land via chpasswd -e on stdin
           { echo "${A[1]}"; KP_U="${A[1]}" PANEL_PHP 'foreach (Transfer::ftpLogins((string)getenv("KP_U")) as $l) echo $l, "\n";'; } | while IFS= read -r L; do
             [[ "$L" =~ ^[a-z][a-z0-9_.-]{0,63}$ ]] || continue
             H="$(getent shadow "$L" | cut -d: -f2)" || continue
             printf '%s\t%s\n' "$L" "$H"
           done ;;
  dkim)    n 3; movable "${A[1]}"; owns_domain "${A[1]}" "${A[2]}"
           K="$(grep -m1 -oP "^\Q${A[2]}\E: \K/etc/exim4/dkim/[a-z0-9.-]+\.pem$" /etc/exim4/virtual/dkim 2>/dev/null || true)"
           [[ -n "$K" && -f "$K" ]] || exit 0
           SEL="${K#/etc/exim4/dkim/"${A[2]}".}"; SEL="${SEL%.pem}"
           [[ "$SEL" =~ ^[a-z0-9_-]{1,32}$ ]] || exit 0
           echo "SELECTOR $SEL"; cat -- "$K" ;;
  rsync-home)
           (( ${#A[@]} >= 5 )) || refuse "short rsync"; movable "${A[1]}"; rsync_ok 2
           [[ -d "/home/${A[1]}" && ! -L "/home/${A[1]}" ]] || refuse "no home"
           export SSH_ORIGINAL_COMMAND="rsync ${A[*]:2}"
           exec /usr/bin/rrsync -ro -no-lock "/home/${A[1]}" ;;
  rsync-mail)
           (( ${#A[@]} >= 6 )) || refuse "short rsync"; movable "${A[1]}"; owns_domain "${A[1]}" "${A[2]}"; rsync_ok 3
           M="/var/mail/virtual/${A[2]}"; [[ -d "$M" && ! -L "$M" ]] || { echo "no mail store" >&2; exit 4; }
           export SSH_ORIGINAL_COMMAND="rsync ${A[*]:3}"
           exec /usr/bin/rrsync -ro -no-lock "$M" ;;
  suspend|unsuspend)
           n 2; movable "${A[1]}"
           "$HERE/suspend-account.sh" "${A[1]}" "$V" >/dev/null
           KP_U="${A[1]}" KP_S="$V" PANEL_PHP 'Transfer::markSuspended((string)getenv("KP_U"), getenv("KP_S") === "suspend");'
           echo "ok $V ${A[1]}" ;;
  cut-hold)
           n 2; movable "${A[1]}"; U="${A[1]}"; ST="$KT_CUT/$U"
           "$HERE/exim-moved-patch.sh" > /dev/null 2>&1 || refuse "can't set up the mail hold here (exim-moved-patch.sh)"
           install -d -m 700 "$KT_CUT"
           if [[ ! -f "$ST" ]]; then   # first hold: remember how it was (written BEFORE each change)
             { echo "pair $ID"; [[ "$(passwd -S "$U" 2>/dev/null | awk '{print $2}')" == L ]] && echo "susp 1" || echo "susp 0"; } > "$ST"
           fi
           grep -q '^relay ' "$ST" && refuse "already passed on to the new server"
           while IFS=$'\t' read -r D M; do
             [[ "$D" =~ $DOM_RE && "$M" == 0 && -f "/usr/local/lsws/conf/vhosts/$D/vhost.conf" ]] || continue
             grep -qxF "maint $D" "$ST" || echo "maint $D" >> "$ST"
             "$HERE/maintenance.sh" "$D" on > /dev/null 2>&1 || refuse "holding page failed for $D"
             KP_U="$U" KP_D="$D" PANEL_PHP 'Transfer::setMaintenance((string)getenv("KP_U"), (string)getenv("KP_D"), true);'
           done < <(KP_U="$U" PANEL_PHP 'foreach (Transfer::siteFlags((string)getenv("KP_U")) as $r) echo $r["domain"], "\t", (int)$r["maintenance"], "\n";')
           while IFS= read -r D; do
             [[ "$D" =~ $DOM_RE ]] || continue
             grep -qxF "mail $D" "$ST" || echo "mail $D" >> "$ST"
             moved_set "$D" hold
           done < <(KP_U="$U" PANEL_PHP 'foreach (Transfer::mailDomains((string)getenv("KP_U")) as $d) echo $d, "\n";')
           "$HERE/suspend-account.sh" "$U" suspend > /dev/null
           KP_U="$U" PANEL_PHP 'Transfer::markSuspended((string)getenv("KP_U"), true);'
           echo "ok hold $(hostname -I | awk '{print $1}')" ;;
  cut-undo)
           n 2; movable "${A[1]}"; U="${A[1]}"; ST="$KT_CUT/$U"
           [[ -f "$ST" ]] || { echo "ok undo nothing held"; exit 0; }
           grep -q '^relay ' "$ST" && refuse "mail is already passed on to the new server: undo refused"
           while read -r K D; do
             case "$K" in
               maint) [[ "$D" =~ $DOM_RE ]] || continue
                      "$HERE/maintenance.sh" "$D" off > /dev/null 2>&1 || true
                      KP_U="$U" KP_D="$D" PANEL_PHP 'Transfer::setMaintenance((string)getenv("KP_U"), (string)getenv("KP_D"), false);' ;;
               mail)  [[ "$D" =~ $DOM_RE ]] && moved_del "$D" ;;
             esac
           done < "$ST"
           if grep -qx "susp 0" "$ST"; then
             "$HERE/suspend-account.sh" "$U" unsuspend > /dev/null
             KP_U="$U" PANEL_PHP 'Transfer::markSuspended((string)getenv("KP_U"), false);'
           fi
           rm -f -- "$ST"
           setsid -f exim -qff > /dev/null 2>&1 < /dev/null || true   # held mail: delivered here as before
           echo "ok undo $U" ;;
  cut-mail)
           n 2; movable "${A[1]}"; U="${A[1]}"; ST="$KT_CUT/$U"
           [[ -f "$ST" ]] || refuse "not held: cut-hold first"
           NEW="${SSH_CLIENT%% *}"; [[ "$NEW" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] || refuse "the new server isn't on IPv4"
           grep -qxF "relay $NEW" "$ST" || echo "relay $NEW" >> "$ST"
           while read -r K D; do [[ "$K" == mail && "$D" =~ $DOM_RE ]] && moved_set "$D" "$NEW"; done < "$ST"
           setsid -f exim -qff > /dev/null 2>&1 < /dev/null || true   # what was held goes now
           echo "ok mail $NEW" ;;
  cut-dns)
           n 2; movable "${A[1]}"; U="${A[1]}"; ST="$KT_CUT/$U"
           grep -q '^relay ' "$ST" 2>/dev/null || refuse "mail not passed on yet: cut-mail first"
           NEW="${SSH_CLIENT%% *}"; OLD="$(hostname -I | awk '{print $1}')"
           KP_U="$U" KP_O="$OLD" KP_N="$NEW" PANEL_PHP 'foreach (Transfer::repoint(Transfer::userId((string)getenv("KP_U")), (string)getenv("KP_O"), (string)getenv("KP_N")) as $l) echo $l, "\n";'
           echo "ok dns $NEW" ;;
  done)    n 1
           # answer first, then drop the key line (that ends this session)
           echo "ok done $ID"
           setsid -f bash -c 'sleep 2; exec "$1" source-drop "$2"' _ "$HERE/transfer-pair.sh" "$ID" >/dev/null 2>&1 < /dev/null ;;
  *) refuse "unknown verb" ;;
esac
