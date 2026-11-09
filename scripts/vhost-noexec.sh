#!/usr/bin/env bash
# vhost-noexec.sh <domain> <on|off> [--path <dir>]... [--no-reload]
# No PHP in upload folders: a rewrite block in vhost.conf (marked
# NOEXEC-START/END, inside the rules heredoc just before CUSTOM-START) that
# answers 403 to any PHP-type file under an upload folder — the usual
# webshell drop spot. OLS ignores .htaccess, so the vhost is the only place.
#   always (on): wp-content/uploads (any depth: subfolder installs too),
#                sites/<site>/files (Drupal), .well-known
#   --path <dir>: extra folder relative to the docroot (up to 10), e.g.
#                 images/uploads. Flags give the FULL state: re-runs converge.
# The match ends in (/|$) so x.php/fake.jpg (PATH_INFO) is caught too.
# `off` removes the block. Idempotent both directions.
set -euo pipefail
D="${1:?}"; ACT="${2:?}"; shift 2
NORELOAD=0; PATHS=()
while (( $# )); do
  case "$1" in
    --path) P="${2:-}"; shift 2
      P="${P#/}"; P="${P%/}"
      # plain folder names only: no '..' segment, no '//', no regex/shell chars
      [[ "$P" =~ ^[A-Za-z0-9_-][A-Za-z0-9._/-]{0,119}$ && "$P" != *//* && ! "/$P/" =~ /\.\.?/ ]] || { echo "bad folder: $P"; exit 1; }
      PATHS+=("$P") ;;
    --no-reload) NORELOAD=1; shift ;;
    *) echo "unknown flag: $1"; exit 1 ;;
  esac
done
[[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ && "$D" != *..* ]] || { echo "bad domain"; exit 1; }
[[ "$ACT" == on || "$ACT" == off ]] || { echo "want on|off"; exit 1; }
[[ "$ACT" == on || ${#PATHS[@]} == 0 ]] || { echo "--path needs on"; exit 1; }
(( ${#PATHS[@]} <= 10 )) || { echo "at most 10 extra folders"; exit 1; }
F="/usr/local/lsws/conf/vhosts/$D/vhost.conf"
[[ -f "$F" && ! -L "$F" ]] || { echo "no vhost.conf for $D"; exit 1; }
awk '/^context \/ \{/{c=1} c&&/rules[[:space:]]+<<<END_rules/{f=1} c&&/^\}/{c=0} END{exit !f}' "$F" || { echo "REFUSED: no rewrite rules block in context / of $F"; exit 1; }

cp -a "$F" "$F.pre-noexec"
# strip any existing block first (idempotent both directions)
awk '/^    # NOEXEC-START/{skip=1} !skip{print} /^    # NOEXEC-END/{skip=0}' "$F" > "$F.tmp" && cat "$F.tmp" > "$F" && rm -f "$F.tmp"

if [[ "$ACT" == on ]]; then
  ALT='(.*/)?wp-content/uploads|(.*/)?sites/[^/]+/files|\.well-known'
  for P in "${PATHS[@]}"; do ALT="$ALT|${P//./\\.}"; done
  # block via FILE (awk -v unescapes backslashes — see waf-shield.sh)
  {
    echo "    # NOEXEC-START (KarnakPanel: no PHP in upload folders — vhost-noexec.sh off to remove)"
    echo '    RewriteCond %{REQUEST_URI} \.(php[0-9]?|phtml|pht|phar|phps)(/|$) [NC]'
    echo "    RewriteCond %{REQUEST_URI} ^/+($ALT)/ [NC]"
    echo '    RewriteRule ^ - [F,L]'
    echo "    # NOEXEC-END"
  } > "$F.block"
  if grep -q '^    # CUSTOM-START' "$F"; then
    awk 'NR==FNR{b=b $0 "\n"; next} /^    # CUSTOM-START/ && !done{printf "%s", b; done=1} {print}' "$F.block" "$F" > "$F.tmp"
  else
    # hand-made / system vhost without the CUSTOM markers: top of context / rules
    awk 'NR==FNR{b=b $0 "\n"; next} {print} /^context \/ \{/{c=1} c && !done && /rules[[:space:]]+<<<END_rules/{printf "%s", b; done=1}' "$F.block" "$F" > "$F.tmp"
  fi
  cat "$F.tmp" > "$F" && rm -f "$F.tmp" "$F.block"
  [[ "$(grep -c '^    # NOEXEC-START' "$F")" == 1 ]] && grep -qF 'RewriteCond %{REQUEST_URI} \.(php[0-9]?|phtml|pht|phar|phps)(/|$) [NC]' "$F" \
    || { cp -a "$F.pre-noexec" "$F"; rm -f "$F.pre-noexec"; echo "insert failed — previous config kept"; exit 1; }
fi
rm -f "$F.pre-noexec"

# panel state follows the file (also run by hand). Best-effort: pre-migration
# DBs lack the columns; $D and the folders are regex-validated above.
PASS="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
if [[ -n "$PASS" ]]; then
  ON=0; [[ "$ACT" == on ]] && ON=1
  # nosemgrep: bash.lang.security.ifs-tampering.ifs-tampering -- IFS set inside a subshell to join with commas
  LIST="$(IFS=,; echo "${PATHS[*]}")"
  MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "UPDATE domains SET noexec=$ON, noexec_paths='$LIST' WHERE domain='$D';" 2>/dev/null || true
fi
if [[ "$NORELOAD" == "0" ]]; then
  /usr/local/lsws/bin/lswsctrl reload 2>/dev/null || systemctl reload lsws 2>/dev/null || true
fi
echo "php-in-uploads block $ACT for $D${PATHS[*]:+ (+ ${PATHS[*]})}"
