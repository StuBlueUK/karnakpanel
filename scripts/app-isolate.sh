#!/usr/bin/env bash
# app-isolate.sh — run Roundcube (:8091) and phpMyAdmin (:8092) in their own
# PHP-FPM pools as dedicated system users, instead of the shared OLS lsphp
# worker that runs as `nobody` — the uid that held the panel's sudo rights
# (and still does on boxes panel-user.sh has not moved). Separate uids keep
# the webmail/phpMyAdmin apps away from the privileged gateway.
# Also drops the old www-data line from /etc/sudoers.d/skypanel (unused on
# OLS boxes, but it gave the distro FPM `www` pools root-script rights).
#
# Idempotent: installer runs it after both webmail modules; panel-update runs
# it every update so existing boxes converge. Only reloads FPM / restarts
# OLS when something actually changed. No args.
# Exit 3 = SKIPPED (php-fpm lacks an extension both apps need): nothing is
# moved, an admin notification is raised (once a week) and the 04:41 cron
# retries daily. Callers accept 3 (post-update, roundcube-upstream): a skip
# must not fail the whole convergence or hold back a Roundcube upgrade.
# Test hook: APP_ISOLATE_TEST_MISSING=<ext> pretends it is missing.
#
# The panel itself moves to its own uid separately (panel-user.sh).
set -euo pipefail
PV=8.3
POOLDIR="/etc/php/$PV/fpm/pool.d"
VH=/usr/local/lsws/conf/vhosts

# ---- sudoers: no www-data (needs no FPM: runs even when isolation can't) ----
S=/etc/sudoers.d/skypanel
if [[ -f "$S" ]] && grep -q '^www-data ' "$S"; then
  T="$(mktemp)"
  grep -v '^www-data ' "$S" > "$T"
  if grep -qE '^[a-z, -]+ ALL=\(root\) NOPASSWD: ' "$T" && visudo -cqf "$T"; then
    install -m 440 -o root -g root "$T" "$S"; echo "sudoers: dropped www-data"
  else
    echo "sudoers: candidate failed validation — left unchanged"
  fi
  rm -f "$T"
fi

[[ -d "$POOLDIR" ]] || { echo "php$PV-fpm not installed — cannot isolate"; exit 1; }
# both apps must keep working on the FPM build — the shared lsphp has its own
# extension set. A missing ext means NO move (stay on the old worker), never
# a 500ing webmail after an update.
MODS="$(php-fpm$PV -m 2>/dev/null | tr 'A-Z' 'a-z')"
MISSING=""
for m in intl mysqli pdo_mysql mbstring dom xml json session zip; do
  grep -qx "$m" <<<"$MODS" || MISSING+="$m "
done
if [[ -n "${APP_ISOLATE_TEST_MISSING:-}" ]]; then
  [[ "$APP_ISOLATE_TEST_MISSING" =~ ^[a-z_]{2,20}$ ]] || { echo "bad APP_ISOLATE_TEST_MISSING"; exit 1; }
  MISSING+="$APP_ISOLATE_TEST_MISSING "
fi
if [[ -n "$MISSING" ]]; then
  MISSING="${MISSING% }"
  # still on the shared lsphp worker = running as nobody, which may sudo every panel script
  SHARED="$(grep -lE '^\s*add\s+lsapi:lsphp[0-9]+\s+php' "$VH"/Webmail/vhost.conf "$VH"/PhpMyAdmin/vhost.conf 2>/dev/null | awk -F/ '{print $(NF-1)}' | paste -sd' ' - || true)"
  TITLE="Webmail/phpMyAdmin isolation skipped: php$PV-fpm lacks $MISSING${SHARED:+ ($SHARED still run as nobody)} — install the php$PV extension(s)"
  echo "SKIPPED: $TITLE"
  TITLE="${TITLE:0:190}"; TITLE="${TITLE//\'/}"
  mysql -uroot skypanel -e "INSERT INTO notifications(user_id,type,title) SELECT NULL,'app-isolate','$TITLE' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM notifications WHERE user_id IS NULL AND type='app-isolate' AND created_at > (NOW() - INTERVAL 7 DAY));" 2>/dev/null || true
  exit 3
fi
CHANGED_FPM=0; CHANGED_OLS=0

ensure_user() {  # system user, no home, no shell, own group
  local ent
  if ent="$(getent passwd "$1")"; then
    # never adopt a stranger: an existing name must be the system user we made
    [[ "$(cut -d: -f3 <<<"$ent")" -lt 1000 && "$(cut -d: -f7 <<<"$ent")" == /usr/sbin/nologin ]] \
      || { echo "REFUSED: existing user $1 is not a nologin system user"; return 1; }
    return 0
  fi
  useradd --system --user-group --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin "$1"
  echo "created system user $1"
}

# write_pool <name> <session_dir> <error_log>  (only rewrites when content differs)
write_pool() {
  local N="$1" SESS="$2" ELOG="$3" F="$POOLDIR/$1.conf" TMP
  mkdir -p "$SESS"; chown "$N:" "$SESS"; chmod 700 "$SESS"
  touch "$ELOG"; chown "$N:" "$ELOG"; chmod 640 "$ELOG"
  TMP="$(mktemp)"
  cat > "$TMP" <<EOF
; KarnakPanel isolated app pool (managed by app-isolate.sh — do not hand-edit)
; ';' comments only: '#' lines take down ALL of FPM
[$N]
user = $N
group = $N
listen = /run/php/$N.sock
listen.owner = nobody
listen.group = nogroup
listen.mode = 0660
listen.backlog = 16
pm = ondemand
pm.max_children = 10
pm.process_idle_timeout = 30s
request_terminate_timeout = 300s
php_admin_value[memory_limit] = 256M
php_admin_value[upload_max_filesize] = 64M
php_admin_value[post_max_size] = 64M
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec,show_source
php_admin_value[error_log] = $ELOG
php_admin_value[session.save_path] = $SESS
; Debian's sessionclean cron only sweeps php.ini's save_path — GC our own
php_admin_value[session.gc_probability] = 1
php_admin_value[session.gc_divisor] = 100
php_admin_value[session.gc_maxlifetime] = 86400
EOF
  if ! cmp -s "$TMP" "$F" 2>/dev/null; then
    install -m 644 -o root -g root "$TMP" "$F"; CHANGED_FPM=1; echo "pool $N written"
  fi
  rm -f "$TMP"
}

# point_vhost <vhost dir name> <pool name>: swap the lsapi handler for the pool
point_vhost() {
  local C="$VH/$1/vhost.conf" N="$2"
  [[ -f "$C" ]] || { echo "skip $1: no vhost (module not installed)"; return 0; }
  if ! grep -q "^extprocessor fpm-$N {" "$C"; then
    # multi-line block ONLY (single-line OLS blocks are silently ignored)
    cat >> "$C" <<EOF

extprocessor fpm-$N {
  type                    fcgi
  address                 uds:///run/php/$N.sock
  maxConns                10
  initTimeout             330
  retryTimeout            5
  persistConn             0
  respBuffer              0
  autoStart               0
  backlog                 16
  instances               1
}
EOF
    CHANGED_OLS=1
  fi
  if grep -qE '^\s*add\s+lsapi:lsphp[0-9]+\s+php\s*$' "$C"; then
    sed -i -E "s#^(\s*add\s+)lsapi:lsphp[0-9]+(\s+php\s*)\$#\1fcgi:fpm-$N\2#" "$C"
    CHANGED_OLS=1
  fi
  grep -qE "^\s*add\s+fcgi:fpm-$N\s+php" "$C" || { echo "FAILED to point $1 at fpm-$N"; exit 1; }
  echo "$1 -> fpm-$N"
}

# ---- Roundcube ----
if [[ -d "$VH/Webmail" ]]; then
  ensure_user karnak-webmail
  # upstream Roundcube (roundcube-upstream.sh): config root:karnak-webmail
  # 640, own temp dir. Distro paths kept until that switch has happened.
  if [[ -f /etc/karnak-roundcube/config.inc.php ]]; then
    chown root:karnak-webmail /etc/karnak-roundcube/config.inc.php; chmod 640 /etc/karnak-roundcube/config.inc.php
    RC_SESS=/var/lib/karnak-roundcube/sessions
  else
    RC_SESS=/var/lib/roundcube/sessions
  fi
  for f in /etc/roundcube/config.inc.php /etc/roundcube/debian-db.php; do
    [[ -f "$f" ]] && setfacl -m u:karnak-webmail:r "$f"
  done
  for d in /var/lib/roundcube/temp /var/lib/karnak-roundcube/temp /var/log/roundcube; do
    [[ -d "$d" ]] || continue
    setfacl -m u:karnak-webmail:rwx "$d"; setfacl -d -m u:karnak-webmail:rwx "$d"
  done
  write_pool karnak-webmail "$RC_SESS" /var/log/roundcube/php-error.log
  point_vhost Webmail karnak-webmail
fi

# ---- phpMyAdmin ----
if [[ -d "$VH/PhpMyAdmin" ]]; then
  ensure_user karnak-pma
  for f in /etc/phpmyadmin/config-db.php /var/lib/phpmyadmin/blowfish_secret.inc.php; do
    [[ -f "$f" ]] && setfacl -m u:karnak-pma:r "$f"
  done
  if [[ -d /var/lib/phpmyadmin/tmp ]]; then
    setfacl -m u:karnak-pma:rwx /var/lib/phpmyadmin/tmp; setfacl -d -m u:karnak-pma:rwx /var/lib/phpmyadmin/tmp
  fi
  write_pool karnak-pma /var/lib/phpmyadmin/sessions /var/log/karnakpanel/php-karnak-pma.error.log
  point_vhost PhpMyAdmin karnak-pma
fi

# ---- apply ----
if (( CHANGED_FPM )); then
  php-fpm$PV -t 2>&1 | tail -1
  php-fpm$PV -t >/dev/null 2>&1 || { echo "php-fpm$PV -t FAILED — not reloading"; exit 1; }
  systemctl reload "php$PV-fpm" 2>/dev/null || systemctl restart "php$PV-fpm"
fi
if (( CHANGED_FPM || CHANGED_OLS )); then
  /usr/local/lsws/bin/lswsctrl restart 2>&1 | tail -1 || true
fi
echo "app-isolate: done (fpm changed=$CHANGED_FPM, ols changed=$CHANGED_OLS)"
