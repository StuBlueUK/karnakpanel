#!/usr/bin/env bash
# wp-autoupdate.sh <on|off|status> <docroot> — WordPress auto-update policy
# without wp-cli: a must-use plugin (plugins+translations auto, themes stay
# manual) + WP_AUTO_UPDATE_CORE=minor in wp-config.php (security releases
# only — major versions never auto). Content, uploads, DB and wp-config
# itself are never touched by updates; toggling off removes the mu-plugin
# and pins core updates to false. Refuses non-WordPress dirs.
set -euo pipefail
MODE="${1:?on|off|status}"; DOC="${2:?docroot}"
[[ "$MODE" =~ ^(on|off|status)$ ]] || { echo "bad mode"; exit 1; }
# docroot must be a real path inside a panel-style home (no traversal games)
REAL="$(realpath -m "$DOC")"
[[ "$REAL" =~ ^/home/[a-z][a-z0-9_]{2,31}/.+ ]] || { echo "docroot outside homes"; exit 1; }
[[ -f "$REAL/wp-config.php" && -d "$REAL/wp-content" ]] || { echo "not a WordPress install: $REAL"; exit 1; }
U="$(stat -c %U "$REAL")"
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad owner"; exit 1; }
MU="$REAL/wp-content/mu-plugins/karnakpanel-autoupdate.php"
is_on() {
  [[ -f "$MU" ]] && grep -q "define( *'WP_AUTO_UPDATE_CORE', *'minor'" "$REAL/wp-config.php"
}
if [[ "$MODE" == "status" ]]; then
  is_on && echo on || echo off
  exit 0
fi
if [[ "$MODE" == "on" ]]; then
  cp -a "$REAL/wp-config.php" "$REAL/wp-config.php.bak-karnakpanel"
  python3 - "$REAL/wp-config.php" <<'PYEOF'
import re, sys
p = sys.argv[1]
src = open(p).read()
line = "define( 'WP_AUTO_UPDATE_CORE', 'minor' );"
if re.search(r"define\s*\(\s*'WP_AUTO_UPDATE_CORE'", src):
    # swallow stray trailing semicolons too, or every re-apply appends one
    src = re.sub(r"define\s*\(\s*'WP_AUTO_UPDATE_CORE'\s*,\s*[^)]+\)\s*;*", line, src)
elif "stop editing" in src:
    src = src.replace("/* That's all, stop editing!", line + "\n\n/* That's all, stop editing!", 1)
else:
    src = src.rstrip() + "\n" + line + "\n"
open(p, 'w').write(src)
PYEOF
  mkdir -p "$REAL/wp-content/mu-plugins"
  cat > "$MU" <<'PHEOF'
<?php
// KarnakPanel auto-update policy (managed from the panel Software page —
// do not hand-edit; the toggle rewrites this file). Minor core releases,
// plugins and translations update themselves; themes and major versions
// stay manual so a big change never lands unreviewed.
add_filter( 'auto_update_plugin', '__return_true' );
add_filter( 'auto_update_theme', '__return_false' );
add_filter( 'auto_update_translation', '__return_true' );
PHEOF
  chown -R "$U:" "$REAL/wp-config.php" "$REAL/wp-config.php.bak-karnakpanel" "$MU"
  chmod 644 "$REAL/wp-config.php" "$MU"
  echo "auto-updates ON (minor core + plugins; themes + majors stay manual)"
else
  rm -f "$MU"
  python3 - "$REAL/wp-config.php" <<'PYEOF'
import re, sys
p = sys.argv[1]
src = open(p).read()
line = "define( 'WP_AUTO_UPDATE_CORE', false );"
if re.search(r"define\s*\(\s*'WP_AUTO_UPDATE_CORE'", src):
    src = re.sub(r"define\s*\(\s*'WP_AUTO_UPDATE_CORE'\s*,\s*[^)]+\)\s*;*", line, src)
elif "stop editing" in src:
    # WP auto-updates minor cores even with NO constant — off must say so
    src = src.replace("/* That's all, stop editing!", line + "\n\n/* That's all, stop editing!", 1)
else:
    src = src.rstrip() + "\n" + line + "\n"
open(p, 'w').write(src)
PYEOF
  chown "$U:" "$REAL/wp-config.php"; chmod 644 "$REAL/wp-config.php"
  echo "auto-updates OFF (core pinned, mu-plugin removed)"
fi
