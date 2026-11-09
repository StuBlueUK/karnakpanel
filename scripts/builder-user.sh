#!/usr/bin/env bash
# builder-user.sh <user> <domain> <subdir> <op> [args] — Karnak Builder worker.
# Runs AS THE ACCOUNT USER, never root: builder.sh (root, via sudo) validates
# and then re-execs this through `runuser -u <user>`, so every file it reads
# or writes is bounded by the kernel's own permission checks — a planted
# symlink in the tenant's home can only ever reach what the tenant could
# reach anyway (no confused deputy). It sits in scripts/ (sudo-able by the
# panel worker), so it refuses to run as root at all.
#
# Site state: /home/<u>/.karnakbuilder/<domain>[+<subdir>]/
#   project.json  draft.json  revisions/*.json.gz  assets/*  published.txt
#   form.json form.key form.log form-rl/ (contact form, see builder-form.php)
# Live output: /home/<u>/<domain>/public_html[/<subdir>]
#
# ops:  read <project.json|draft.json|published.txt|form.log|rev:NAME|asset:NAME>
#       write <project|draft>          (payload on stdin)
#       revs | asset-list | status
#       asset-put <name> | asset-del <name>   (asset-put: bytes on stdin)
#       publish                        (bundle tar on stdin: site/ + private/)
#       unpublish | delete
set -euo pipefail
umask 022
[[ "$(id -u)" -ne 0 ]] || { echo "builder-user.sh must not run as root"; exit 1; }
U="${1:?}"; D="${2:?}"; SUB="${3-}"; OP="${4:?}"; A="${5-}"
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ && "$(id -un)" == "$U" ]] || { echo "bad user"; exit 1; }
[[ "$D" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$ ]] || { echo "bad domain"; exit 1; }
[[ "$SUB" =~ ^[a-z0-9_-]{0,64}$ ]] || { echo "bad subdir"; exit 1; }
H="/home/$U"
KEY="$D${SUB:++$SUB}"
SITE="$H/.karnakbuilder/$KEY"
ROOT="$H/$D/public_html"
DOC="$ROOT${SUB:+/$SUB}"
ASSET_RE='^[a-z0-9][a-z0-9_-]{0,80}\.(webp|jpg|png|gif)$'
REV_RE='^[0-9]{8}T[0-9]{6}-[0-9a-f]{4}-(save|publish)\.json\.gz$'
MAX_JSON=$((8 * 1024 * 1024))
MAX_ASSET=$((10 * 1024 * 1024))

die() { echo "$*"; exit 1; }
# a real (non-symlink) directory, or absent
realdir() { [[ ! -L "$1" ]] && { [[ -d "$1" ]] || [[ ! -e "$1" ]]; }; }
# a real regular file (never follow a link the tenant planted)
realfile() { [[ -f "$1" && ! -L "$1" ]]; }

site_dirs() {
  realdir "$H/.karnakbuilder" && realdir "$SITE" || die "builder folder is a symlink — refusing"
  mkdir -p "$SITE/revisions" "$SITE/assets"
  # the home's default ACL (u:nobody:rX, for docroots) must not reach the
  # private project: strip it so nothing new inherits it either
  setfacl -b "$H/.karnakbuilder" 2>/dev/null || true
  setfacl -P -R -b "$SITE" 2>/dev/null || true
  chmod 700 "$H/.karnakbuilder" "$SITE" "$SITE/revisions" "$SITE/assets"
}

# stdin -> file, size-capped, atomic
put_capped() {
  local dest="$1" cap="$2" tmp
  tmp="$(mktemp "$SITE/.in-XXXXXX")"
  head -c $((cap + 1)) > "$tmp"
  [[ "$(stat -c %s "$tmp")" -le "$cap" ]] || { rm -f "$tmp"; die "too large"; }
  chmod 600 "$tmp"; mv -f "$tmp" "$dest"
}

prune_revs() {
  # keep the newest 20 saves; publish points for 90 days (always the newest 5)
  local n=0 f
  while IFS= read -r f; do n=$((n+1)); [[ $n -gt 20 ]] && rm -f -- "$SITE/revisions/$f"; done \
    < <(ls -1 "$SITE/revisions" 2>/dev/null | grep -E -- '-save\.json\.gz$' | sort -r)
  n=0
  while IFS= read -r f; do
    n=$((n+1))
    [[ $n -gt 5 && -n "$(find "$SITE/revisions/$f" -mtime +90 2>/dev/null)" ]] && rm -f -- "$SITE/revisions/$f"
  done < <(ls -1 "$SITE/revisions" 2>/dev/null | grep -E -- '-publish\.json\.gz$' | sort -r)
  return 0
}

stamp_rev() {
  local kind="$1" name
  name="$(date -u +%Y%m%dT%H%M%S)-$(od -An -tx1 -N2 /dev/urandom | tr -d ' \n')-$kind.json.gz"
  realfile "$SITE/project.json" || return 0
  gzip -c "$SITE/project.json" > "$SITE/revisions/$name"
  chmod 600 "$SITE/revisions/$name"
  prune_revs
}

# walk DOC's parents under ROOT: no symlinks anywhere on the way
doc_ok() {
  realdir "$ROOT" && [[ -d "$ROOT" ]] || die "no docroot for $D"
  [[ -z "$SUB" ]] || realdir "$DOC" || die "folder $SUB is a symlink — refusing"
}

# target is free for a FIRST publish: nothing but the stock placeholder (and,
# at a domain root, the folders of this domain's other builder sites)
doc_left() {
  [[ -d "$DOC" ]] || return 0
  local n left=""
  while IFS= read -r n; do
    case "$n" in index.html|.well-known|autodiscover|.htaccess) continue;; esac
    [[ -z "$SUB" && -d "$H/.karnakbuilder/$D+$n" && ! -L "$H/.karnakbuilder/$D+$n" ]] && continue
    left+="$n "
  done < <(ls -A "$DOC")
  printf '%s' "${left% }"
}
doc_free() {
  local left; left="$(doc_left | cut -d' ' -f1-5)"
  [[ -z "$left" ]] || die "folder is not empty (${left// /, }) — publish to an empty folder, or move those files to Trash first"
}

# remove one manifest path from DOC (regular files only), then empty parents
drop_path() {
  local rel="$1" p
  [[ "$rel" != *".."* && "$rel" != /* && -n "$rel" ]] || return 0
  [[ "$rel" == .well-known* ]] && return 0
  p="$DOC/$rel"
  realfile "$p" && rm -f -- "$p"
  p="$(dirname -- "$p")"
  while [[ "$p" != "$DOC" && "$p" == "$DOC"/* && ! -L "$p" ]]; do
    rmdir -- "$p" 2>/dev/null || break
    p="$(dirname -- "$p")"
  done
}

placeholder() {
  realfile "$DOC/index.html" || [[ -e "$DOC/index.html" ]] || \
    printf '<!doctype html><meta charset="utf-8"><title>%s</title><p>Coming soon.</p>\n' "$D" > "$DOC/index.html"
}

case "$OP" in
  read)
    case "$A" in
      project.json|draft.json|published.txt|form.log) F="$SITE/$A"; CAP=$MAX_JSON ;;
      rev:*) N="${A#rev:}"; [[ "$N" =~ $REV_RE ]] || die "bad revision"; F="$SITE/revisions/$N"; CAP=$MAX_JSON ;;
      asset:*) N="${A#asset:}"; [[ "$N" =~ $ASSET_RE ]] || die "bad asset"; F="$SITE/assets/$N"; CAP=$MAX_ASSET ;;
      *) die "bad file" ;;
    esac
    realdir "$SITE" || die "builder folder is a symlink — refusing"
    realfile "$F" || exit 3   # 3 = not found (callers map it)
    [[ "$(stat -c %s "$F")" -le "$CAP" ]] || die "file too large"
    # binary goes out base64 (the panel's exec wrapper trims line ends)
    case "$A" in
      rev:*) gzip -dc -- "$F" | head -c "$CAP" ;;
      asset:*) base64 -w0 -- "$F"; echo ;;
      *) cat -- "$F" ;;
    esac
    ;;
  write)
    site_dirs
    case "$A" in
      project) put_capped "$SITE/project.json" "$MAX_JSON"; stamp_rev save ;;
      draft)   put_capped "$SITE/draft.json" "$MAX_JSON" ;;
      *) die "bad kind" ;;
    esac
    echo "saved"
    ;;
  revs)
    realdir "$SITE" || die "builder folder is a symlink — refusing"
    [[ -d "$SITE/revisions" ]] || exit 0
    ls -1 "$SITE/revisions" | grep -E "$REV_RE" | sort -r || true
    ;;
  asset-put)
    [[ "$A" =~ $ASSET_RE ]] || die "bad asset name"
    site_dirs
    [[ "$(ls -1 "$SITE/assets" | wc -l)" -lt 500 ]] || die "asset limit reached (500 files)"
    put_capped "$SITE/assets/$A" "$MAX_ASSET"
    chmod 644 "$SITE/assets/$A"
    echo "uploaded"
    ;;
  asset-del)
    [[ "$A" =~ $ASSET_RE ]] || die "bad asset name"
    realfile "$SITE/assets/$A" && rm -f -- "$SITE/assets/$A"
    echo "deleted"
    ;;
  asset-list)
    realdir "$SITE" || die "builder folder is a symlink — refusing"
    [[ -d "$SITE/assets" ]] || exit 0
    for f in "$SITE"/assets/*; do
      n="${f##*/}"; [[ "$n" =~ $ASSET_RE ]] && realfile "$f" && printf '%s %s\n' "$n" "$(stat -c %s "$f")"
    done
    true
    ;;
  status)
    doc_ok
    if realfile "$SITE/published.txt"; then echo "published"; exit 0; fi
    LEFT="$(doc_left)"
    read -ra LW <<< "$LEFT"
    if (( ${#LW[@]} == 0 )); then echo "free"; else echo "occupied ${LW[*]:0:5}$( (( ${#LW[@]} > 5 )) && echo " …" )"; fi
    ;;
  publish)
    site_dirs
    doc_ok
    FIRST=1; realfile "$SITE/published.txt" && FIRST=0
    [[ $FIRST -eq 0 ]] || doc_free
    B="$(mktemp -d "$SITE/build-XXXXXX")"
    trap 'rm -rf -- "$B"' EXIT
    # bundle from the panel: regular files + dirs only, no links/devices
    tar -x -C "$B" --no-same-permissions --no-overwrite-dir -f - || die "bad bundle"
    [[ -z "$(find "$B" ! -type f ! -type d -print -quit)" ]] || die "bundle holds non-regular files"
    [[ -d "$B/site" ]] || die "bundle has no site"
    # uploaded images ride along (only well-named regular files)
    mkdir -p "$B/site/assets/kb/img"
    for f in "$SITE"/assets/*; do
      n="${f##*/}"; [[ "$n" =~ $ASSET_RE ]] && realfile "$f" && cp -- "$f" "$B/site/assets/kb/img/$n"
    done
    rmdir "$B/site/assets/kb/img" 2>/dev/null || true
    OLD="$B/old.txt"; : > "$OLD"
    [[ $FIRST -eq 1 ]] || awk '{ $1=""; sub(/^ /,""); print }' "$SITE/published.txt" > "$OLD"
    # a robots.txt the customer placed themselves wins over the generated one
    if [[ -e "$DOC/robots.txt" ]] && ! grep -qx 'robots.txt' "$OLD"; then rm -f "$B/site/robots.txt"; fi
    ( cd "$B/site" && find . -type f -printf '%P\n' | LC_ALL=C sort ) > "$B/new.txt"
    # never overwrite a file we did not write ourselves
    CLASH=""
    while IFS= read -r rel; do
      if [[ -e "$DOC/$rel" || -L "$DOC/$rel" ]] && ! grep -qxF -- "$rel" "$OLD"; then
        [[ $FIRST -eq 1 && "$rel" == "index.html" ]] && continue   # stock placeholder
        CLASH+="$rel "
      fi
    done < "$B/new.txt"
    # nor publish into a folder someone else made (an app installed at /blog/)
    while IFS= read -r top; do
      if [[ -e "$DOC/$top" || -L "$DOC/$top" ]] && ! awk -v p="$top/" 'index($0, p) == 1 { f = 1; exit } END { exit !f }' "$OLD"; then CLASH+="$top/ "; fi
    done < <(cd "$B/site" && find . -mindepth 1 -maxdepth 1 -type d -printf '%P\n')
    [[ -z "$CLASH" ]] || die "these files already exist and were not made by Karnak Builder: ${CLASH% } — rename the page or move the files"
    mkdir -p "$DOC"; chmod 755 "$DOC"
    ( cd "$B/site" && find . -type f -exec chmod 644 {} + && find . -type d -exec chmod 755 {} + )
    [[ -s "$B/new.txt" ]] || die "nothing to publish"
    ( cd "$B/site" && xargs -d '\n' -a "$B/new.txt" sha256sum -- ) > "$B/published.txt"
    # swap in file by file (mv is atomic per file on the same filesystem)
    while IFS= read -r rel; do
      dir="$(dirname -- "$rel")"
      if [[ "$dir" != "." ]]; then
        p="$DOC"; IFS='/' read -ra parts <<< "$dir"
        for part in "${parts[@]}"; do p="$p/$part"; [[ ! -L "$p" ]] || die "symlink in the way: ${p#$DOC/}"; done
        mkdir -p -- "$DOC/$dir"
      fi
      mv -f -- "$B/site/$rel" "$DOC/$rel"
    done < "$B/new.txt"
    # pages/files dropped since the last publish
    while IFS= read -r rel; do
      grep -qxF -- "$rel" "$B/new.txt" || drop_path "$rel"
    done < "$OLD"
    # contact form config (outside the docroot; the key never leaves it)
    if [[ -f "$B/private/form.json" ]]; then
      cp -- "$B/private/form.json" "$SITE/form.json"
      realfile "$SITE/form.key" || { od -An -tx1 -N32 /dev/urandom | tr -d ' \n' > "$SITE/form.key"; }
      mkdir -p "$SITE/form-rl"
      chmod 600 "$SITE/form.json" "$SITE/form.key"; chmod 700 "$SITE/form-rl"
      setfacl -b -- "$SITE/form.json" "$SITE/form.key" "$SITE/form-rl" 2>/dev/null || true
    else
      rm -f -- "$SITE/form.json"
    fi
    cp -- "$B/published.txt" "$SITE/published.txt"; chmod 600 "$SITE/published.txt"
    stamp_rev publish
    echo "published $(wc -l < "$B/new.txt") files"
    ;;
  unpublish)
    doc_ok
    realfile "$SITE/published.txt" || { echo "not published"; exit 0; }
    while IFS= read -r rel; do drop_path "$rel"; done < <(awk '{ $1=""; sub(/^ /,""); print }' "$SITE/published.txt")
    rm -f -- "$SITE/published.txt" "$SITE/form.json"
    [[ -d "$DOC" ]] && placeholder
    echo "unpublished"
    ;;
  delete)
    if realfile "$SITE/published.txt"; then "$0" "$U" "$D" "$SUB" unpublish >/dev/null; fi
    realdir "$SITE" || die "builder folder is a symlink — refusing"
    rm -rf -- "$SITE"
    echo "deleted"
    ;;
  *) die "bad op" ;;
esac
