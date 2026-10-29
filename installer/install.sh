#!/usr/bin/env bash
# KarnakPanel installer — Ubuntu 24.04 / Debian 13 (22.04: repair re-runs only)
# Stack: OpenLiteSpeed + PHP-FPM 8.3/8.4/8.5 (panel on LSPHP 8.5), BIND9, cron, Dovecot, Exim4, Fail2ban, MariaDB, SpamAssassin(spamd), OpenSSH, vsftpd, PHP panel
set -euo pipefail
SKY_VER="1.5.45"
SKY_DIR="$(cd "$(dirname "$0")" && pwd)"
LOG="/var/log/karnakpanel-install.log"
# root-only log: it records the whole run, and anything secret printed by a
# module must never be readable by customer PHP/cron (logs used to be 644).
# Secrets the installer itself shows go to the TTY only (say_secret below).
mkdir -p /var/log; ( umask 077; touch "$LOG" )
# one log per run: a re-run after a failure used to append onto the old one,
# mixing stale errors (e.g. first-run repo misses) with the fresh attempt.
if [[ -s "$LOG" ]]; then mv "$LOG" "$LOG.prev-$(date +%Y%m%d-%H%M%S)"; ( umask 077; touch "$LOG" ); fi
chmod 600 "$LOG" "$LOG".prev-* 2>/dev/null || true
exec > >(tee -a "$LOG") 2>&1

say(){ echo -e "\e[1;36m[KarnakPanel]\e[0m $*"; }
die(){ echo -e "\e[1;31m[ERROR]\e[0m $*" >&2; exit 1; }
# terminal only, never the log: stdout is tee'd into $LOG, so secrets go
# straight to the controlling TTY (no TTY = unattended: point at the file).
say_secret(){ if { : >/dev/tty; } 2>/dev/null; then echo -e "\e[1;36m[KarnakPanel]\e[0m $1" >/dev/tty; else say "$2"; fi; }

[[ $EUID -eq 0 ]] || die "Run as root: sudo bash install.sh"
source "${KARNAK_OS_RELEASE:-/etc/os-release}"
# Supported: Ubuntu 24.04 LTS, Debian 13 (trixie). Anything else untested
# (Ubuntu 26.04: Ondrej PHP has no builds yet) dies HERE, not 10 minutes
# into apt — the multi-PHP stack cannot install without those repos.
# Ubuntu 22.04: refused for FRESH installs (its universe Roundcube gets no free
# security updates; EOL April 2027). An EXISTING 22.04 panel may still re-run
# this (repair) — it must keep receiving fixes until it migrates.
_existing=0; [[ -f "${KARNAK_EXISTING_MARKER:-/usr/local/skypanel/panel/config/config.php}" ]] && _existing=1
case "${ID:-}:${VERSION_ID:-}" in
  ubuntu:24.04|debian:13) ;;
  ubuntu:22.04)
    (( _existing )) || die "Ubuntu 22.04 is no longer supported for new installs — use Ubuntu 24.04 LTS or Debian 13."
    say "WARNING: existing Ubuntu 22.04 panel — repair re-run allowed, but move to a supported server (Admin -> Migrate) before April 2027." ;;
  *) die "${PRETTY_NAME:-${ID:-unknown} ${VERSION_ID:-}} is not supported — use Ubuntu 24.04 LTS or Debian 13." ;;
esac
unset _existing

PANEL_DOMAIN="${1:-}"
ADMIN_EMAIL="${2:-admin@localhost}"
# lowercase once, up front: every later check (onboarding, admin email,
# root forward, alerts) matches [a-z] only — "ME@FOO.COM" used to skip them all.
PANEL_DOMAIN="${PANEL_DOMAIN,,}"; ADMIN_EMAIL="${ADMIN_EMAIL,,}"
# Unattended installs (cloud-init/Ansible): KARNAK_ACCEPT_LICENSE=1 accepts the
# licence without a TTY; domain + email must then be real (no prompts possible).
ACCEPT_LIC="${KARNAK_ACCEPT_LICENSE:-}"
# Placeholder guard: the website's copy-paste example uses panel.example.com /
# admin@example.com — installing with those bakes a dead hostname (the
# installer adds it to the panel: zone, vhost, LE attempts) + an unreachable
# email through the whole stack. Fresh installs are asked for real values;
# re-runs keep whatever was passed.
_is_example_domain(){ case "${1,,}" in
  ""|example.com|example.net|example.org|*.example.com|*.example.net|*.example.org|*.localhost|*.local|*.test|*.invalid) return 0;;
  *) return 1;; esac; }
_is_example_email(){ case "${1,,}" in
  ""|admin@localhost|*@localhost|*@example.com|*@example.net|*@example.org) return 0;;
  *) return 1;; esac; }
if [[ ! -d "/usr/local/skypanel/panel" && ! -s /root/.skypanel-dbpass ]]; then
  while _is_example_domain "$PANEL_DOMAIN"; do
    [[ -n "$PANEL_DOMAIN" ]] && say "Ignoring placeholder panel domain '$PANEL_DOMAIN' — the installer adds this hostname to the panel, it must be real."
    [[ -t 0 ]] || die "Panel domain missing — pass the real public domain AND a real email: bash installer/install.sh panel.yourdomain.com you@yourdomain.com"
    read -r -p "Panel domain (public FQDN pointing here, or server IP): " PANEL_DOMAIN || PANEL_DOMAIN=""
    PANEL_DOMAIN="${PANEL_DOMAIN,,}"
    if [[ -n "$PANEL_DOMAIN" ]] && ! _is_example_domain "$PANEL_DOMAIN" \
       && ! [[ "$PANEL_DOMAIN" =~ ^[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ || "$PANEL_DOMAIN" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]]; then
      say "'$PANEL_DOMAIN' doesn't look like a domain or IP — try again."
      PANEL_DOMAIN=""
    fi
  done
  while _is_example_email "$ADMIN_EMAIL"; do
    say "Ignoring placeholder admin email '${ADMIN_EMAIL:-<empty>}' — LE contact, alerts and recovery go here, it must reach you."
    [[ -t 0 ]] || die "Admin email is a placeholder — pass a real address: bash installer/install.sh $PANEL_DOMAIN you@yourdomain.com"
    read -r -p "Admin email (real address, not @example): " ADMIN_EMAIL || ADMIN_EMAIL=""
    ADMIN_EMAIL="${ADMIN_EMAIL,,}"
    if [[ -n "$ADMIN_EMAIL" ]] && ! _is_example_email "$ADMIN_EMAIL" \
       && ! [[ "$ADMIN_EMAIL" =~ ^[^@]+@[^@]+\.[a-zA-Z]{2,}$ ]]; then
      say "'$ADMIN_EMAIL' doesn't look like an email — try again."
      ADMIN_EMAIL=""
    fi
  done
fi
# Fail fast on accidents: an empty domain bakes mail.localhost + admin@localhost
# through the whole stack and the run LOOKS complete, then nothing (SSL, mail,
# alerts) works and the cause is far from obvious. IPs are fine as $1 (LE just
# skips), but empty never is.
if [[ -z "$PANEL_DOMAIN" ]]; then
  die "Panel domain missing — pass the real public domain AND a real email (not defaults): bash installer/install.sh panel.yourdomain.com you@yourdomain.com"
fi
if [[ ! "$ADMIN_EMAIL" =~ ^[^@]+@[^@]+\.[a-z]{2,}$ || "$ADMIN_EMAIL" == *@localhost ]]; then
  say "WARNING: admin email is '${ADMIN_EMAIL}' — continuing, but: Let's Encrypt Issue-SSL will REFUSE until the admin account has a real address (Accounts page), system mail has nowhere to go, and there are no down/recovery alerts. Fix with: installer ran? update the admin email on the Accounts page."
fi
# HELO identity for outbound mail (reverse-DNS should match; user fixes PTR at host)
export MAILNAME="mail.${PANEL_DOMAIN:-$(hostname -f 2>/dev/null || echo localhost)}"
# passwords persist across re-runs (regenerating while CREATE USER IF NOT
# EXISTS keeps the old one desyncs credentials — a fresh-install bug we hit)
if [[ -s /root/.skypanel-dbpass ]]; then DB_PASS="$(cat /root/.skypanel-dbpass)"; else DB_PASS="$(openssl rand -base64 16 | tr -dc 'a-zA-Z0-9' | head -c 20)"; fi
PANEL_DIR="/usr/local/skypanel/panel"
PUB_IP=""   # set by sky_banner when the public-IP probe answers
SCRIPTS_DIR="/usr/local/skypanel/scripts"

sky_banner(){
  # fresh-box terminals are full of bootstrap scrollback — start clean.
  # tty-guarded so piped/log output never eats escape codes.
  [[ -t 1 ]] && clear || true
  echo -e "\e[1;36m"
  cat <<'LOGO'
 _  __    ____
| |/ /   |  _ \
| ' /    | |_) |
| . \    |  __/
|_|\_\   |_|
LOGO
  echo -e "\e[0m\e[1;36m  K A R N A K P A N E L\e[0m"
  echo
  _cpu="$(grep -m1 'model name' /proc/cpuinfo 2>/dev/null | cut -d: -f2- | sed 's/^ *//;s/ *$//' || true)"
  [[ -z "${_cpu:-}" ]] && _cpu="$(nproc 2>/dev/null || echo ?)-core CPU"
  _mem="$(free -m 2>/dev/null | awk '/^Mem:/{print $2" MB RAM"}' || true)"; _mem="${_mem:-unknown RAM}"
  _disk="$(df -h / 2>/dev/null | awk 'NR==2{print $2" disk, "$4" free"}' || true)"; _disk="${_disk:-unknown disk}"
  _lip="$(hostname -I 2>/dev/null | awk '{print $1}' || true)"; _lip="${_lip:-unknown}"
  _pip="$(wget -qO- --timeout=8 https://checkip.amazonaws.com 2>/dev/null | tr -d '[:space:]' || true)"; _pip="${_pip:-unknown}"
  # the address users actually reach: NAT clouds (AWS/GCP/Oracle) list a
  # private IP first in `hostname -I`, which made the webmail/pma links dead.
  [[ "$_pip" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] && PUB_IP="$_pip"
  say "KarnakPanel v$SKY_VER — LiteSpeed/BIND/Dovecot/Exim/MariaDB/PHP-multi stack"
  say "Server : ${_cpu} | ${_mem} | ${_disk}"
  say "Network: local ${_lip} | public ${_pip} | host $(hostname -f 2>/dev/null || hostname)"
  say "Panel  : $PANEL_DOMAIN | Admin email: $ADMIN_EMAIL"
  echo
}
sky_banner
# first-run intro + licence gate (re-runs skip: nothing new to agree to).
# Installs OpenLiteSpeed + PHP 8.3/8.4/8.5 (per-user FPM) + MariaDB +
# Exim/Dovecot/SpamAssassin + BIND9 + TLS-only vsftpd + Fail2ban + Roundcube +
# phpMyAdmin + panel on :8090. Takes ~15-25 min; safe to re-run afterwards.
if [[ ! -d "$PANEL_DIR" && ! -s /root/.skypanel-dbpass ]]; then
  say "This installer sets up a complete hosting stack (~15-25 minutes, logged per-attempt). Re-runs are safe (completed steps are skipped)."
  say "LICENCE (summary — full text in the LICENSE file): free to install, use, modify and self-host. NOT permitted: selling/renting the software itself, redistributing it, rebranding it as your own product, or removing copyright notices. No warranty, no free support."
  if [[ "$ACCEPT_LIC" == "1" ]]; then
    say "Licence accepted via KARNAK_ACCEPT_LICENSE=1 — installing."
  elif [[ -t 0 ]]; then
    read -r -p "Accept the licence and install KarnakPanel? [y/N] " LIC_OK || LIC_OK=""
    [[ "$LIC_OK" =~ ^[Yy]$ ]] || die "Licence not accepted — installer cancelled (nothing was changed)"
    say "Licence accepted — installing."
  else
    die "Fresh installs need an interactive terminal to accept the licence — re-run from a shell, or set KARNAK_ACCEPT_LICENSE=1 for unattended installs"
  fi
  # pre-flight (fresh installs only): fail before apt touches anything
  _disk_gb="$(df -Pk / 2>/dev/null | awk 'NR==2{print int($4/1048576)}' || true)"
  if [[ "${_disk_gb:-0}" =~ ^[0-9]+$ ]] && (( ${_disk_gb:-0} < 10 )); then
    die "Only ${_disk_gb}GB free on / — the stack needs ~10GB free (20GB disk recommended)."
  fi
  _mem_mb="$(free -m 2>/dev/null | awk '/^Mem:/{print $2}' || true)"
  if [[ "${_mem_mb:-0}" =~ ^[0-9]+$ ]] && (( ${_mem_mb:-0} < 1800 )); then
    say "WARNING: ${_mem_mb}MB RAM is under the 2GB minimum — expect swapping; ClamAV will refuse."
  fi
  # an existing MariaDB/MySQL with a root password makes every `mysql -uroot`
  # below fail with a bare "Access denied" deep into the run — say so now.
  # Only "Access denied" means foreign: a server that is merely DOWN (an
  # earlier run died inside the mariadb module) is ours to repair on re-run.
  if command -v mysql >/dev/null 2>&1; then
    _myerr="$(mysql -uroot -e 'SELECT 1' 2>&1 >/dev/null || true)"
    if [[ "$_myerr" == *"Access denied"* ]]; then
      die "A MariaDB/MySQL server is already here and root can't log in via the unix socket — KarnakPanel needs a FRESH Ubuntu 24.04 / Debian 13 box."
    fi
    unset _myerr
  fi
  unset _disk_gb _mem_mb
fi

WANT_CLAMAV="${WITH_CLAMAV:-}"
if [[ -z "$WANT_CLAMAV" && -t 0 ]]; then
  read -r -p "Install ClamAV antivirus for mail? Needs 2GB+ RAM (this box: $(free -m | awk '/^Mem:/{print $2}')MB) [y/N] " WANT_CLAMAV || true
  [[ "$WANT_CLAMAV" =~ ^[Yy] ]] && WANT_CLAMAV=1 || WANT_CLAMAV=""
fi

# modules are separate processes: a failure must NAME the module, or set -e
# just stops the run with apt's last line on screen and no hint what broke.
run_module(){ say "=== module: $1 ==="; bash "$SKY_DIR/lib/$1.sh" || die "module $1 failed — see $LOG (re-running the installer is safe)"; }
for m in common openlitespeed php-multiphp php-fpm mariadb; do run_module "$m"; done

say "Seeding database (BEFORE mail modules grant on its tables)..."
PROV_PASS="$(openssl rand -base64 16 | tr -dc 'a-zA-Z0-9' | head -c 20)"
if [[ -s /root/.skypanel-provpass ]]; then PROV_PASS="$(cat /root/.skypanel-provpass)"; fi
mysql -uroot <<SQL
CREATE DATABASE IF NOT EXISTS skypanel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'skypanel'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON skypanel.* TO 'skypanel'@'localhost';
-- localhost-only provisioner for customer DB/user DDL (DB wizard). Least-privilege split:
-- web requests use 'skypanel' (panel tables only); DDL uses this handle.
CREATE USER IF NOT EXISTS 'skypanel_prov'@'localhost' IDENTIFIED BY '${PROV_PASS}';
GRANT ALL PRIVILEGES ON *.* TO 'skypanel_prov'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;
-- converge on re-runs (IF NOT EXISTS keeps stale passwords otherwise)
ALTER USER 'skypanel'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER 'skypanel_prov'@'localhost' IDENTIFIED BY '${PROV_PASS}';
FLUSH PRIVILEGES;
SQL
# fresh DB = no users table yet. Decided BEFORE the import: only a fresh
# schema contains every bundled migration's end-state (see below).
FRESH_DB=0
_ut="$(mysql -uroot -N skypanel -e "SHOW TABLES LIKE 'users';" 2>/dev/null || true)"
[[ -z "$_ut" ]] && FRESH_DB=1; unset _ut
SCHEMA="$SKY_DIR/../database/schema.sql"; [[ -f "$SCHEMA" ]] || SCHEMA="./database/schema.sql"
[[ -f "$SCHEMA" ]] || die "database/schema.sql not found next to the installer — incomplete extract?"
# fresh: any error is fatal. Re-runs over an existing DB: the schema can
# legitimately trip on an older table shape (self-update migrations own
# that), so warn loudly instead of aborting a repair run.
if ! mysql -uroot skypanel < "$SCHEMA"; then
  [[ "$FRESH_DB" == "1" ]] && die "schema import failed on a fresh database (see the mysql error above)"
  say "WARNING: schema re-import reported errors on the existing database (above) — continuing; run a panel self-update if tables look stale."
fi
# Fresh schema already contains every bundled migration's end-state — record
# them as applied, or the box's FIRST self-update replays all migrations and
# dies on the non-idempotent ones (029's ADD KEY). Standard practice.
# ONLY on a fresh DB: re-running a newer installer over an older install
# would otherwise mark migrations that never ran as done (skipped forever).
mysql -uroot skypanel -e "CREATE TABLE IF NOT EXISTS applied_migrations (name VARCHAR(190) PRIMARY KEY, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);" 2>/dev/null || true
[[ "$FRESH_DB" == "1" ]] && for _m in "$SKY_DIR/../database/migrations/"*.sql "$SKY_DIR/database/migrations/"*.sql ./database/migrations/*.sql; do
  [[ -f "$_m" ]] || continue
  _n="$(basename "$_m")"
  mysql -uroot skypanel -e "INSERT IGNORE INTO applied_migrations(name) VALUES('$_n');" 2>/dev/null || true
done; unset _m _n
# NOTE: config.php passwords are injected AFTER panel-deploy creates the file (see below)
echo "$DB_PASS" > /root/.skypanel-dbpass; chmod 600 /root/.skypanel-dbpass
echo "$PROV_PASS" > /root/.skypanel-provpass; chmod 600 /root/.skypanel-provpass
# Random per-install panel admin account. Four states, all safe:
#   fresh (no file, no row)      -> create row with random password, verify
#   re-run (file + row)          -> touch NOTHING (panel-changed passwords survive)
#   lost file (no file, row)     -> reset row to a new file password (owner
#                                   reads it via root; only sane recovery)
#   lost row (file, no row)      -> re-create from the file password
# The verify runs only on paths where WE just wrote the password — a
# panel-side change is unverifiable by design (hashing), so re-runs with an
# untouched row skip it instead of false-aborting.
NEW_FILE=0
if [[ -s /root/.skypanel-adminpass ]]; then
  ADMIN_PASS="$(cat /root/.skypanel-adminpass)"
else
  ADMIN_PASS="$(openssl rand -base64 18 | tr -dc 'a-zA-Z0-9' | head -c 24)"
  echo "$ADMIN_PASS" > /root/.skypanel-adminpass; chmod 600 /root/.skypanel-adminpass
  NEW_FILE=1
fi
ADMIN_HASH="$(php -r 'echo password_hash($argv[1], PASSWORD_BCRYPT), PHP_EOL;' "$ADMIN_PASS")"
# admin email comes from the installer arg below; use a placeholder for now so
# the INSERT has a valid row even if the email step is skipped on re-runs.
ROW_EXISTS="$(mysql -uroot -N skypanel -e "SELECT COUNT(*) FROM users WHERE username='admin';" 2>/dev/null || echo err)"
[[ "$ROW_EXISTS" =~ ^[0-9]+$ ]] || { echo "FATAL: cannot read panel database — aborting before admin setup"; exit 1; }
if [[ "$ROW_EXISTS" == "0" ]]; then
  mysql -uroot skypanel -e "INSERT INTO users(username,email,password_hash,role) VALUES('admin','admin@localhost','${ADMIN_HASH}','admin');" 2>/dev/null \
    || { echo "FATAL: admin account creation failed"; exit 1; }
elif [[ "$NEW_FILE" == "1" ]]; then
  mysql -uroot skypanel -e "UPDATE users SET password_hash='${ADMIN_HASH}' WHERE username='admin';" 2>/dev/null \
    || { echo "FATAL: admin account recovery failed"; exit 1; }
fi
if [[ "$ROW_EXISTS" == "0" || "$NEW_FILE" == "1" ]]; then
  # Verify only where we just wrote: a failed write with no check would leave
  # the box with a mismatched (or missing) admin login while claiming success.
  NEW_HASH="$(mysql -uroot -N skypanel -e "SELECT password_hash FROM users WHERE username='admin';" 2>/dev/null || true)"
  php -r 'exit(password_verify($argv[1], $argv[2]) ? 0 : 1);' "$ADMIN_PASS" "$NEW_HASH" 2>/dev/null \
    || { echo "FATAL: admin account setup failed — refusing to continue without a working admin login"; exit 1; }
fi
# admin account email: the SSL page takes the ACME contact from the users table
# (SslController), NOT from the installer arg — so a real installer email must
# land there, or every Issue-SSL refuses with admin@localhost. Re-run safe:
# only replaces placeholder/empty, never an email the owner set themselves.
if [[ "$ADMIN_EMAIL" =~ ^[^@]+@[^@]+\.[a-z]{2,}$ && "$ADMIN_EMAIL" != *@localhost ]]; then
  _AE="${ADMIN_EMAIL//\'/}"
  mysql -uroot skypanel -e "UPDATE users SET email='$_AE' WHERE username='admin' AND (email='' OR email LIKE '%@localhost');" 2>/dev/null || true
  unset _AE
fi

for m in exim-dovecot-spamassassin vsftpd bind fail2ban-ssh cron-ssl roundcube phpmyadmin panel-deploy restic; do run_module "$m"; done
# roundcube/phpmyadmin pull the PPA `php-cli` metapackage, which makes
# /usr/bin/php the newest PPA build (8.4/8.5, no mbstring/intl) — roundcube's
# gc timer then fatals every run. Pin the CLI to the stack's default version.
. "$SKY_DIR/../scripts/php-versions.sh"
if [[ -x "/usr/bin/php$PHP_DEFAULT" ]]; then
  update-alternatives --set php "/usr/bin/php$PHP_DEFAULT" >/dev/null 2>&1 || say "WARNING: could not pin /usr/bin/php to $PHP_DEFAULT"
fi
# config.php now exists (panel-deploy created it) — inject generated passwords
if [[ -f "$PANEL_DIR/config/config.php" ]]; then
  sed -i "s/'pass'=>'[^']*'/'pass'=>'${DB_PASS}'/" "$PANEL_DIR/config/config.php"
  sed -i "s/'provision_pass'=>'[^']*'/'provision_pass'=>'${PROV_PASS}'/" "$PANEL_DIR/config/config.php"
else
  say "WARNING: $PANEL_DIR/config/config.php missing — panel cannot reach its DB"
fi
# service URLs (modules try too, but config may not have existed then)
IP0="${PUB_IP:-$(hostname -I | awk '{print $1}')}"
sed -i "s#'webmail_url' => '[^']*'#'webmail_url' => 'http://$IP0:8091'#" "$PANEL_DIR/config/config.php" 2>/dev/null || true
if grep -q "phpmyadmin_url" "$PANEL_DIR/config/config.php" 2>/dev/null; then
  sed -i "s#'phpmyadmin_url' => '[^']*'#'phpmyadmin_url' => 'https://$IP0:8092'#" "$PANEL_DIR/config/config.php" 2>/dev/null || true
fi
if [[ -n "${WANT_CLAMAV:-}" ]]; then
  # optional: a refusal (low RAM) or failure must not abort the install
  # halfway — scripts, sudoers, TLS and the health check still come after it.
  say "=== module: clamav (optional, needs 2GB+ RAM) ==="
  bash "$SKY_DIR/lib/clamav.sh" || say "WARNING: ClamAV not installed (see above) — mail scans fail open; retry later with WITH_CLAMAV=1 bash installer/install.sh"
else
  say "(skipping ClamAV — mail scans fail open; WITH_CLAMAV=1 to include)"
fi

say "Provision scripts -> $SCRIPTS_DIR"
mkdir -p "$SCRIPTS_DIR"
# every later step runs these: a failed copy must stop here, not surface as
# a string of confusing "No such file" errors further down.
cp -f "$SKY_DIR"/../scripts/*.sh "$SCRIPTS_DIR/" 2>/dev/null || cp -f ./scripts/*.sh "$SCRIPTS_DIR/" \
  || die "could not copy the panel scripts into $SCRIPTS_DIR — incomplete extract?"
chown -R root:root "$SCRIPTS_DIR"; chmod 755 "$SCRIPTS_DIR" "$SCRIPTS_DIR"/*.sh
# update-signing trust anchor: panel-update.sh verifies self-update tarballs
# against this key. Lives OUTSIDE scripts/ + panel/ so updates never replace
# it (trust-on-first-install); private half stays on the build host only.
cp -f "$SKY_DIR"/../installer/configs/update-pubkey.pem /usr/local/skypanel/update-pubkey.pem 2>/dev/null || cp -f ./installer/configs/update-pubkey.pem /usr/local/skypanel/update-pubkey.pem || true
chmod 600 /usr/local/skypanel/update-pubkey.pem 2>/dev/null || true

# sudoers: the panel worker may run the scripts the panel code names, and
# nothing else in scripts/ (panel-sudoers.sh derives the list from the panel
# tree). Still `nobody` here; panel-user.sh (end of install) moves the worker
# to karnak-panel and the sudoers line with it. No www-data line: it would
# hand root-script rights to the distro FPM `www` pools.
# the panel worker's own user must exist before any script writes the
# u:karnak-panel ACL twins (setfacl rejects a whole spec naming a missing user)
"$SCRIPTS_DIR/panel-user.sh" ensure-user || die "could not create the karnak-panel user"
"$SCRIPTS_DIR/panel-sudoers.sh" nobody || die "could not write /etc/sudoers.d/skypanel (see above)"

# customer PHP (FPM) may not create symlinks + customer vhosts serve
# owner-matched links only (cross-account leak; symlink-guard.sh)
bash "$SCRIPTS_DIR/symlink-guard.sh" >>"$LOG" 2>&1 || say "WARNING: symlink-guard failed (see $LOG)"

# (database + users seeded earlier, before the mail modules — see above; pass
# files already written there too)

say "Reconciling virtual mail maps (repairs pre-existing mailboxes, migrates legacy mail)..."
bash "$SCRIPTS_DIR/email-reconcile.sh" >>"$LOG" 2>&1 || true
# system mail must reach a human from day one: bare root dies in
# local_delivery (uid 0 refused), so forward root@<mailname> at the address
# the installer was given (skipped for localhost placeholders).
if [[ "$ADMIN_EMAIL" =~ ^[^@]+@[^@]+\.[a-z]{2,}$ && "$ADMIN_EMAIL" != *@localhost ]]; then
  "$SCRIPTS_DIR/forward-add.sh" "root@$MAILNAME" "$ADMIN_EMAIL" >>"$LOG" 2>&1 || true
  _AE="${ADMIN_EMAIL//\'/}"
  mysql -uroot skypanel -e "INSERT INTO settings(name,value) VALUES('alert_email','$_AE') ON DUPLICATE KEY UPDATE value=VALUES(value);" 2>/dev/null || true
  unset _AE
fi

say "Enabling self-signed TLS (panel, webmail, mail, ftp)..."
bash "$SCRIPTS_DIR/ssl-selfsigned.sh" >>"$LOG" 2>&1 || true
# panel's own domain: onboard it (zone+vhost) and, when DNS already points
# here, LE + services cert. IPs skip this (LE never issues for IPs); the
# printed registrar homework covers the rest.
if [[ "$PANEL_DOMAIN" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]]; then
  say "Onboarding panel domain $PANEL_DOMAIN..."
  # NOTE: no >>$LOG redirect here (unlike the lines above) — panel-domain.sh
  # is interactive (r/c/a prompt) and exec already tees everything to both
  # the terminal AND the log. Redirecting hid the prompt and looked frozen.
  bash "$SCRIPTS_DIR/panel-domain.sh" "$PANEL_DOMAIN" "$ADMIN_EMAIL" || true
else
  say "Panel domain '$PANEL_DOMAIN' is not a public FQDN — skipping domain onboarding."
  say "To reach the panel by name later: add the domain in Domains, point its A record at $IP0, then Issue SSL."
fi

say "Final sweep: apache2 must never run here (php deps reinstall it; OLS owns :80)..."
systemctl stop apache2 2>/dev/null || true
systemctl disable apache2 2>/dev/null || true
systemctl mask apache2 2>/dev/null || true
# via systemd, never `lswsctrl ... | tail`: a cold start daemonises holding
# the pipe open and the installer hangs forever (seen on Debian 13).
systemctl restart lshttpd 2>/dev/null || /usr/local/lsws/bin/lswsctrl restart >/dev/null 2>&1 || true

say "Isolating webmail + phpMyAdmin onto their own users (no sudo reach)..."
"$SCRIPTS_DIR/app-isolate.sh" || say "WARNING: app-isolate failed — webmail/phpMyAdmin still on the shared worker; rerun $SCRIPTS_DIR/app-isolate.sh"
say "Moving the panel worker off nobody onto its own user (karnak-panel)..."
"$SCRIPTS_DIR/panel-user.sh" || say "WARNING: panel still runs as nobody (switch rolled back) — retry: PANEL_USER_RETRY=1 $SCRIPTS_DIR/panel-user.sh"

# health check: most steps above are best-effort (|| true), so the banner
# alone proves nothing — verify the parts a working panel needs, loudly.
# Retried (6 x 5s): app-isolate + the lsws restart above have just bounced
# FPM/OLS, and a still-starting service must not read as a broken install.
say "Health check..."
HC_FAIL=()
hc_retry(){ local i; for i in 1 2 3 4 5 6; do "$@" && return 0; sleep 5; done; return 1; }
panel_up(){
  local c
  c="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 10 https://127.0.0.1:8090/login 2>/dev/null || true)"
  [[ "$c" == "200" ]] || c="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 http://127.0.0.1:8090/login 2>/dev/null || true)"
  [[ "$c" == "200" ]]
}
webmail_up(){
  local c
  c="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 10 https://127.0.0.1:8091/ 2>/dev/null || true)"
  [[ "$c" == "200" ]] || c="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 http://127.0.0.1:8091/ 2>/dev/null || true)"
  [[ "$c" == "200" ]]
}
if systemctl cat spamd >/dev/null 2>&1; then _sa=spamd; else _sa=spamassassin; fi
# every php-fpm that installed (a version the repo lacked was a WARN, not a
# health failure — the panel just doesn't offer it)
_fpm=(); while IFS= read -r _v; do _fpm+=("php$_v-fpm"); done < <(php_installed)
for _u in lshttpd mariadb exim4 dovecot named fail2ban "${_fpm[@]}" "$_sa"; do
  hc_retry systemctl is-active --quiet "$_u" || HC_FAIL+=("service $_u not running")
done
exim -bV >/dev/null 2>&1 || HC_FAIL+=("exim config does not parse (exim -bV)")
# filtered mailboxes route through this transport; without it they get NO mail
grep -q '^dovecot_lmtp:' /etc/exim4/exim4.conf 2>/dev/null || HC_FAIL+=("exim config lacks the dovecot_lmtp transport (mail filters would drop mail)")
doveconf -n >/dev/null 2>&1 || HC_FAIL+=("Dovecot config does not parse (doveconf -n)")
named-checkconf >/dev/null 2>&1 || HC_FAIL+=("BIND config invalid (named-checkconf)")
hc_retry panel_up || HC_FAIL+=("panel :8090/login is not answering 200")
hc_retry webmail_up || HC_FAIL+=("webmail :8091 is not answering 200")
unset _u _sa
say "--------------------------------------------------"
if (( ${#HC_FAIL[@]} )); then
  say "⚠️  KarnakPanel installed WITH PROBLEMS:"
  for _f in "${HC_FAIL[@]}"; do say "   - $_f"; done
  say "   Re-running the installer is safe; details in $LOG"
else
  say "✅ KarnakPanel installed! (health check passed)"
fi
say "Panel : https://${IP0}:8090  (or https://$PANEL_DOMAIN:8090 once DNS + SSL are set)"
say "OLS admin (:7080) is firewalled — reach it via: ssh -L 7080:127.0.0.1:7080 root@${IP0}, then https://127.0.0.1:7080"
say_secret "Login : admin / $ADMIN_PASS  (shown ONCE, not logged — saved root-only in /root/.skypanel-adminpass)" \
  "Login : admin — password saved root-only in /root/.skypanel-adminpass (not written to this log)"
say "  → change it after first login, enable 2FA, then create a daily-use"
say "    account and stop using admin (admin is for"
say "    server tasks only — a stolen daily session must not own the box)"
say "DB pass saved in /root/.skypanel-dbpass (provisioner: /root/.skypanel-provpass)"
# show WHICH key owns root SSH (fingerprint + where the private half lives),
# so nobody wonders which ~/.ssh file to use afterwards.
if [[ -n "${SKY_SSH_KEY:-}" ]]; then
  FP="$(ssh-keygen -lf /dev/stdin <<<"$SKY_SSH_KEY" 2>/dev/null | awk '{print $2, $4}' || true)"
  [[ -n "$FP" ]] || FP="(comment: $(awk '{print $NF}' <<<"$SKY_SSH_KEY"))"
  say "SSH key for root: $FP — in /root/.ssh/authorized_keys; log in with the matching private key (back it up — losing it = locked out without provider console)"
else
  say "SSH : NO key deployed — password logins still ON. Add your .pub via panel Services → SSH, verify key login, then set PasswordAuthentication no"
fi
say "Logs  : $LOG"
