#!/usr/bin/env bash
# builder.sh <user> <domain> <subdir> <op> [arg] [staged path] — Karnak Builder
# (root, via sudo from BuilderController). Validates everything, then hands
# the actual file work to builder-user.sh running AS THE ACCOUNT USER
# (runuser), feeding it staged panel payloads on stdin. Root never reads or
# writes inside the tenant's home itself, so planted symlinks get nothing.
#   read <file> | revs | asset-list | status | unpublish | delete
#   write <project|draft> <tmpfile>      asset-put <name> <tmpfile>
#   asset-del <name>                     publish - <bundle dir>
# Staged paths must live under the panel's tmp-uploads dir; they are removed
# here after use (success or not). Idempotent per op; fails loudly.
set -euo pipefail
U="${1:?}"; D="${2:?}"; SUB="${3-}"; OP="${4:?}"; A="${5-}"; P="${6-}"
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad user"; exit 1; }
[[ "$D" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$ ]] || { echo "bad domain"; exit 1; }
[[ "$SUB" =~ ^[a-z0-9_-]{0,64}$ ]] || { echo "bad subdir"; exit 1; }
[[ "$OP" =~ ^(read|revs|asset-list|status|unpublish|delete|write|asset-put|asset-del|publish)$ ]] || { echo "bad op"; exit 1; }
[[ "$A" =~ ^[a-zA-Z0-9:._+-]{0,120}$ ]] || { echo "bad arg"; exit 1; }
id "$U" >/dev/null 2>&1 || { echo "no such user"; exit 1; }
[[ "$(id -u "$U")" -ge 1000 ]] || { echo "refusing system user"; exit 1; }
H="/home/$U"
[[ -d "$H/$D" && ! -L "$H/$D" && "$(stat -c %U "$H/$D")" == "$U" ]] || { echo "no such domain folder for $U"; exit 1; }

HERE="$(dirname "$(readlink -f "$0")")"
W="$HERE/builder-user.sh"
STAGE="$(realpath -m /usr/local/skypanel/panel/storage/tmp-uploads)"
FORM_SRC="/usr/local/skypanel/panel/app/Builder/runtime"

cleanup() { [[ -n "${RP:-}" && "$RP" == "$STAGE"/* ]] && rm -rf -- "$RP"; return 0; }
trap cleanup EXIT

# staged path: under tmp-uploads, the right type, no symlink anywhere
RP=""
if [[ "$OP" =~ ^(write|asset-put|publish)$ ]]; then
  [[ -n "$P" ]] || { echo "missing payload"; exit 1; }
  RP="$(realpath -m -- "$P")"
  case "$RP" in "$STAGE"/*) ;; *) RP=""; echo "payload outside staging"; exit 1;; esac
  [[ "$RP" == "$(realpath -m -- "$STAGE/$(basename -- "$RP")")" ]] || { echo "payload must sit directly in staging"; exit 1; }
  [[ ! -L "$P" ]] || { echo "payload is a symlink"; exit 1; }
fi

run_as() {
  runuser -u "$U" -- env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin HOME="$H" LANG=C.UTF-8 \
    "$W" "$U" "$D" "$SUB" "$@"
}

case "$OP" in
  write|asset-put)
    [[ -f "$RP" ]] || { echo "no such payload"; exit 1; }
    run_as "$OP" "$A" < "$RP"
    ;;
  publish)
    [[ -d "$RP" && -d "$RP/site" ]] || { echo "no such bundle"; exit 1; }
    [[ -z "$(find "$RP" ! -type f ! -type d -print -quit)" ]] || { echo "bundle holds links or devices"; exit 1; }
    # the contact form runtime must be byte-identical to this release's copy
    for f in form.php pow.js; do
      if [[ -e "$RP/site/_kb/$f" ]]; then
        cmp -s -- "$RP/site/_kb/$f" "$FORM_SRC/$f" || { echo "form runtime $f does not match the release"; exit 1; }
      fi
    done
    [[ ! -e "$RP/site/_kb" ]] || [[ -z "$(ls -A "$RP/site/_kb" | grep -v -x -e form.php -e pow.js)" ]] || { echo "unexpected files in _kb/"; exit 1; }
    SIZE="$(du -sb -- "$RP" | cut -f1)"
    [[ "$SIZE" -le $((64 * 1024 * 1024)) ]] || { echo "bundle too large"; exit 1; }
    PARTS=(site); [[ -d "$RP/private" ]] && PARTS+=(private)
    tar -C "$RP" --owner=0 --group=0 --numeric-owner -cf - -- "${PARTS[@]}" | run_as publish
    ;;
  *)
    run_as "$OP" "$A"
    ;;
esac
