#!/usr/bin/env bash
# drupal-install.sh <domain> <owner> <subdir> <db> <dbuser> <dbpass> <title> <user_id> <domain_id> <url> <admin_email> <admin_user> <admin_pass>
# One-click Drupal 11 (CMS, standard profile) via Drupal's own programmatic
# installer — database, site and admin account are all created here, no web
# wizard. Domain ROOT only: Drupal always uses clean URLs, and the vhost's
# front controller sends unknown paths to the root index.php.
# Same contract/guards as joomla-install.sh: DETACHED background, tracking rows
# on success, refuses to clobber, dbpass panel-generated hex, pinned release
# with digest verified (supply chain), runs as the owner (never root) with the
# DOMAIN's PHP (8.3+ enforced by the panel; Drupal 11 floor). Options reach
# install_drupal() from a 600 file in a private dir: no password on argv.
# OLS ignores Drupal's .htaccess, so its protections (config/vendor/twig/yml,
# no PHP from files/, install.php closed) go into a managed vhost block BEFORE
# any file is unpacked; vhost-add.sh carries the block over re-renders and
# app-uninstall.sh strips it. A failed run removes everything it made.
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/secret-args.sh"; secret_argv "$@"; set -- "${SECRET_ARGV[@]}"  # @stdin secrets
. "$(dirname "$(readlink -f "$0")")/as-user.sh"   # customer-tree writes run as the customer
. "$(dirname "$(readlink -f "$0")")/db-caps.sh"  # DB_CAPS: per-user MariaDB caps (admin-set)
. "$(dirname "$(readlink -f "$0")")/php-versions.sh"  # php_known
D="${1:?}"; U="${2:?}"; SUB="${3:-}"; DB="${4:?}"; DBU="${5:?}"; DBP="${6:?}"; TITLE="${7:-Drupal}"
UIDN="${8:?}"; DID="${9:?}"; URL="${10:?}"; AM="${11:?}"; AU="${12:?}"; AP="${13:?}"
DR_VER="11.4.11"
DR_URL="https://ftp.drupal.org/files/projects/drupal-$DR_VER.tar.gz"
DR_SHA="f6935da6ddfb2b3bf5c79c5118018be91021f7d5e12901eed1a71de140708e13"
LOG="/var/log/karnakpanel/software.log"
. "$(dirname "$(readlink -f "$0")")/sw-state.sh"  # Software page progress: ok/fail per target
sw_begin drupal "$D" "$SUB" || exit 1
( ( exec >>"$SW_OUT" 2>&1
  echo "=== $(date -Is) drupal install for $D${SUB:+/$SUB} ==="
  [[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ && "$D" != *..* ]] || { echo "bad domain"; exit 1; }
  [[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad owner"; exit 1; }
  [[ -z "$SUB" ]] || { echo "Drupal installs at the domain root only"; exit 1; }
  [[ "$DB" =~ ^[a-z][a-z0-9_]{2,63}$ ]] || { echo "bad db"; exit 1; }
  [[ "$DBU" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad dbuser"; exit 1; }
  [[ "$DBP" =~ ^[a-f0-9]{12,64}$ ]] || { echo "db password must be panel-generated hex"; exit 1; }
  [[ "$UIDN" =~ ^[0-9]{1,10}$ && "$DID" =~ ^[0-9]{1,10}$ ]] || { echo "bad ids"; exit 1; }
  [[ "$URL" =~ ^https?://[a-z0-9.-]+\.[a-z]{2,}/?$ ]] || { echo "bad url"; exit 1; }
  [[ "$AM" =~ ^[^[:space:][:cntrl:]\"\\]{1,64}@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$ && "${#AM}" -le 190 ]] || { echo "bad admin email"; exit 1; }
  [[ "$AU" =~ ^[a-zA-Z0-9_.-]{3,32}$ ]] || { echo "bad admin user"; exit 1; }
  [[ "${#AP}" -ge 12 && "${#AP}" -le 128 && "$AP" != *[[:cntrl:]]* ]] || { echo "admin password: 12-128 characters, no control characters"; exit 1; }
  [[ "$TITLE" != *[[:cntrl:]]* ]] || TITLE="Drupal"
  id "$U" >/dev/null 2>&1 || { echo "no such user"; exit 1; }
  # the domain's own PHP runs the site, so it runs the installer too
  PV="$(mysql -uroot -N skypanel -e "SELECT php_version FROM domains WHERE id=$DID AND user_id=$UIDN AND domain='$D';" 2>/dev/null || true)"
  php_known "$PV" || { echo "domain PHP version unknown on this server"; exit 1; }
  [[ "$(printf '%s\n' "$PV" 8.3 | sort -V | head -1)" == 8.3 ]] || { echo "Drupal 11 needs PHP 8.3 or newer (domain is on $PV) — switch it on the PHP page first"; exit 1; }  # php-ver-ok: Drupal 11 floor
  PHPB="$(command -v "php$PV" || true)"
  [[ -n "$PHPB" ]] || { echo "php$PV CLI missing"; exit 1; }
  for x in gd pdo_mysql dom simplexml mbstring xml; do "$PHPB" -m 2>/dev/null | grep -qix "$x" || { echo "php$PV is missing the $x extension (Drupal needs it)"; exit 1; }; done
  DOC="/home/$U/$D/public_html"
  [[ -d "$DOC" && ! -L "$DOC" ]] || { echo "no docroot for $D (add the domain first)"; exit 1; }
  LEFT="$(as_user "$U" ls -A -- "$DOC" | grep -v -e '^index\.html$' -e '^\.well-known$' -e '^autodiscover$' || true)"
  [[ -z "$LEFT" ]] || { echo "folder not empty ($DOC) — refusing to overwrite"; exit 1; }
  VH="/usr/local/lsws/conf/vhosts/$D/vhost.conf"
  [[ -f "$VH" ]] || { echo "no vhost for $D — add the domain in the panel first (its web server entry is missing)"; exit 1; }
  TMP="$(mktemp -d)"; PRIV=""; MADE_DB=0; MADE_VH=0; DONE=0; TOP=()
  cleanup() {
    rm -rf "$TMP"
    [[ -n "$PRIV" ]] && as_user "$U" rm -rf -- "$PRIV"
    (( DONE )) && return 0
    # failed: nothing of this Drupal may stay web-reachable or half-made.
    # The installer leaves sites/default read-only: open it up first.
    local t
    as_user "$U" chmod -R u+w -- "$DOC/sites" 2>/dev/null || true
    for t in "${TOP[@]}"; do as_user "$U" rm -rf --one-file-system -- "$DOC/$t" 2>/dev/null || true; done
    (( MADE_DB )) && mysql -uroot <<<"DROP DATABASE IF EXISTS \`$DB\`; DROP USER IF EXISTS '$DBU'@'localhost';" 2>/dev/null || true
    if (( MADE_VH )); then
      sed -i "/# drupal BEGIN/,/# drupal END/d" "$VH"
      /usr/local/lsws/bin/lswsctrl restart >/dev/null 2>&1 || true
    fi
    return 0
  }
  trap cleanup EXIT
  curl -fsSL --max-time 600 "$DR_URL" -o "$TMP/d.tgz" || { echo "download failed"; exit 1; }
  echo "$DR_SHA  $TMP/d.tgz" | sha256sum -c - >/dev/null || { echo "checksum mismatch — delete and retry"; exit 1; }
  mkdir "$TMP/d"; tar xzf "$TMP/d.tgz" -C "$TMP/d" --strip-components=1 --no-same-owner
  [[ -f "$TMP/d/index.php" && -f "$TMP/d/core/includes/install.core.inc" && -f "$TMP/d/sites/default/default.settings.php" ]] || { echo "bad archive layout"; exit 1; }
  # OLS hardening FIRST (index.php wins over the coming-soon page the moment
  # it lands, and core/install.php must never be reachable). Mirrors Drupal's
  # .htaccess; /core/<file>.php (authorize, update via /update.php) stays open.
  if ! grep -q "# drupal BEGIN" "$VH"; then
    python3 - "$VH" <<'PYEOF'
import sys
p = sys.argv[1]
s = open(p).read()
block = r"""# drupal BEGIN (.htaccess protections for OLS — managed)
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteCond %{REQUEST_URI} ^/(core/)?(install|rebuild)\.php [NC,OR]
RewriteCond %{REQUEST_URI} ^/(vendor|private)(/|$) [NC,OR]
RewriteCond %{REQUEST_URI} ^/autoload(_runtime)?\.php [NC,OR]
RewriteCond %{REQUEST_URI} ^/(sites|modules|themes|profiles|recipes)/.*\.(php[0-9]?|phtml|phar|pht|inc)(/|$) [NC,OR]
RewriteCond %{REQUEST_URI} ^/core/[^/]+/.*\.php(/|$) [NC,OR]
RewriteCond %{REQUEST_URI} \.(engine|inc|install|make|module|profile|po|sh|[a-z]*sql|theme|twig|tpl(\.php)?|xtmpl|yml)(~|\.sw[op]|\.bak|\.orig|\.save)?$ [NC,OR]
RewriteCond %{REQUEST_URI} /(composer\.(json|lock)|web\.config|yarn\.lock|package(-lock)?\.json)$ [NC,OR]
RewriteCond %{REQUEST_URI} \.php(~|\.sw[op]|\.bak|\.orig|\.save)$ [NC,OR]
RewriteCond %{REQUEST_URI} /\.(?!well-known/)
RewriteRule ^ - [F,L]
# drupal END
"""
anchor = "rules                 <<<END_rules"
assert anchor in s, "rewrite anchor missing"
s = s.replace(anchor, anchor + "\n" + block.rstrip("\n"), 1)  # BEGIN..END delete restores it byte-exact
open(p, "w").write(s)
PYEOF
    MADE_VH=1
    /usr/local/lsws/bin/lswsctrl restart >/dev/null 2>&1 || true
    sleep 2
  fi
  while IFS= read -r t; do TOP+=("$t"); done < <(ls -A "$TMP/d")
  cp "$TMP/d/sites/default/default.settings.php" "$TMP/d/sites/default/settings.php"
  user_unpack "$U" "$TMP/d" "$DOC" || { echo "copy into $DOC failed (disk quota full?)"; exit 1; }
  db_new "$DB" "$DBU" "$DBP" || { echo "database $DB or db user $DBU already exists: not reusing it"; exit 1; }
  MADE_DB=1
  # options file + wrapper live in a private dir of the owner, outside the web root
  PRIV="$(as_user "$U" mktemp -d -p "/home/$U" .kp-drupal.XXXXXX)" || { echo "temp dir failed"; exit 1; }
  [[ "$PRIV" =~ ^/home/$U/\.kp-drupal\.[A-Za-z0-9]{6}$ ]] || { echo "temp dir failed"; exit 1; }
  # JSON-escape every value (titles/passwords can carry quotes and backslashes)
  J() { local s="${1//\\/\\\\}"; printf '%s' "${s//\"/\\\"}"; }
  user_put "$U" "$PRIV/o.json" 600 <<JSONEOF || { echo "install config write failed"; exit 1; }
{"title":"$(J "$TITLE")","user":"$(J "$AU")","pass":"$(J "$AP")","mail":"$(J "$AM")","db":"$DB","dbu":"$DBU","dbp":"$DBP"}
JSONEOF
  user_put "$U" "$PRIV/run.php" 600 <<'PHPEOF' || { echo "installer wrapper write failed"; exit 1; }
<?php
// KarnakPanel: run Drupal's own installer (as core's InstallCommand does,
// but on MariaDB) with its options read here, never from argv
$f = (string)getenv('KP_OPTS'); $doc = (string)getenv('KP_DOC');
$o = json_decode((string)@file_get_contents($f), true); @unlink($f);
if (!is_array($o) || !is_file("$doc/core/includes/install.core.inc")) { fwrite(STDERR, "bad installer options\n"); exit(1); }
chdir($doc);
$loader = require "$doc/autoload.php";
require_once "$doc/core/includes/install.core.inc";
$ns = 'Drupal\\mysql\\Driver\\Database\\mysql';
install_drupal($loader, [
  'interactive' => FALSE,
  'site_path' => 'sites/default',
  'parameters' => ['profile' => 'standard', 'langcode' => 'en'],
  'forms' => [
    'install_settings_form' => ['driver' => $ns, $ns => ['database' => $o['db'], 'username' => $o['dbu'], 'password' => $o['dbp'], 'host' => 'localhost', 'port' => '3306', 'prefix' => '']],
    'install_configure_form' => [
      'site_name' => $o['title'], 'site_mail' => $o['mail'],
      'account' => ['name' => $o['user'], 'mail' => $o['mail'], 'pass' => ['pass1' => $o['pass'], 'pass2' => $o['pass']]],
      'enable_update_status_module' => TRUE,
      'enable_update_status_emails' => NULL,  // NULL, not FALSE, unticks it (see InstallCommand)
    ],
  ],
]);
echo "Drupal installer finished\n";
PHPEOF
  RC=0
  as_user "$U" env HOME="/home/$U" KP_OPTS="$PRIV/o.json" KP_DOC="$DOC" "$PHPB" -d memory_limit=512M "$PRIV/run.php" >"$TMP/cli.out" 2>&1 || RC=1
  OUT="$(grep -vE '^\s*$' "$TMP/cli.out" | tail -4 || true)"
  OUT="${OUT//"$DBP"/<hidden>}"; OUT="${OUT//"$AP"/<hidden>}"; printf '%s\n' "$OUT"
  as_user "$U" grep -q "^\$databases\['default'\]\['default'\]" -- "$DOC/sites/default/settings.php" || RC=1
  [[ -n "$(mysql -uroot -N "$DB" -e "SELECT 1 FROM users_field_data WHERE uid=1 LIMIT 1;" 2>/dev/null)" ]] || RC=1
  [[ "$RC" == 0 ]] || { echo "drupal installer failed"; exit 1; }
  # host pinning (Drupal warns without it) + temp files off the shared /tmp
  as_user "$U" chmod u+w -- "$DOC/sites/default" "$DOC/sites/default/settings.php"
  as_user "$U" cat -- "$DOC/sites/default/settings.php" > "$TMP/settings.php" || { echo "cannot read settings.php"; exit 1; }
  RD="${D//./\\.}"
  { printf '\n// KarnakPanel\n$settings['"'"'trusted_host_patterns'"'"'] = ['"'"'^%s$'"'"', '"'"'^www\\.%s$'"'"'];\n' "$RD" "$RD"
    as_user "$U" test -d "/home/$U/.tmp" && printf '$settings['"'"'file_temp_path'"'"'] = '"'"'/home/%s/.tmp'"'"';\n' "$U"
  } >> "$TMP/settings.php"
  user_put "$U" "$DOC/sites/default/settings.php" 640 < "$TMP/settings.php" || { echo "settings.php write failed"; exit 1; }
  # Drupal 11.4's standard profile ships no content types: Article (tags,
  # comments, image) and Basic page are core recipes now. Not fatal: the site
  # works without them and they can be added under Structure → Content types.
  for r in page_content_type article_tags article_comment; do
    as_user "$U" env HOME="/home/$U" "$PHPB" -d memory_limit=512M "$DOC/core/scripts/drupal" recipe "$DOC/core/recipes/$r" >/dev/null 2>&1 \
      || echo "WARN: recipe $r not applied"
  done
  user_perms "$U" "$DOC"
  as_user "$U" chmod 640 -- "$DOC/sites/default/settings.php"
  as_user "$U" chmod 550 -- "$DOC/sites/default"
  as_user "$U" rm -f -- "$DOC/index.html"   # coming-soon page goes once Drupal is in
  DONE=1
  mysql -uroot skypanel -e "INSERT INTO mysql_dbs(user_id,db_name,db_user) VALUES($UIDN,'$DB','$DBU');" 2>/dev/null || echo "WARN: db tracking row not recorded"
  mysql -uroot skypanel -e "INSERT INTO site_installs(user_id,domain_id,app,path,url,version) VALUES($UIDN,$DID,'drupal','','$URL','$DR_VER');" 2>/dev/null || echo "WARN: install not registered"
  echo "installed Drupal $DR_VER at $URL (db $DB) — admin: $AU at ${URL%/}/user/login"
) & sw_wait $! ) >/dev/null 2>&1 &
echo "install started in background — watch Logs → software"
