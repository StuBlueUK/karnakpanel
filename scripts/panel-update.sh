#!/usr/bin/env bash
# panel-update.sh <tarball> — safe self-update for KarnakPanel itself. DETACHED
# (web timeout): all work backgrounds into /var/log/karnakpanel/update.log.
#
# Tarball layout (built by packaging the repo):
#   MANIFEST            — VERSION=<x.y> line (required)
#   panel/...           — new panel tree (app/, public/, config.sample, ...)
#   scripts/...         — new privileged scripts
#   migrations/*.sql    — new DB migrations (idempotent, IF NOT EXISTS)
#
# Phases: validate -> snapshot (code+DB) -> stage to releases/<ver> ->
# php -l gate -> migrate (tracked) -> atomic symlink swap -> graceful restart
# -> health check -> auto-ROLLBACK (code+DB) on any failure. Keeps 3 releases.
set -euo pipefail
TARBALL="${1:?}"
LOG="/var/log/karnakpanel/update.log"
SKY="/usr/local/skypanel"
REL="$SKY/releases"
STAGE="/usr/local/skypanel/panel/storage/tmp-uploads"
# panel/ may be a symlink — compare REAL paths (upload lands in the live release's dir)
RSTAGE="$(realpath -m "$STAGE")"; RTARBALL="$(realpath -m "$TARBALL")"
case "$RTARBALL" in "$RSTAGE"/*.tar.gz) ;; *) echo "tarball outside staging"; exit 1;; esac
[[ -f "$RTARBALL" ]] || { echo "no such tarball"; exit 1; }
TARBALL="$RTARBALL"
( exec >>"$LOG" 2>&1
  echo "=== $(date -Is) panel update from $(basename "$TARBALL") ==="
  # 1. validate contents (no absolute paths, no .., required trees)
  LIST="$(tar tzf "$TARBALL")"
  echo "$LIST" | grep -E '^/|\\.\\.' && { echo "REFUSED: unsafe paths in tarball"; exit 1; }
  echo "$LIST" | grep -q '^MANIFEST$' || { echo "REFUSED: no MANIFEST"; exit 1; }
  echo "$LIST" | grep -q '^panel/' || { echo "REFUSED: no panel/ tree"; exit 1; }
  echo "$LIST" | grep -q '^scripts/' || { echo "REFUSED: no scripts/ tree"; exit 1; }
  # authenticity: SHA256SUMS.sig (over SHA256SUMS, which covers every file)
  # must verify against the install-time trust anchor. No valid signature =
  # no update, even if everything else looks right.
  PUBKEY="/usr/local/skypanel/update-pubkey.pem"
  [[ -f "$PUBKEY" ]] || { echo "REFUSED: trust anchor missing ($PUBKEY) — reinstall or restore it, never update blind"; exit 1; }
  echo "$LIST" | grep -q '^SHA256SUMS$' || { echo "REFUSED: no SHA256SUMS (unsigned tarball)"; exit 1; }
  echo "$LIST" | grep -q '^SHA256SUMS\.sig$' || { echo "REFUSED: no SHA256SUMS.sig (unsigned tarball)"; exit 1; }
  SIGDIR="$(mktemp -d)"
  tar xzOf "$TARBALL" SHA256SUMS > "$SIGDIR/SHA256SUMS" 2>/dev/null || { echo "REFUSED: cannot read SHA256SUMS"; rm -rf "$SIGDIR"; exit 1; }
  tar xzOf "$TARBALL" SHA256SUMS.sig > "$SIGDIR/SHA256SUMS.sig" 2>/dev/null || { echo "REFUSED: cannot read SHA256SUMS.sig"; rm -rf "$SIGDIR"; exit 1; }
  openssl dgst -sha256 -verify "$PUBKEY" -signature "$SIGDIR/SHA256SUMS.sig" "$SIGDIR/SHA256SUMS" >/dev/null 2>&1 || { echo "REFUSED: signature INVALID — tarball is not from the build host"; rm -rf "$SIGDIR"; exit 1; }
  echo "signature: VALID (build host)"
  rm -rf "$SIGDIR"
  VER="$(tar xzOf "$TARBALL" MANIFEST 2>/dev/null | grep -E '^VERSION=' | cut -d= -f2 | tr -cd 'a-zA-Z0-9._-')"
  [[ -n "$VER" ]] || { echo "REFUSED: MANIFEST has no VERSION="; exit 1; }
  [[ -d "$REL/$VER" ]] && { echo "REFUSED: release $VER already installed (rollback to it or bump VERSION)"; exit 1; }
  # 2. first-run conversion: live dirs -> versioned release + symlinks
  mkdir -p "$REL"
  TS="$(date +%Y%m%d-%H%M%S)"
  for d in panel scripts; do
    if [[ ! -L "$SKY/$d" ]]; then
      mkdir -p "$REL/prev-$TS"
      mv "$SKY/$d" "$REL/prev-$TS/$d"
    fi
  done
  # (re)point both symlinks at the newest staged trees = current live version
  rm -f "$SKY/panel" "$SKY/scripts"
  PREV_PANEL="$(ls -dt $REL/*/panel 2>/dev/null | head -1 || true)"
  PREV_SCRIPTS="$(ls -dt $REL/*/scripts 2>/dev/null | head -1 || true)"
  [[ -n "$PREV_PANEL" && -n "$PREV_SCRIPTS" ]] || { echo "REFUSED: cannot determine current release"; exit 1; }
  ln -s "$PREV_PANEL" "$SKY/panel"; ln -s "$PREV_SCRIPTS" "$SKY/scripts"
  CUR_VER="$(basename "$(dirname "$(readlink -f "$SKY/panel")")")"
  echo "current release: $CUR_VER -> new: $VER"
  # no downgrades via upload (rollback path owns that direction): a lower or
  # equal version here means a stale or replayed tarball. Skipped for legacy
  # pre-release layouts (CUR_VER "prev-<ts>"), which have nothing to compare.
  if [[ "$CUR_VER" =~ ^[0-9] ]]; then
    NEWEST="$(printf '%s\n%s\n' "$CUR_VER" "$VER" | sort -V | tail -1)"
    [[ "$NEWEST" == "$VER" && "$VER" != "$CUR_VER" ]] || { echo "REFUSED: $VER is not newer than installed $CUR_VER (use rollback for downgrades)"; exit 1; }
  fi
  # 3. snapshot DB (named for the release it belongs to, so rollback finds it;
  # code snapshot IS the previous release dir)
  DUMP="/backup/skypanel/paneldb-${CUR_VER}-$(date +%Y%m%d-%H%M%S).sql.gz"
  mysqldump -uroot skypanel 2>/dev/null | gzip > "$DUMP" && echo "db snapshot: $DUMP"
  # 4. stage + per-file integrity (signature proved WHO packed it; this
  # proves every file survived transit byte-identical) + lint gate
  mkdir -p "$REL/$VER"
  # --no-same-owner: as root, tar restores owners BY NAME from the archive
  # — never trust archive ownership. Everything shipped is root's; go-w now.
  tar --no-same-owner -xzf "$TARBALL" -C "$REL/$VER"
  chown -R root:root "$REL/$VER" && chmod -R go-w "$REL/$VER"
  (cd "$REL/$VER" && sha256sum -c SHA256SUMS >/dev/null 2>&1) || { echo "ABORTED: file hashes MISMATCH — tarball corrupted or tampered"; rm -rf "$REL/$VER"; exit 1; }
  echo "file hashes: OK"
  FAIL=0
  while IFS= read -r f; do php -l "$f" >/dev/null 2>&1 || { echo "LINT FAIL: $f"; FAIL=1; }; done < <(find "$REL/$VER/panel" -name '*.php')
  if [[ "$FAIL" != 0 ]]; then echo "ABORTED: lint gate failed, live untouched"; rm -rf "$REL/$VER"; exit 1; fi
  chmod +x "$REL/$VER"/scripts/*.sh 2>/dev/null || true
  # carry live state the tarball must never contain: DB credentials + staging/uploads
  if [[ -f "$PREV_PANEL/config/config.php" && ! -f "$REL/$VER/panel/config/config.php" ]]; then
    cp "$PREV_PANEL/config/config.php" "$REL/$VER/panel/config/config.php" && echo "carried live config.php"
  fi
  # worker (nobody / karnak-panel): carried + fresh files must be world-readable
  # (root umask is 027 — without this the new release 500s on config load).
  # EXCEPT config.php: DB + provision creds — worker group only (set below,
  # after the tree chown) — never world-readable.
  chmod 644 "$REL/$VER/panel/VERSION" 2>/dev/null || true
  chmod -R a+rX "$REL/$VER/panel/app" "$REL/$VER/panel/public" "$REL/$VER/panel/cli" "$REL/$VER/panel/database" 2>/dev/null || true
  if [[ -d "$PREV_PANEL/storage" ]]; then
    cp -a "$PREV_PANEL/storage/." "$REL/$VER/panel/storage/" 2>/dev/null && echo "carried storage/"
  fi
  chown -R root:root "$REL/$VER/panel" 2>/dev/null || true
  . "$SKY/scripts/panel-id.sh" 2>/dev/null || { PANEL_U=nobody; PANEL_G=nogroup; }
  chown root:"$PANEL_G" "$REL/$VER/panel/config/config.php" 2>/dev/null || true
  chmod 640 "$REL/$VER/panel/config/config.php" 2>/dev/null || true
  # ...except runtime state: sessions/uploads must stay worker-writable
  chown -R "$PANEL_U:$PANEL_G" "$REL/$VER/panel/storage" 2>/dev/null || true
  # 5. tracked migrations (new files only)
  mysql -uroot skypanel -e "CREATE TABLE IF NOT EXISTS applied_migrations (name VARCHAR(190) PRIMARY KEY, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);" 2>/dev/null || true
  if [[ -d "$REL/$VER/migrations" ]]; then
    for m in "$REL/$VER"/migrations/*.sql; do
      [[ -f "$m" ]] || continue
      n="$(basename "$m")"
      done=$(mysql -uroot -N skypanel -e "SELECT COUNT(*) FROM applied_migrations WHERE name='$n';" 2>/dev/null || echo 1)
      if [[ "$done" == "0" ]]; then
        mysql -uroot skypanel < "$m" && mysql -uroot skypanel -e "INSERT INTO applied_migrations(name) VALUES('$n');" && echo "migrated: $n" || { echo "MIGRATION FAILED: $n (live code untouched, DB may need the snapshot)"; exit 1; }
      fi
    done
  fi
  # 5b. cron table + system hardening (perms, webmail/pma isolation) are
  # converged by post-update.sh AFTER the swap (step 7b) from the installed
  # release — one implementation shared with update-check.sh, which is how
  # boxes on older updaters get the same convergence.
  # 6. atomic swap + graceful restart
  ln -sfn "$REL/$VER/panel" "$SKY/panel"
  ln -sfn "$REL/$VER/scripts" "$SKY/scripts"
  /usr/local/lsws/bin/lswsctrl restart 2>&1 | tail -1 || true
  # 7. health check, else full rollback. Generous loop: PHP's realpath cache
  # (TTL 120s) can serve the OLD release briefly after a symlink swap.
  OK=0
  for i in $(seq 1 60); do
    sleep 2
    code=$(curl -sk -o /dev/null -w "%{http_code}" --max-time 5 https://127.0.0.1:8090/login 2>/dev/null || echo 000)
    [[ "$code" == "200" ]] && { OK=1; break; }
  done
  if [[ "$OK" != 1 ]]; then
    echo "HEALTH CHECK FAILED — rolling back"
    ln -sfn "$PREV_PANEL" "$SKY/panel"; ln -sfn "$PREV_SCRIPTS" "$SKY/scripts"
    zcat "$DUMP" | mysql -uroot skypanel 2>/dev/null || true
    /usr/local/lsws/bin/lswsctrl restart 2>&1 | tail -1 || true
    echo "rolled back to $CUR_VER (code+DB)"
    exit 1
  fi
  # 8. prune old releases (keep 3 newest version dirs containing panel/)
  KEEP=$(ls -dt $REL/*/ 2>/dev/null | head -3)
  for d in $REL/*/; do
    echo "$KEEP" | grep -qxF "$d" || { rm -rf "$d"; echo "pruned old release: $d"; }
  done
  rm -f "$TARBALL"
  # staged installers clone forward with storage/ on every update, so the
  # Update page would keep offering the just-installed release (seen live:
  # 1.5.2+1.5.3+1.5.4 all piled up). Purge staged files for this version or
  # older from the NEW tree; newer (shouldn't exist) stay put.
  for _s in "$REL/$VER"/panel/storage/tmp-uploads/*.tar.gz; do
    [[ -f "$_s" ]] || continue
    _sv="$(tar xzOf "$_s" MANIFEST 2>/dev/null | grep -E '^VERSION=' | cut -d= -f2 | tr -cd 'a-zA-Z0-9._-')"
    if [[ -z "$_sv" ]] || [[ "$(printf '%s\n%s\n' "$_sv" "$VER" | sort -V | tail -1)" == "$VER" ]]; then
      rm -f "$_s" && echo "pruned staged installer: $(basename "$_s")"
    fi
  done; unset _s _sv
  # known-legit change source: re-baseline integrity (covers new code+scripts)
  # 7b. converge: ownership/modes over every release (rollback targets
  # included), shipped cron table, webmail/pma isolation. Non-fatal: the
  # daily update-check retries until it records success.
  "$SKY/scripts/post-update.sh" >>"$LOG" 2>&1 || true
  "$SKY/scripts/integrity-check.sh" --baseline >>"$LOG" 2>&1 || true
  # local VERSION just moved — refresh the update-check state now, or the
  # dashboard banner keeps offering the version we already installed until
  # the next daily probe.
  "$SKY/scripts/update-check.sh" >>"$LOG" 2>&1 || true
  echo "=== $(date -Is) update to $VER COMPLETE ==="
) &
echo "update to background started — follow /admin/logs?log=update"
