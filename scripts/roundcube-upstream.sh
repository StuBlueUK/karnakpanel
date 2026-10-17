#!/usr/bin/env bash
# roundcube-upstream.sh — run webmail (:8091) on a pinned UPSTREAM Roundcube
# release instead of the Ubuntu package. Why: Ubuntu ships Roundcube in
# universe — jammy 1.5.0 never got a free security fix (CVE-2025-49113 RCE
# unpatched), noble 1.6.6 misses every 1.6.7+ fix. Spec:
# docs/roundcube-upstream-spec.md.
#
# Idempotent; called by the installer (roundcube module) and post-update.sh.
# On the pinned version already -> housekeeping only. Otherwise: download,
# sha256 + gpgv (both must pass), extract to its own versioned dir, back up
# the roundcube DB, initdb/updatedb, flip `current`, point the Webmail vhost
# at it, verify login page + static asset, and ROLL BACK (docroot, symlink,
# DB) on any failure. Only after a verified switch is the distro package
# removed (its gc/cleandb timers go with it; roundcube-gc.sh replaces them).
#
# SECURITY RELEASES: bump RC_VER + RC_SHA256 below and cut a panel release —
# unattended-upgrades no longer patches webmail.
set -euo pipefail
RC_VER="1.7.4"
RC_SHA256="2c6c878f0093f1bf7fb6086781d2dd9269d652c016b86939c157c5f1729139a2"
RC_URL="https://github.com/roundcube/roundcubemail/releases/download/$RC_VER/roundcubemail-$RC_VER-complete.tar.gz"
# Roundcube Developers <devs@roundcube.net>, primary F3E4C04BB3DB5D4215C45F7F5AB2BAA141C4F7D5;
# releases are signed by this subkey (gpgv VALIDSIG must name it):
RC_SIGNKEY="8970E37A698AF775D87D590DC2946A9609CD56B4"

BASE=/usr/local/skypanel/roundcube
CUR="$BASE/current"
ETC=/etc/karnak-roundcube
CONF="$ETC/config.inc.php"
TEMPD=/var/lib/karnak-roundcube/temp
LOGD=/var/log/roundcube
VHC=/usr/local/lsws/conf/vhosts/Webmail/vhost.conf
PHP=/usr/bin/php8.3  # php-ver-ok: webmail tier stays on 8.3
WU=karnak-webmail
HERE="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
[[ "$(id -u)" == "0" ]] || { echo "run as root"; exit 1; }
[[ "$RC_VER" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "bad RC_VER"; exit 1; }
[[ -f "$VHC" ]] || { echo "roundcube-upstream: no Webmail vhost — skipped"; exit 0; }
[[ -x "$PHP" ]] || { echo "roundcube-upstream: $PHP missing — cannot run webmail"; exit 1; }

docroot_ok() { grep -qE "^docRoot[[:space:]]+$CUR/public_html/?\$" "$VHC"; }

remove_distro_pkg() {
  # only once upstream is live + verified. conffiles stay (reference only).
  local pk=() p sim
  for p in roundcube roundcube-core roundcube-mysql roundcube-plugins roundcube-skin-larry roundcube-skin-classic; do
    dpkg-query -W -f='${Status}' "$p" 2>/dev/null | grep -q "ok installed" && pk+=("$p")
  done
  if (( ${#pk[@]} == 0 )); then
    # remove (not purge) leaves the distro gc/cleandb timers' enable links
    # dangling — drop only dangling ones so list-timers stays clean
    if find /etc/systemd/system -name 'roundcube-*' -xtype l 2>/dev/null | grep -q .; then
      find /etc/systemd/system -name 'roundcube-*' -xtype l -delete
      systemctl daemon-reload 2>/dev/null || true
    fi
    return 0
  fi
  sim="$(apt-get -s remove "${pk[@]}" 2>/dev/null | awk '/^Remv /{print $2}')"
  if grep -qv '^roundcube' <<<"$sim"; then
    echo "distro roundcube NOT removed: removal would also take: $(grep -v '^roundcube' <<<"$sim" | tr '\n' ' ')"; return 0
  fi
  if DEBIAN_FRONTEND=noninteractive apt-get remove -y -q "${pk[@]}" >/dev/null 2>&1; then
    echo "distro roundcube package removed (${pk[*]})"
    find /etc/systemd/system -name 'roundcube-*' -xtype l -delete 2>/dev/null || true
    systemctl daemon-reload 2>/dev/null || true
  else
    echo "distro roundcube removal failed (apt busy?) — retried next run"
  fi
  # apt postrm may poke apache2 — it must stay masked/stopped (OLS owns :80)
  systemctl stop apache2 2>/dev/null || true
  systemctl reset-failed apache2 2>/dev/null || true
}

prune_old() {  # keep current + the newest other version (rollback target)
  local live keep
  live="$(readlink -f "$CUR")"
  keep="$(ls -1dt "$BASE"/[0-9]* 2>/dev/null | grep -vxF "$live" | head -1 || true)"
  for d in "$BASE"/[0-9]*; do
    [[ -d "$d" ]] || continue
    [[ "$d" == "$live" || "$d" == "$keep" ]] && continue
    rm -rf -- "$d" && echo "pruned old webmail release $(basename "$d")"
  done
  rm -rf -- "$BASE"/.stage-* 2>/dev/null || true
}

ensure_runtime() {  # user, dirs, config perms (idempotent)
  local ent
  if ent="$(getent passwd "$WU")"; then
    # never adopt a stranger: an existing name must be the system user we made
    [[ "$(cut -d: -f3 <<<"$ent")" -lt 1000 && "$(cut -d: -f7 <<<"$ent")" == /usr/sbin/nologin ]] \
      || { echo "REFUSED: existing user $WU is not a nologin system user"; exit 1; }
  else
    useradd --system --user-group --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin "$WU"
  fi
  WG="$(id -gn "$WU")"
  mkdir -p "$TEMPD" "$LOGD" "$ETC"
  chown "$WU:" "$TEMPD"; chmod 700 "$TEMPD"
  chmod 755 "$LOGD"; setfacl -m "u:$WU:rwx" "$LOGD"; setfacl -d -m "u:$WU:rwx" "$LOGD"
  chown root:root "$ETC"; chmod 755 "$ETC"
  [[ -f "$CONF" ]] && { chown "root:$WG" "$CONF"; chmod 640 "$CONF"; }
  return 0
}

verify() {  # login form + a static.php asset must both serve
  local u html css
  for u in https://127.0.0.1:8091 http://127.0.0.1:8091; do
    html="$(curl -sk --max-time 20 "$u/" 2>/dev/null || true)"
    grep -q 'rcmloginuser' <<<"$html" || continue
    css="$(grep -oE 'static\.php/skins/elastic/[^"?]+\.css' <<<"$html" | head -1 || true)"
    [[ -n "$css" ]] || { echo "verify: login page has no static.php stylesheet"; return 1; }
    [[ "$(curl -sk --max-time 20 -o /dev/null -w '%{http_code}' "$u/$css")" == "200" ]] \
      || { echo "verify: $css not served"; return 1; }
    return 0
  done
  echo "verify: no Roundcube login form on :8091"; return 1
}

# ---------- already on the pinned version: housekeeping only ----------
if [[ "$(readlink -f "$CUR" 2>/dev/null)" == "$BASE/$RC_VER" ]] && docroot_ok && [[ -f "$CONF" ]]; then
  ensure_runtime
  remove_distro_pkg
  prune_old
  echo "roundcube-upstream: $RC_VER live"
  exit 0
fi

echo "roundcube-upstream: installing Roundcube $RC_VER"
[[ -s /root/.roundcube-dbpass ]] || { echo "no /root/.roundcube-dbpass — run the installer roundcube module"; exit 1; }
mysql -uroot -e "USE roundcube" 2>/dev/null || { echo "no roundcube database"; exit 1; }
command -v gpgv >/dev/null || { echo "gpgv missing (apt install gpgv)"; exit 1; }

# ---------- fetch + verify ----------
mkdir -p "$BASE"; chmod 755 "$BASE"
STAGE="$(mktemp -d "$BASE/.stage-XXXXXX")"
DUMP=""; ROLLBACK_READY=0; OLD_DOCROOT=""; OLD_CUR=""; DB_TOUCHED=0
cleanup() { rm -rf -- "$STAGE"; }
trap cleanup EXIT
curl -fsSL --max-time 300 -o "$STAGE/rc.tgz" "$RC_URL"
curl -fsSL --max-time 60 -o "$STAGE/rc.tgz.asc" "$RC_URL.asc"
echo "$RC_SHA256  $STAGE/rc.tgz" | sha256sum -c --quiet - || { echo "sha256 MISMATCH — aborted"; exit 1; }
base64 -d > "$STAGE/rc-key.gpg" <<'KEYEOF'
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
udHVyZ4RHu3l5MAW3xM5
KEYEOF
gpgv --status-fd 3 --keyring "$STAGE/rc-key.gpg" "$STAGE/rc.tgz.asc" "$STAGE/rc.tgz" 3>"$STAGE/gpg.status" 2>/dev/null \
  && grep -q "^\[GNUPG:\] VALIDSIG $RC_SIGNKEY " "$STAGE/gpg.status" \
  || { echo "signature check FAILED — aborted"; exit 1; }

# ---------- extract ----------
mkdir "$STAGE/x"
tar xzf "$STAGE/rc.tgz" -C "$STAGE/x" --no-same-owner
SRC="$STAGE/x/roundcubemail-$RC_VER"
[[ -f "$SRC/public_html/index.php" && -f "$SRC/bin/updatedb.sh" ]] || { echo "unexpected tarball layout"; exit 1; }
rm -rf -- "$SRC/installer" "$SRC/public_html/installer.php"
cat > "$SRC/config/config.inc.php" <<EOF
<?php
// KarnakPanel: the real config lives outside the release dir so upgrades
// never touch it (managed by roundcube-upstream.sh).
include '$CONF';
EOF
chown -R root:root "$SRC"; chmod -R go-w "$SRC"
# temp/ + logs/ in the tree are unused (config points elsewhere) — keep them unwritable
rm -rf -- "$BASE/$RC_VER"
mv "$SRC" "$BASE/$RC_VER"

# ---------- config (first time only — never overwritten) ----------
ensure_runtime
if [[ ! -f "$CONF" ]]; then
  DESK=""
  if [[ -f /etc/roundcube/config.inc.php ]]; then
    # keep the distro des_key: existing sessions/encrypted prefs stay valid
    DESK="$(grep -oE "^\\\$config\[['\"]des_key['\"]\][[:space:]]*=[[:space:]]*['\"][^'\"]{24}['\"]" /etc/roundcube/config.inc.php | tail -1 | sed -E "s/.*['\"]([^'\"]{24})['\"]\$/\1/")"
  fi
  [[ "$DESK" =~ ^[A-Za-z0-9+/=]{24}$ ]] || DESK="$(openssl rand -base64 18 | head -c 24)"
  RCP="$(cat /root/.roundcube-dbpass)"
  [[ "$RCP" =~ ^[A-Za-z0-9]+$ ]] || { echo "unexpected characters in roundcube DB password"; exit 1; }
  T="$(mktemp "$ETC/.conf-XXXXXX")"
  cat > "$T" <<EOF
<?php
// KarnakPanel webmail config — written once by roundcube-upstream.sh, kept
// across Roundcube upgrades. Defaults: $CUR/config/defaults.inc.php
\$config['db_dsnw'] = 'mysql://roundcube:$RCP@localhost/roundcube';
\$config['imap_host'] = 'localhost:143';
\$config['smtp_host'] = 'localhost:25';
\$config['smtp_user'] = '%u';
\$config['smtp_pass'] = '%p';
\$config['des_key'] = '$DESK';
\$config['product_name'] = 'KarnakPanel Webmail';
\$config['skin'] = 'elastic';
\$config['plugins'] = [];
\$config['support_url'] = '';
\$config['temp_dir'] = '$TEMPD/';
\$config['log_dir'] = '$LOGD/';
\$config['enable_installer'] = false;
EOF
  "$PHP" -l "$T" >/dev/null || { rm -f "$T"; echo "generated config failed php -l"; exit 1; }
  chown "root:$WG" "$T"; chmod 640 "$T"; mv "$T" "$CONF"
  echo "wrote $CONF"
fi

# ---------- rollback (armed before anything live changes) ----------
OLD_DOCROOT="$(grep -m1 -E '^docRoot[[:space:]]' "$VHC" || true)"
OLD_CUR="$(readlink "$CUR" 2>/dev/null || true)"
cp -a "$VHC" "$STAGE/vhost.conf.bak"
rollback() {
  echo "ROLLING BACK webmail to the previous state"
  cp -a "$STAGE/vhost.conf.bak" "$VHC"
  if [[ -n "$OLD_CUR" ]]; then ln -sfn "$OLD_CUR" "$CUR.new" && mv -T "$CUR.new" "$CUR"; else rm -f "$CUR"; fi
  if (( DB_TOUCHED )) && [[ -n "$DUMP" ]]; then
    zcat "$DUMP" | mysql -uroot roundcube && echo "roundcube DB restored from $DUMP"
  fi
  /usr/local/lsws/bin/lswsctrl restart >/dev/null 2>&1 || true
}
fail() { echo "FAILED: $*"; rollback; exit 1; }

# ---------- database ----------
DUMP="/root/roundcube-db-$(date +%Y%m%d%H%M%S).sql.gz"
( umask 077; mysqldump -uroot --single-transaction roundcube | gzip > "$DUMP" ) || fail "DB backup"
DB_TOUCHED=1
if mysql -uroot roundcube -Nse "SHOW TABLES LIKE 'users'" | grep -qx users; then
  ( cd "$BASE/$RC_VER" && "$PHP" bin/updatedb.sh --package=roundcube --dir="$BASE/$RC_VER/SQL" ) || fail "updatedb"
else
  ( cd "$BASE/$RC_VER" && "$PHP" bin/initdb.sh --dir="$BASE/$RC_VER/SQL" ) || fail "initdb"
fi

# ---------- switch ----------
ln -sfn "$RC_VER" "$CUR.new" && mv -T "$CUR.new" "$CUR"
python3 - "$VHC" "$CUR/public_html/" <<'PYEOF' || fail "vhost edit"
import re, sys
path, root = sys.argv[1], sys.argv[2]
s = open(path).read()
s, n = re.subn(r'(?m)^docRoot[ \t]+\S+[ \t]*$', 'docRoot                   ' + root, s, count=1)
if n != 1: sys.exit("no docRoot line")
# the panel-style front-controller catch-all rewrites static.php/<asset>
# (no such file) to index.php -> unstyled login. Roundcube needs no catch-all.
old = "RewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule ^(.*)$ index.php [L]\n"
s = s.replace(old, "RewriteRule ^favicon\\.ico$ static.php/skins/elastic/images/favicon.ico [L]\n")
open(path, 'w').write(s)
PYEOF
# pool + ACLs (creates/points the karnak-webmail FPM pool if not yet done)
"$HERE/app-isolate.sh" >/dev/null || fail "app-isolate"
/usr/local/lsws/bin/lswsctrl restart >/dev/null 2>&1 || true
ok=0
for i in 1 2 3 4 5 6; do sleep 5; if verify; then ok=1; break; fi; done
(( ok )) || fail "webmail did not come up on $RC_VER"
echo "Roundcube $RC_VER live (DB backup: $DUMP)"

# ---------- after a verified switch ----------
remove_distro_pkg
prune_old
"$HERE/integrity-check.sh" --baseline >/dev/null 2>&1 || true
exit 0
