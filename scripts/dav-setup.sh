#!/usr/bin/env bash
# dav-setup.sh [converge|reconcile] — calendar + contacts sync (CalDAV/CardDAV)
# for every local mailbox: https://<panel host>:8091/dav/, login = full
# address + mailbox password. SabreDAV 4.7.1, vendored + pinned in
# app/Dav/vendor (composer.lock).
# SECURITY RELEASES: bump sabre/* in app/Dav/composer.json, re-run composer
# (--no-dev, classmap-authoritative), commit vendor/ + lock, cut a panel
# release — nothing else updates this code.
#
# converge (default; installer + host-converge, idempotent, quiet when nothing
# changes):
#   - system user karnak-dav; php8.3-fpm pool of its own (not webmail's uid,
#     never the panel's), open_basedir to its code/config/log
#   - code: a COPY of the live app/Dav into /usr/local/skypanel/dav/<hash>,
#     `current` flipped atomically, pool reloaded (no stale opcache after a
#     self-update), last two copies kept
#   - database karnakdav + user karnakdav: ALL on karnakdav, column-level
#     SELECT on the few skypanel columns auth needs (no other panel data)
#   - /etc/karnak-dav/config.php root:karnak-dav 640 (secret = /root/.karnakdav-dbpass)
#   - Webmail vhost: marked block = extprocessor + /dav/ context (OLS sends no
#     SCRIPT_FILENAME for an fcgi context: a rewrite env sets it) + the
#     /.well-known/caldav|carddav redirects phones probe; verified by curl,
#     rolled back on failure
#   - fail2ban [karnak-dav]: wrong DAV passwords (auth.log), port 8091 only,
#     high threshold (a phone with a stale password retries forever — it must
#     not ban an office IP off webmail for long)
# reconcile (daily cron): purge calendars/contacts of deleted mailboxes.
#
# NOT covered (by design, v1): DAV data is outside disk quotas (capped per
# mailbox instead: app/Dav/lib/Store.php), outside per-account backups and
# migrations (only in the nightly all-databases dump), and tied to the panel
# host name — changing it breaks every configured phone.
set -euo pipefail
[[ "$(id -u)" == "0" ]] || { echo "run as root"; exit 1; }
MODE="${1:-converge}"
[[ "$MODE" =~ ^(converge|reconcile)$ ]] || { echo "usage: dav-setup.sh [converge|reconcile]"; exit 1; }
PV=8.3   # php-ver-ok: webmail tier stays on 8.3
PHP=/usr/bin/php$PV
SRC=/usr/local/skypanel/panel/app/Dav
BASE=/usr/local/skypanel/dav
CUR="$BASE/current"
ETC=/etc/karnak-dav
CONF="$ETC/config.php"
LOGD=/var/log/karnak-dav
PASSF=/root/.karnakdav-dbpass
POOL=/etc/php/$PV/fpm/pool.d/karnak-dav.conf
VHC=/usr/local/lsws/conf/vhosts/Webmail/vhost.conf
U=karnak-dav
DB=karnakdav
HERE="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"

if [[ "$MODE" == reconcile ]]; then
  [[ -f "$CUR/reconcile.php" && -f "$CONF" ]] || exit 0
  exec "$PHP" "$CUR/reconcile.php"
fi

[[ -f "$VHC" ]] || { echo "dav-setup: no Webmail vhost — skipped"; exit 0; }
# vendor: a directory (fresh install) or vendor.tar.gz (panel self-update package)
[[ -f "$SRC/server.php" && ( -f "$SRC/vendor/autoload.php" || -f "$SRC/vendor.tar.gz" ) ]] || { echo "dav-setup: $SRC missing — skipped"; exit 0; }
[[ -x "$PHP" && -d /etc/php/$PV/fpm/pool.d ]] || { echo "dav-setup: php$PV-fpm missing — skipped"; exit 0; }
MODS="$("php-fpm$PV" -m 2>/dev/null | tr 'A-Z' 'a-z')"
MISSING=""
for m in dom xml xmlreader xmlwriter simplexml mbstring ctype iconv pdo_mysql json; do
  grep -qx "$m" <<<"$MODS" || MISSING+="$m "
done
[[ -z "$MISSING" ]] || { echo "dav-setup: php$PV-fpm lacks ${MISSING% } — skipped"; exit 0; }

CH_FPM=0; CH_OLS=0; CH_F2B=0; SAID=""
say() { echo "dav-setup: $*"; SAID=1; }

# ---- user ----
if ent="$(getent passwd "$U")"; then
  [[ "$(cut -d: -f3 <<<"$ent")" -lt 1000 && "$(cut -d: -f7 <<<"$ent")" == /usr/sbin/nologin ]] \
    || { echo "dav-setup: REFUSED: existing user $U is not a nologin system user"; exit 1; }
else
  useradd --system --user-group --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin "$U"
  say "created system user $U"
fi

# ---- code: own versioned copy (content hash), atomic flip ----
HASH="$(cd "$SRC" && find . -type f -print0 | LC_ALL=C sort -z | xargs -0 sha256sum | sha256sum | cut -c1-12)"
DEST="$BASE/$HASH"
install -d -m 755 -o root -g root "$BASE"
if [[ ! -f "$DEST/server.php" ]]; then
  T="$(mktemp -d "$BASE/.stage.XXXXXX")"
  cp -a "$SRC/." "$T/"
  if [[ -f "$T/vendor.tar.gz" ]]; then
    rm -rf "$T/vendor"
    tar --no-same-owner -xzf "$T/vendor.tar.gz" -C "$T" vendor
    rm -f "$T/vendor.tar.gz"
    [[ -f "$T/vendor/autoload.php" ]] || { rm -rf "$T"; echo "dav-setup: vendor.tar.gz has no autoload.php — refusing"; exit 1; }
  fi
  chown -R root:root "$T"; find "$T" -type d -exec chmod 755 {} +; find "$T" -type f -exec chmod 644 {} +
  rm -rf "$DEST"; mv "$T" "$DEST"
fi
if [[ "$(readlink "$CUR" 2>/dev/null)" != "$HASH" ]]; then
  ln -sfn "$HASH" "$BASE/.current.new" && mv -T "$BASE/.current.new" "$CUR"
  CH_FPM=1; say "code -> $HASH"
fi
# keep current + one previous
for old in $( (ls -1t "$BASE" 2>/dev/null || true) | grep -E '^[0-9a-f]{12}$' | grep -vx "$HASH" | tail -n +2 || true); do
  [[ "$old" =~ ^[0-9a-f]{12}$ ]] && rm -rf "${BASE:?}/$old"
done
find "$BASE" -maxdepth 1 -name '.stage.*' -mmin +60 -exec rm -rf {} + 2>/dev/null || true

# ---- database (password via stdin only, never argv) ----
if [[ ! -s "$PASSF" ]]; then
  ( umask 077; openssl rand -hex 24 > "$PASSF" ); say "generated DB password ($PASSF)"
fi
chmod 600 "$PASSF"
DBP="$(cat "$PASSF")"
[[ "$DBP" =~ ^[0-9a-f]{48}$ ]] || { echo "dav-setup: $PASSF malformed"; exit 1; }
mysql -uroot <<SQL
CREATE DATABASE IF NOT EXISTS $DB CHARACTER SET utf8mb4;
CREATE USER IF NOT EXISTS '$DB'@'localhost' IDENTIFIED BY '$DBP';
ALTER USER '$DB'@'localhost' IDENTIFIED BY '$DBP';
GRANT ALL PRIVILEGES ON $DB.* TO '$DB'@'localhost';
GRANT SELECT (id, user_id, domain_id, localpart, password_hash) ON skypanel.email_accounts TO '$DB'@'localhost';
GRANT SELECT (id, domain, mail_handling) ON skypanel.domains TO '$DB'@'localhost';
GRANT SELECT (id, status) ON skypanel.users TO '$DB'@'localhost';
FLUSH PRIVILEGES;
SQL
mysql -uroot "$DB" < "$CUR/schema.sql"

# ---- config ----
install -d -m 750 -o root -g "$U" "$ETC"
T="$(mktemp)"
cat > "$T" <<EOF
<?php
// managed by dav-setup.sh — DAV database credentials (secret: $PASSF)
return [
    'dsn'  => 'mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=$DB;charset=utf8mb4',
    'user' => '$DB',
    'pass' => '$DBP',
];
EOF
if ! cmp -s "$T" "$CONF" 2>/dev/null; then
  install -m 640 -o root -g "$U" "$T" "$CONF"; CH_FPM=1; say "config written"
fi
rm -f "$T"; chown root:"$U" "$CONF"; chmod 640 "$CONF"

# ---- logs (auth.log is watched by fail2ban: append-only, rotated by logrotate) ----
install -d -m 750 -o "$U" -g "$U" "$LOGD"
for f in auth.log php-error.log; do
  [[ -f "$LOGD/$f" ]] || install -m 640 -o "$U" -g "$U" /dev/null "$LOGD/$f"
done
LR=/etc/logrotate.d/karnak-dav
LRW="# managed by dav-setup.sh
$LOGD/*.log {
  weekly
  rotate 8
  compress
  delaycompress
  missingok
  notifempty
  create 0640 $U $U
  su $U $U
}"
[[ "$(cat "$LR" 2>/dev/null)" == "$LRW" ]] || { printf '%s\n' "$LRW" > "$LR"; chmod 644 "$LR"; }

# ---- php-fpm pool ----
T="$(mktemp)"
cat > "$T" <<EOF
; KarnakPanel CalDAV/CardDAV pool (managed by dav-setup.sh — do not hand-edit)
; ';' comments only: '#' lines take down ALL of FPM
[$U]
user = $U
group = $U
listen = /run/php/$U.sock
listen.owner = nobody
listen.group = nogroup
listen.mode = 0660
; a phone's sync is a burst of requests, each a bcrypt check
listen.backlog = 64
pm = ondemand
pm.max_children = 8
pm.process_idle_timeout = 30s
request_terminate_timeout = 120s
php_admin_value[memory_limit] = 192M
php_admin_value[upload_max_filesize] = 4M
php_admin_value[post_max_size] = 4M
php_admin_value[open_basedir] = $BASE/:$ETC/:$LOGD/:/tmp/
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec,show_source
php_admin_value[error_log] = $LOGD/php-error.log
php_admin_flag[log_errors] = on
php_admin_flag[display_errors] = off
php_admin_flag[expose_php] = off
EOF
if ! cmp -s "$T" "$POOL" 2>/dev/null; then
  OLDP=""; [[ -f "$POOL" ]] && { OLDP="$(mktemp)"; cp -a "$POOL" "$OLDP"; }
  install -m 644 -o root -g root "$T" "$POOL"
  if ! "php-fpm$PV" -t >/dev/null 2>&1; then
    if [[ -n "$OLDP" ]]; then cat "$OLDP" > "$POOL"; else rm -f "$POOL"; fi
    rm -f "$T" "$OLDP"; echo "dav-setup: FPM config test failed — pool not installed"; exit 1
  fi
  rm -f "$OLDP"; CH_FPM=1; say "pool $U written"
fi
rm -f "$T"
if (( CH_FPM )); then
  systemctl reload "php$PV-fpm" || systemctl restart "php$PV-fpm"
  for _ in 1 2 3 4 5 6 7 8 9 10; do [[ -S /run/php/$U.sock ]] && break; sleep 0.5; done
  [[ -S /run/php/$U.sock ]] || { echo "dav-setup: pool socket did not appear"; exit 1; }
fi

# ---- Webmail vhost block ----
BLOCK="$(cat <<'EOF'
# karnak-dav BEGIN (managed by dav-setup.sh — calendar/contacts sync)
extprocessor fpm-karnak-dav {
  type                    fcgi
  address                 uds:///run/php/karnak-dav.sock
  maxConns                8
  initTimeout             120
  retryTimeout            5
  persistConn             0
  respBuffer              0
  autoStart               0
  backlog                 64
  instances               1
}

context /dav/ {
  type                    fcgi
  handler                 fpm-karnak-dav
  addDefaultCharset       off
  rewrite  {
    enable                1
    rules                 <<<END_rules
RewriteRule .* - [E=SCRIPT_FILENAME:/usr/local/skypanel/dav/current/server.php]
END_rules
  }
}

context /.well-known/caldav {
  type                    redirect
  externalRedirect        1
  statusCode              301
  location                /dav/
}

context /.well-known/carddav {
  type                    redirect
  externalRedirect        1
  statusCode              301
  location                /dav/
}
# karnak-dav END
EOF
)"
HAVE="$(sed -n '/^# karnak-dav BEGIN/,/^# karnak-dav END/p' "$VHC")"
if [[ "$HAVE" != "$BLOCK" ]]; then
  BAK="$(mktemp)"; cp -a "$VHC" "$BAK"
  python3 - "$VHC" "$BLOCK" <<'PYEOF'
import re, sys
p, block = sys.argv[1], sys.argv[2]
s = open(p).read()
s = re.sub(r'\n*# karnak-dav BEGIN.*?# karnak-dav END\n?', '\n', s, flags=re.S)
s = s.rstrip('\n') + '\n\n' + block + '\n'
open(p, 'w').write(s)
PYEOF
  CH_OLS=1; say "Webmail vhost: /dav/ block written"
fi
lsws_restart() { /usr/local/lsws/bin/lswsctrl restart >/dev/null 2>&1 || true; sleep 3; }
probe() { curl -sk -o /dev/null -m 10 -w '%{http_code}' -X "$1" "$2://127.0.0.1:8091$3" 2>/dev/null || true; }
dav_ok() {
  # TLS listener: unauthenticated PROPFIND reaches SabreDAV = 401. A box whose
  # :8091 is still plain HTTP (no cert yet): server.php refuses with 403.
  [[ "$(probe GET https /)" == 200 && "$(probe PROPFIND https /dav/)" == 401 ]] && return 0
  [[ "$(probe GET http /)" == 200 && "$(probe PROPFIND http /dav/)" == 403 ]]
}
if (( CH_OLS )); then
  lsws_restart
  if ! dav_ok && { sleep 5; ! dav_ok; }; then
    cat "$BAK" > "$VHC"; rm -f "$BAK"; lsws_restart
    echo "dav-setup: /dav/ did not answer 401 (or webmail broke) — vhost restored"; exit 1
  fi
  rm -f "$BAK"
fi

# ---- fail2ban jail ----
FD=/etc/fail2ban/filter.d; J=/etc/fail2ban/jail.local
if [[ -d "$FD" && -f "$J" ]]; then
  FW='# installed by KarnakPanel (dav-setup.sh) — wrong CalDAV/CardDAV passwords
[Definition]
# fail2ban strips the timestamp (ISO 8601 with offset, server.php runs in UTC) before matching
datepattern = ^%%Y-%%m-%%dT%%H:%%M:%%S%%z
failregex = ^\s*dav auth failed user=\S* ip=<HOST>$
ignoreregex ='
  if [[ "$(cat "$FD/skypanel-dav.conf" 2>/dev/null)" != "$FW" ]]; then
    printf '%s\n' "$FW" > "$FD/skypanel-dav.conf"; chmod 644 "$FD/skypanel-dav.conf"; CH_F2B=1
  fi
  ADDED=0
  if ! grep -q '^\[karnak-dav\]' "$J"; then
    cp -a "$J" "$J.pre-dav"
    cat >> "$J" <<EOF

# wrong calendar/contacts (CalDAV/CardDAV) passwords on :8091/dav/ (dav-setup.sh).
# High threshold: a phone with a stale password retries on every sync.
[karnak-dav]
enabled = true
backend = polling
banaction = ufw-port
port = 8091
filter = skypanel-dav
logpath = $LOGD/auth.log
maxretry = 20
findtime = 600
bantime = 1800
EOF
    CH_F2B=1; ADDED=1
  fi
  if (( CH_F2B )) && systemctl is-active --quiet fail2ban; then
    if timeout 60 fail2ban-client reload >/dev/null 2>&1 && timeout 10 fail2ban-client status karnak-dav >/dev/null 2>&1; then
      rm -f "$J.pre-dav"; say "fail2ban [karnak-dav] active"
    else
      if (( ADDED )); then
        cat "$J.pre-dav" > "$J"; rm -f "$J.pre-dav"; timeout 60 fail2ban-client reload >/dev/null 2>&1 || true
        echo "dav-setup: [karnak-dav] jail did not start — previous jail.local restored"; exit 1
      fi
      echo "dav-setup: fail2ban reload failed after filter update"; exit 1
    fi
  fi
  rm -f "$J.pre-dav"
fi

# legit writer of watched files (jail.local, dav/current, /etc/karnak-dav)
if [[ -n "$SAID" ]]; then
  "$HERE/integrity-check.sh" --baseline >/dev/null 2>&1 || true
fi
# an unchanged run says nothing (host-converge runs this on every update)
exit 0
