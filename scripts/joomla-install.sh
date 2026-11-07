#!/usr/bin/env bash
# joomla-install.sh <domain> <owner> <subdir> <db> <dbuser> <dbpass> <title> <user_id> <domain_id> <url> <admin_email> <admin_user> <admin_pass>
# One-click Joomla 6 (CMS) via its CLI installer — database, site and Super
# User are all created here, no web wizard needed. Root or a subfolder.
# Same contract/guards as flarum-install.sh: DETACHED background, tracking rows
# on success, refuses to clobber, dbpass panel-generated hex, admin 12+ chars
# (Joomla's own minimum). Pinned release, digest verified (supply chain).
# Runs the CLI as the owner (never root) with the DOMAIN's PHP (8.3+ enforced
# by the panel; Joomla 6 floor). The CLI only takes options on its command
# line, which every account can read in /proc — so a small wrapper loads them
# from a 600 file in a private dir and hands them to the CLI inside PHP: no
# password is ever on a command line. A failed run removes everything it
# unpacked and the database it made (a half-installed Joomla would leave its
# web installer open to anyone).
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/secret-args.sh"; secret_argv "$@"; set -- "${SECRET_ARGV[@]}"  # @stdin secrets
. "$(dirname "$(readlink -f "$0")")/as-user.sh"   # customer-tree writes run as the customer
. "$(dirname "$(readlink -f "$0")")/db-caps.sh"  # DB_CAPS: per-user MariaDB caps (admin-set)
. "$(dirname "$(readlink -f "$0")")/php-versions.sh"  # php_known
D="${1:?}"; U="${2:?}"; SUB="${3:-}"; DB="${4:?}"; DBU="${5:?}"; DBP="${6:?}"; TITLE="${7:-Joomla}"
UIDN="${8:?}"; DID="${9:?}"; URL="${10:?}"; AM="${11:?}"; AU="${12:?}"; AP="${13:?}"
J_VER="6.2.0"
J_URL="https://github.com/joomla/joomla-cms/releases/download/$J_VER/Joomla_$J_VER-Stable-Full_Package.tar.gz"
J_SHA="f7a4c3962c4a8682f5847ea9d47d2848f6eb475109805935c9e19a5c6511829d"
LOG="/var/log/karnakpanel/software.log"
. "$(dirname "$(readlink -f "$0")")/sw-state.sh"  # Software page progress: ok/fail per target
sw_begin joomla "$D" "$SUB" || exit 1
( ( exec >>"$SW_OUT" 2>&1
  echo "=== $(date -Is) joomla install for $D${SUB:+/$SUB} ==="
  [[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ && "$D" != *..* ]] || { echo "bad domain"; exit 1; }
  [[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad owner"; exit 1; }
  [[ "$SUB" =~ ^[a-z0-9_-]{0,64}$ ]] || { echo "bad subdir"; exit 1; }
  [[ "$DB" =~ ^[a-z][a-z0-9_]{2,63}$ ]] || { echo "bad db"; exit 1; }
  [[ "$DBU" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad dbuser"; exit 1; }
  [[ "$DBP" =~ ^[a-f0-9]{12,64}$ ]] || { echo "db password must be panel-generated hex"; exit 1; }
  [[ "$UIDN" =~ ^[0-9]{1,10}$ && "$DID" =~ ^[0-9]{1,10}$ ]] || { echo "bad ids"; exit 1; }
  [[ "$URL" =~ ^https?://[a-z0-9.-]+\.[a-z]{2,}(/[a-z0-9_-]{1,64})?/?$ ]] || { echo "bad url"; exit 1; }
  [[ "$AM" =~ ^[^[:space:][:cntrl:]\"\\]{1,64}@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$ && "${#AM}" -le 190 ]] || { echo "bad admin email"; exit 1; }
  [[ "$AU" =~ ^[a-zA-Z0-9_.-]{3,32}$ ]] || { echo "bad admin user"; exit 1; }
  [[ "${#AP}" -ge 12 && "${#AP}" -le 128 && "$AP" != *[[:cntrl:]]* ]] || { echo "admin password: 12-128 characters, no control characters"; exit 1; }
  [[ "$AP" != [[:space:]]* && "$AP" != *[[:space:]] ]] || { echo "admin password: no spaces at the start or end (Joomla refuses them)"; exit 1; }
  [[ "$TITLE" != *[[:cntrl:]]* ]] || TITLE="Joomla"
  id "$U" >/dev/null 2>&1 || { echo "no such user"; exit 1; }
  # the domain's own PHP runs the site, so it runs the installer too
  PV="$(mysql -uroot -N skypanel -e "SELECT php_version FROM domains WHERE id=$DID AND user_id=$UIDN AND domain='$D';" 2>/dev/null || true)"
  php_known "$PV" || { echo "domain PHP version unknown on this server"; exit 1; }
  [[ "$(printf '%s\n' "$PV" 8.3 | sort -V | head -1)" == 8.3 ]] || { echo "Joomla 6 needs PHP 8.3 or newer (domain is on $PV) — switch it on the PHP page first"; exit 1; }  # php-ver-ok: Joomla 6 floor
  PHPB="$(command -v "php$PV" || true)"
  [[ -n "$PHPB" ]] || { echo "php$PV CLI missing"; exit 1; }
  ROOT="/home/$U/$D/public_html"
  DOC="$ROOT${SUB:+/$SUB}"
  [[ -d "$ROOT" && ! -L "$ROOT" ]] || { echo "no docroot for $D (add the domain first)"; exit 1; }
  as_user "$U" mkdir -p -- "$DOC" || { echo "can't create $DOC"; exit 1; }
  LEFT="$(as_user "$U" ls -A -- "$DOC" | grep -v -e '^index\.html$' -e '^\.well-known$' -e '^autodiscover$' || true)"
  [[ -z "$LEFT" ]] || { echo "folder not empty ($DOC) — refusing to overwrite"; exit 1; }
  TMP="$(mktemp -d)"; PRIV=""; MADE_DB=0; DONE=0; TOP=()
  cleanup() {
    rm -rf "$TMP"
    [[ -n "$PRIV" ]] && as_user "$U" rm -rf -- "$PRIV"
    (( DONE )) && return 0
    # failed: nothing of this Joomla may stay web-reachable or half-made
    local t
    for t in "${TOP[@]}" configuration.php; do as_user "$U" rm -rf --one-file-system -- "$DOC/$t" 2>/dev/null || true; done
    if [[ -n "$SUB" ]]; then as_user "$U" rmdir -- "$DOC" 2>/dev/null || true; fi
    (( MADE_DB )) && mysql -uroot <<<"DROP DATABASE IF EXISTS \`$DB\`; DROP USER IF EXISTS '$DBU'@'localhost';" 2>/dev/null || true
    return 0
  }
  trap cleanup EXIT
  curl -fsSL --max-time 600 "$J_URL" -o "$TMP/j.tgz" || { echo "download failed"; exit 1; }
  echo "$J_SHA  $TMP/j.tgz" | sha256sum -c - >/dev/null || { echo "checksum mismatch — delete and retry"; exit 1; }
  mkdir "$TMP/j"; tar xzf "$TMP/j.tgz" -C "$TMP/j" --no-same-owner
  [[ -f "$TMP/j/index.php" && -f "$TMP/j/installation/joomla.php" ]] || { echo "bad archive layout"; exit 1; }
  while IFS= read -r t; do TOP+=("$t"); done < <(ls -A "$TMP/j")
  user_unpack "$U" "$TMP/j" "$DOC" || { echo "copy into $DOC failed (disk quota full?)"; exit 1; }
  db_new "$DB" "$DBU" "$DBP" || { echo "database $DB or db user $DBU already exists: not reusing it"; exit 1; }
  MADE_DB=1
  # options file + wrapper live in a private dir of the owner, outside the web root
  PRIV="$(as_user "$U" mktemp -d -p "/home/$U" .kp-joomla.XXXXXX)" || { echo "temp dir failed"; exit 1; }
  [[ "$PRIV" =~ ^/home/$U/\.kp-joomla\.[A-Za-z0-9]{6}$ ]] || { echo "temp dir failed"; exit 1; }
  # JSON-escape every value (titles/passwords can carry quotes and backslashes)
  J() { local s="${1//\\/\\\\}"; printf '%s' "${s//\"/\\\"}"; }
  PFX="j$(openssl rand -hex 2)_"
  user_put "$U" "$PRIV/o.json" 600 <<JSONEOF || { echo "install config write failed"; exit 1; }
{"site-name":"$(J "$TITLE")","admin-user":"$(J "$AU")","admin-username":"$(J "$AU")","admin-password":"$(J "$AP")","admin-email":"$(J "$AM")","db-type":"mysqli","db-host":"localhost","db-user":"$DBU","db-pass":"$DBP","db-name":"$DB","db-prefix":"$PFX"}
JSONEOF
  user_put "$U" "$PRIV/run.php" 600 <<'PHPEOF' || { echo "installer wrapper write failed"; exit 1; }
<?php
// KarnakPanel: hand the Joomla CLI its options inside PHP, never on argv
$f = (string)getenv('KP_OPTS'); $doc = (string)getenv('KP_DOC');
$o = json_decode((string)@file_get_contents($f), true); @unlink($f);
if (!is_array($o) || !is_file("$doc/installation/joomla.php")) { fwrite(STDERR, "bad installer options\n"); exit(1); }
$a = ["$doc/installation/joomla.php", 'install', '-n'];
foreach ($o as $k => $v) $a[] = "--$k=$v";
$_SERVER['argv'] = $argv = $a; $_SERVER['argc'] = $argc = count($a);
chdir($doc);
require "$doc/installation/joomla.php";
PHPEOF
  RC=0
  as_user "$U" env HOME="/home/$U" KP_OPTS="$PRIV/o.json" KP_DOC="$DOC" "$PHPB" "$PRIV/run.php" >"$TMP/cli.out" 2>&1 || RC=1
  grep -vE '^\s*$' "$TMP/cli.out" | tail -4 || true
  as_user "$U" test -f "$DOC/configuration.php" || RC=1
  ! as_user "$U" test -e "$DOC/installation" || RC=1
  [[ "$RC" == 0 ]] || { echo "joomla installer failed"; exit 1; }
  user_perms "$U" "$DOC"
  as_user "$U" chmod 640 -- "$DOC/configuration.php"
  # the coming-soon page stays up until Joomla is in (a failed root run keeps it)
  if [[ -z "$SUB" ]]; then as_user "$U" rm -f -- "$DOC/index.html"; fi
  DONE=1
  mysql -uroot skypanel -e "INSERT INTO mysql_dbs(user_id,db_name,db_user) VALUES($UIDN,'$DB','$DBU');" 2>/dev/null || echo "WARN: db tracking row not recorded"
  mysql -uroot skypanel -e "INSERT INTO site_installs(user_id,domain_id,app,path,url,version) VALUES($UIDN,$DID,'joomla','$SUB','$URL','$J_VER');" 2>/dev/null || echo "WARN: install not registered"
  echo "installed Joomla $J_VER at $URL (db $DB) — admin: $AU at ${URL%/}/administrator/"
) & sw_wait $! ) >/dev/null 2>&1 &
echo "install started in background — watch Logs → software"
