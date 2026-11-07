#!/usr/bin/env bash
# bind-version-patch.sh — stop BIND answering `version.bind` (CH TXT) with
# its exact package version: scanners use it to pick exploits. Adds
# `version "none";` to named.conf.options when it has no version line (an
# owner's own value is left alone). Checks the config, reloads named, and
# restores the old file if either fails. Idempotent; quiet when there is
# nothing to do. Called by host-converge.sh.
set -euo pipefail
O=/etc/bind/named.conf.options
[[ -f "$O" ]] || exit 0
grep -qE '^[[:space:]]*version[[:space:]]' "$O" && exit 0
cp -a "$O" "$O.pre-version"
awk '!d && /^options[[:space:]]*\{/{print; print "  // no exact version for scanners (version.bind)"; print "  version \"none\";"; d=1; next} {print}' "$O" > "$O.tmp"
grep -q '^  version "none";$' "$O.tmp" || { rm -f "$O.tmp" "$O.pre-version"; echo "bind-version-patch: no options block — unchanged"; exit 1; }
cat "$O.tmp" > "$O"; rm -f "$O.tmp"
if named-checkconf >/dev/null 2>&1 && rndc reconfig >/dev/null 2>&1; then
  rm -f "$O.pre-version"; echo "bind-version-patch: version.bind hidden"
else
  cat "$O.pre-version" > "$O"; rm -f "$O.pre-version"; rndc reconfig >/dev/null 2>&1 || true
  echo "bind-version-patch: check/reload failed — previous options restored"; exit 1
fi
