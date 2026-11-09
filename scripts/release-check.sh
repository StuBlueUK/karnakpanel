#!/usr/bin/env bash
# release-check.sh <tarball> — fail the cut if anything dev-only, secret or
# unshippable is inside. The tar include-list is human-edited, so verify the
# OUTPUT: names, owners/modes, signatures, docs whitelist, and the actual file
# CONTENTS (private keys, the live DB password).
#
# Local-only must NEVER reach users: COMMANDS.md, AGENTS.md, session-state,
# gotchas, architecture/refactor notes, tests/, .githooks/, phpstan, prod/,
# whmcs/, docker/git scaffolding, config.php, storage/, keys and passwords.
set -euo pipefail
T="${1:?usage: release-check.sh <tarball>}"
[[ -f "$T" ]] || { echo "no such tarball"; exit 1; }
ROOT="$(cd "$(dirname "$(readlink -f "$0")")/.." && pwd)"
FAIL=0

# NOTE: list to a FILE first — piping tar straight into grep -q trips
# SIGPIPE-under-pipefail (grep quits at first match, tar dies writing) and
# every check would false-fail. Same trap as tr|head (see transfer scripts).
LIST="$(mktemp)"; X="$(mktemp -d)"
trap 'rm -f "$LIST"; rm -rf "$X"' EXIT
tar tzf "$T" > "$LIST"

banned() {
  local hit
  hit="$(grep -E "$1" "$LIST" | head -5 || true)"
  [[ -z "$hit" ]] || { echo "BANNED PRESENT: $1"; sed 's/^/  /' <<<"$hit"; FAIL=1; }
}
# ---- dev-only paths -------------------------------------------------------
banned 'COMMANDS\.md'
banned 'session-state|gotchas\.md|AGENTS\.md|agents-archive|architecture\.md|canonical\.md|refactor-admin\.md'
banned '(^|/)tests(/|$)'
banned '(^|/)\.git(/|$)|(^|/)\.githooks(/|$)|(^|/)\.github(/|$)'
banned 'phpstan\.neon$|phpstan-baseline\.neon$'
banned '(^|/)prod(/|$)'
banned '(^|/)whmcs(/|$)'
banned 'docker-compose\.yml|\.gitignore|Makefile$'
# ---- secrets and runtime state -------------------------------------------
banned '(^|/)config\.php$'                      # only config.sample.php ships
banned '(^|/)storage(/|$)'
banned '(^|/)\.env(\.|$)|(^|/)\.env$'
banned '(^|/)(\.skypanel-dbpass|\.karnakdav-dbpass|\.skypanel-adminpass|\.skypanel-provpass)$'
banned '(^|/)(id_rsa|id_ed25519|authorized_keys)(\.|$)'
banned '\.(key|p12|pfx)$'
# .pem: the only allowed one is the PUBLIC update trust anchor
BADPEM="$(grep -E '\.pem$' "$LIST" | grep -vE '(^|/)installer/configs/update-pubkey\.pem$' | head -5 || true)"
[[ -z "$BADPEM" ]] || { echo "BANNED PRESENT: .pem other than installer/configs/update-pubkey.pem"; sed 's/^/  /' <<<"$BADPEM"; FAIL=1; }
# docs are a whitelist: only the customer deploy guide ships
BADDOC="$(grep -E '(^|/)docs/' "$LIST" | grep -vE '(^|/)docs/how-to-deploy\.md$' | head -5 || true)"
[[ -z "$BADDOC" ]] || { echo "BANNED PRESENT: internal doc in package"; sed 's/^/  /' <<<"$BADDOC"; FAIL=1; }

# ---- ownership / modes ---------------------------------------------------
# every entry root (0/0), no group/world write (a root extraction restores
# owners by name — archive owners must be root).
VLIST="$(mktemp)"; trap 'rm -f "$LIST" "$VLIST"; rm -rf "$X"' EXIT
tar tvzf "$T" > "$VLIST"
BADOWN="$(awk '$2 != "root/root" && $2 != "0/0"' "$VLIST" | head -3)"
[[ -z "$BADOWN" ]] || { echo "NON-ROOT OWNER in tarball (build with --owner=0 --group=0 --numeric-owner):"; sed 's/^/  /' <<<"$BADOWN"; FAIL=1; }
BADMODE="$(awk 'substr($1,6,1)=="w" || substr($1,9,1)=="w"' "$VLIST" | head -3)"
[[ -z "$BADMODE" ]] || { echo "GROUP/WORLD-WRITABLE entries in tarball (build with --mode=go-w):"; sed 's/^/  /' <<<"$BADMODE"; FAIL=1; }

# ---- required content ----------------------------------------------------
required() {
  grep -qE "(^|/)${1#^}" "$LIST" || { echo "MISSING REQUIRED: $1"; FAIL=1; }
}
required 'MANIFEST$'
required 'installer/install\.sh$'
required 'VERSION$'
required 'LICENSE$'
required 'database/migrations/'
required 'app/Dav/vendor/autoload\.php$|app/Dav/vendor\.tar\.gz$'
required 'installer/configs/update-pubkey\.pem$'

# ---- contents scan (the part a filename list cannot prove) --------------
tar xzf "$T" -C "$X"
KEYHITS="$(grep -RlE --binary-files=without-match \
  'BEGIN (RSA |EC |DSA |OPENSSH |PGP )?PRIVATE KEY' "$X" 2>/dev/null | head -5 || true)"
[[ -z "$KEYHITS" ]] || { echo "PRIVATE KEY MATERIAL inside the package:"; sed "s|^$X/|  |" <<<"$KEYHITS"; FAIL=1; }
# the live dev config's DB password must not appear anywhere in the payload
CFG="$ROOT/config/config.php"
if [[ -f "$CFG" ]]; then
  DBP="$(php -r '$c=require $argv[1]; echo (string)($c["db"]["pass"] ?? "");' "$CFG" 2>/dev/null || true)"
  if [[ -n "$DBP" ]] && grep -RIlq --binary-files=without-match -- "$DBP" "$X" 2>/dev/null; then
    echo "LIVE DB PASSWORD found inside the package"; FAIL=1
  fi
fi
# MANIFEST must match VERSION
if [[ -f "$X/MANIFEST" && -f "$X/VERSION" ]]; then
  MVER="$(sed -n 's/^VERSION=//p' "$X/MANIFEST")"
  FVER="$(tr -d '[:space:]' < "$X/VERSION")"
  [[ "$MVER" == "$FVER" && -n "$MVER" ]] || { echo "VERSION MISMATCH: MANIFEST=$MVER VERSION=$FVER"; FAIL=1; }
else
  echo "MISSING MANIFEST or VERSION inside the package"; FAIL=1
fi

# ---- signature -----------------------------------------------------------
# the bootstrap refuses to install without one, so an unsigned cut would
# break every fresh install.
PUB="$ROOT/installer/configs/update-pubkey.pem"
if [[ ! -f "$T.sig" ]]; then
  echo "MISSING SIGNATURE: $T.sig (sign with the release key — see docs/deploy-to-users.md)"; FAIL=1
elif ! openssl dgst -sha256 -verify "$PUB" -signature "$T.sig" "$T" >/dev/null 2>&1; then
  echo "SIGNATURE INVALID: $T.sig does not verify against installer/configs/update-pubkey.pem"; FAIL=1
fi

[[ "$FAIL" == "0" ]] && echo "release-check OK: $T ($(wc -l < "$LIST") entries, signed, no dev/secret payload)" \
  || { echo "release-check FAILED"; exit 1; }
