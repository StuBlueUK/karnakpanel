#!/usr/bin/env bash
# release-check.sh <tarball> — fail the cut if dev-only files ship.
# Local-only files (COMMANDS.md, internal docs, secrets, scaffolding) must
# NEVER reach users; the tar include-list is human-edited, so verify output.
set -euo pipefail
T="${1:?usage: release-check.sh <tarball>}"
[[ -f "$T" ]] || { echo "no such tarball"; exit 1; }
# NOTE: list to a FILE first — piping tar straight into grep -q trips
# SIGPIPE-under-pipefail (grep quits at first match, tar dies writing) and
# every check would false-fail. Same trap as tr|head (see transfer scripts).
LIST="$(mktemp)"; trap 'rm -f "$LIST"' EXIT
tar tzf "$T" > "$LIST"
FAIL=0
banned() {
  if grep -qE "$1" "$LIST"; then echo "BANNED PRESENT: $1"; FAIL=1; fi
}
banned 'COMMANDS\.md'
banned '(^|/)config\.php$'
banned '(^|/)storage(/|$)'
banned '(^|/)\.git(/|$)'
banned 'session-state|gotchas\.md|AGENTS\.md|agents-archive'
banned '(^|/)tests(/|$)'
banned '(^|/)prod(/|$)'
banned 'docker-compose\.yml|\.gitignore'
# ownership/mode: every entry root (0/0) and no group/world write (a root
# extraction restores owners by name — archive owners must be root).
# Verbose listing goes to a FILE like LIST (pipe-into-head = SIGPIPE trap).
VLIST="$(mktemp)"; trap 'rm -f "$LIST" "$VLIST"' EXIT
tar tvzf "$T" > "$VLIST"
BADOWN="$(awk '$2 != "root/root" && $2 != "0/0"' "$VLIST")"
[[ -z "$BADOWN" ]] || { echo "NON-ROOT OWNER in tarball (build with --owner=0 --group=0 --numeric-owner):"; sed -n 1,3p <<<"$BADOWN"; FAIL=1; }
BADMODE="$(awk 'substr($1,6,1)=="w" || substr($1,9,1)=="w"' "$VLIST")"
[[ -z "$BADMODE" ]] || { echo "GROUP/WORLD-WRITABLE entries in tarball (build with --mode=go-w):"; sed -n 1,3p <<<"$BADMODE"; FAIL=1; }
required() {
  grep -qE "(^|/)${1#^}" "$LIST" || { echo "MISSING REQUIRED: $1"; FAIL=1; }
}
required 'MANIFEST$'
required 'installer/install\.sh$'
required 'VERSION$'
required 'LICENSE$'
required 'database/migrations/'
# detached signature next to the tarball: the bootstrap refuses to install
# without one, so an unsigned cut would break every fresh install.
PUB="$(dirname "$(readlink -f "$0")")/../installer/configs/update-pubkey.pem"
if [[ ! -f "$T.sig" ]]; then
  echo "MISSING SIGNATURE: $T.sig (sign with the release key — see docs/deploy-to-users.md)"; FAIL=1
elif ! openssl dgst -sha256 -verify "$PUB" -signature "$T.sig" "$T" >/dev/null 2>&1; then
  echo "SIGNATURE INVALID: $T.sig does not verify against installer/configs/update-pubkey.pem"; FAIL=1
fi
[[ "$FAIL" == "0" ]] && echo "release-check OK: $T" || { echo "release-check FAILED"; exit 1; }
