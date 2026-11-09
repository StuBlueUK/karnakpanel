#!/usr/bin/env bash
# shield-dotfile-patch.sh — add the bot shield's dotfile line to shields that
# predate it. The shield used to 403 only URIs ENDING in .env/.git/..., so
# /.env.local, /.env.bak, /.git/config, /.aws/credentials fell through to the
# site's front controller: every probe a PHP request (prod 2026-11-01: ~115 in
# 6 s on one HTTP/2 connection saturated a pool; a fail2ban ban can't cut an
# open connection, the shield can). The new line matches a dotfile/dot-dir
# path SEGMENT (/.env, /.env.*, /.git/...) — .gitignore, .github, .envrc and
# /.well-known (excluded by the shield's first condition) still pass.
# Only vhosts whose shield is on and still lacks the line change; it goes in
# right after the old endings line. One reload if anything changed.
# Idempotent; quiet when there is nothing to do. Called by host-converge.sh.
set -euo pipefail
PAIR="$(mktemp)"; trap 'rm -f "$PAIR"' EXIT
# anchor/new via FILE, compared literally (awk -v would eat the backslashes)
cat > "$PAIR" <<'LINES'
    RewriteCond %{REQUEST_URI} \.(git|svn|env|sql|bak|swp|dist)$ [NC,OR]
    RewriteCond %{REQUEST_URI} /\.(env|git|svn|hg|aws|ssh|htpasswd|DS_Store|vscode|docker)(\.|/|$) [NC,OR]
LINES
ANCHOR="$(sed -n 1p "$PAIR")"; NEW="$(sed -n 2p "$PAIR")"
n=0
for F in /usr/local/lsws/conf/vhosts/*/vhost.conf; do
  [[ -f "$F" && ! -L "$F" ]] || continue
  grep -qxF -- "$ANCHOR" "$F" || continue
  grep -qxF -- "$NEW" "$F" && continue
  awk 'NR==FNR{if(FNR==1)a=$0; else n=$0; next} {print} $0==a{print n}' "$PAIR" "$F" > "$F.tmp"
  if grep -qxF -- "$NEW" "$F.tmp" && [[ "$(wc -l < "$F.tmp")" == "$(( $(wc -l < "$F") + 1 ))" ]]; then
    cat "$F.tmp" > "$F"; n=$((n+1))
  else
    echo "shield-dotfile-patch: $F left unchanged (rewrite check failed)"
  fi
  rm -f "$F.tmp"
done
if [[ "$n" -gt 0 ]]; then
  /usr/local/lsws/bin/lswsctrl reload >/dev/null 2>&1 || systemctl reload lsws 2>/dev/null || true
  echo "shield-dotfile-patch: $n vhost(s) patched"
fi
