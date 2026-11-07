#!/usr/bin/env bash
# KarnakPanel installer — Ubuntu 24.04 / Debian 13 (22.04: repair re-runs only)
# Stack: OpenLiteSpeed + PHP-FPM 8.3/8.4/8.5 (panel on LSPHP 8.5), BIND9, cron, Dovecot, Exim4, Fail2ban, MariaDB, SpamAssassin(spamd), OpenSSH, vsftpd, PHP panel
set -euo pipefail
SKY_VER="1.5.54"
SKY_DIR="$(cd "$(dirname "$0")" && pwd)"
LOG="/var/log/karnakpanel-install.log"
# root-only log: it records the whole run, and anything secret printed by a
# module must never be readable by customer PHP/cron (logs used to be 644).
# Secrets the installer itself shows go to the TTY only (the final summary).
mkdir -p /var/log; ( umask 077; touch "$LOG" )
# one log per run: a re-run after a failure used to append onto the old one,
# mixing stale errors (e.g. first-run repo misses) with the fresh attempt.
if [[ -s "$LOG" ]]; then mv "$LOG" "$LOG.prev-$(date +%Y%m%d-%H%M%S)"; ( umask 077; touch "$LOG" ); fi
chmod 600 "$LOG" "$LOG".prev-* 2>/dev/null || true
exec > >(tee -a "$LOG") 2>&1

# screen output: one indented line per step (▸), warnings (!) and errors (✗);
# noisy detail (apt, builds, restarts) goes to $LOG only — see run_module
say(){ local m="$*"; if [[ "$m" == WARNING:* ]]; then echo -e "  \e[1;33m!\e[0m ${m#WARNING: }"; else echo -e "  \e[1;36m▸\e[0m $m"; fi; }
die(){ echo -e "  \e[1;31m✗ $*\e[0m" >&2; exit 1; }

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

# welcome-screen layout: label/value rows, headed indented paragraphs and a
# rule, all under 80 columns (one long say line per topic wrapped across the
# [KarnakPanel] prefix and read as a wall of text)
_row(){ printf '  \e[1m%-10s\e[0m %s\n' "$1" "$2"; }
_head(){ echo -e "  \e[1;36m$1\e[0m${2:+ \e[2m$2\e[0m}"; }
_rule(){ echo -e "  \e[2m────────────────────────────────────────────────────────────────────\e[0m"; }
sky_banner(){
  # fresh-box terminals are full of bootstrap scrollback — start clean.
  # stdout is the tee into $LOG (never a tty, so the old [[ -t 1 ]] guard
  # never cleared): clear the terminal itself — interactive fresh installs
  # only (the bootstrap's SSH/RAM warnings are repeated later; a re-run keeps
  # its 22.04 / admin-email warnings on screen).
  if [[ ! -d "$PANEL_DIR" && -t 0 ]] && { : >/dev/tty; } 2>/dev/null; then clear >/dev/tty 2>/dev/null || true; fi
  echo -e "\e[1;36m"
  cat <<'LOGO'
 _  __    ____
| |/ /   |  _ \
| ' /    | |_) |
| . \    |  __/
|_|\_\   |_|
LOGO
  echo -e "  K A R N A K P A N E L\e[0m   v$SKY_VER"
  echo -e "  \e[2mHosting control panel · OpenLiteSpeed · Exim/Dovecot · BIND · MariaDB · PHP\e[0m"
  echo
  _cpu="$(grep -m1 'model name' /proc/cpuinfo 2>/dev/null | cut -d: -f2- | sed 's/^ *//;s/ *$//;s/  */ /g' | cut -c1-48 || true)"
  _cores="$(nproc 2>/dev/null || echo ?)"
  _cpu="${_cpu:+$_cpu, }$_cores cores"
  _mem="$(free -m 2>/dev/null | awk '/^Mem:/{print $2" MB RAM"}' || true)"; _mem="${_mem:-unknown RAM}"
  _disk="$(df -h / 2>/dev/null | awk 'NR==2{print $2" disk, "$4" free"}' || true)"; _disk="${_disk:-unknown disk}"
  _lip="$(hostname -I 2>/dev/null | awk '{print $1}' || true)"; _lip="${_lip:-unknown}"
  _pip="$(wget -qO- --timeout=8 https://checkip.amazonaws.com 2>/dev/null | tr -d '[:space:]' || true)"; _pip="${_pip:-unknown}"
  # the address users actually reach: NAT clouds (AWS/GCP/Oracle) list a
  # private IP first in `hostname -I`, which made the webmail/pma links dead.
  [[ "$_pip" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] && PUB_IP="$_pip"
  _net="public ${_pip}"; [[ "$_lip" != "$_pip" ]] && _net+=", local ${_lip}"
  _row Server "$_cpu"
  _row Memory "$_mem"
  _row Disk "$_disk"
  _row Network "$_net"
  _row Hostname "$(hostname -f 2>/dev/null || hostname)"
  _row Panel "$PANEL_DOMAIN"
  _row Admin "$ADMIN_EMAIL"
  echo
  unset _cpu _cores _mem _disk _lip _pip _net
}
sky_banner
# first-run intro + licence gate (re-runs skip: nothing new to agree to).
# Installs OpenLiteSpeed + PHP 8.3/8.4/8.5 (per-user FPM) + MariaDB +
# Exim/Dovecot/SpamAssassin + BIND9 + TLS-only vsftpd + Fail2ban + Roundcube +
# phpMyAdmin + panel on :8090. Takes ~15-25 min; safe to re-run afterwards.
if [[ ! -d "$PANEL_DIR" && ! -s /root/.skypanel-dbpass ]]; then
  _rule
  _head "What this installs"
  echo "    Web server (OpenLiteSpeed), PHP 8.3-8.5, MariaDB, mail (Exim, Dovecot,"
  echo "    SpamAssassin), DNS (BIND), FTP, Fail2ban, Roundcube, phpMyAdmin and the"
  echo "    panel on port 8090. Takes about 15-25 minutes. Safe to re-run: finished"
  echo "    steps are skipped. Log: $LOG"
  echo
  _head "Licence" "(summary — full text in the LICENSE file)"
  echo -e "    \e[32m✓\e[0m Free to install, use, modify and self-host"
  echo -e "    \e[31m✗\e[0m Not allowed: selling or renting the software, redistributing it,"
  echo "      rebranding it as your own product, or removing copyright notices"
  echo "    No warranty, no free support."
  _rule
  echo
  if [[ "$ACCEPT_LIC" == "1" ]]; then
    echo -e "  \e[1;32m✓\e[0m Licence accepted via KARNAK_ACCEPT_LICENSE=1 — installing"
  elif [[ -t 0 ]]; then
    read -r -p $'  \e[1mAccept the licence and install KarnakPanel? [y/N]\e[0m ' LIC_OK || LIC_OK=""
    [[ "$LIC_OK" =~ ^[Yy]$ ]] || die "Licence not accepted — installer cancelled (nothing was changed)"
    echo -e "  \e[1;32m✓\e[0m Licence accepted — installing"
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
  read -r -p $'  \e[1mInstall ClamAV antivirus for mail?\e[0m Needs 2GB+ RAM, this box has '"$(free -m | awk '/^Mem:/{print $2}')"$'MB [y/N] ' WANT_CLAMAV || true
  echo
  [[ "$WANT_CLAMAV" =~ ^[Yy] ]] && WANT_CLAMAV=1 || WANT_CLAMAV=""
fi

# modules are separate processes: a failure must NAME the module, or set -e
# just stops the run with apt's last line on screen and no hint what broke.
# On screen a module is one line (▸ while it runs, then ✓ + time over it); its
# output goes to $LOG only (modules never prompt) except WARN lines. A
# failure shows its last 25 lines.
declare -A MOD_LABEL=(
  [common]="Base packages" [openlitespeed]="Web server (OpenLiteSpeed)"
  [php-multiphp]="PHP versions" [php-fpm]="PHP-FPM pools" [mariadb]="Database server (MariaDB)"
  [exim-dovecot-spamassassin]="Mail (Exim, Dovecot, SpamAssassin)" [vsftpd]="FTP (vsftpd)"
  [bind]="DNS (BIND9)" [fail2ban-ssh]="Fail2ban and SSH" [cron-ssl]="Scheduled tasks and Let's Encrypt"
  [roundcube]="Webmail (Roundcube)" [phpmyadmin]="phpMyAdmin" [panel-deploy]="KarnakPanel"
  [restic]="Backups (restic)" [clamav]="Antivirus (ClamAV)"
)
_took(){ local t=$(( SECONDS - $1 )); if (( t >= 60 )); then echo "$((t/60))m $((t%60))s"; else echo "${t}s"; fi; }
run_module(){
  local lbl="${MOD_LABEL[$1]:-$1}" t0=$SECONDS rc=0 out
  out="$(mktemp)"
  echo -ne "  \e[1;36m▸\e[0m $lbl …"
  echo "=== module: $1 ===" >>"$LOG"
  bash "$SKY_DIR/lib/$1.sh" >"$out" 2>&1 </dev/null || rc=$?
  cat "$out" >>"$LOG"
  if (( rc )); then
    echo -e "\r\e[2K  \e[1;31m✗\e[0m $lbl — failed. Last lines:"
    tail -n 25 "$out" | sed 's/^/      /'; rm -f "$out"
    return 1
  fi
  echo -e "\r\e[2K  \e[1;32m✓\e[0m $lbl \e[2m($(_took "$t0"))\e[0m"
  # a module's own WARN/WARNING lines still reach the screen, under its ✓
  grep -E '^WARN(ING)?:' "$out" | sed -E 's/^WARN(ING)?: *//' | while IFS= read -r w; do
    echo -e "    \e[1;33m!\e[0m $w"
  done || true
  rm -f "$out"
}
run_module_or_die(){ run_module "$1" || die "$1 failed — full log: $LOG (re-running the installer is safe)"; }
# last thing on screen before the long quiet stretch: users who see a ▸ line
# sit for minutes (PHP builds, mail, MariaDB) and assume it hung, or close SSH.
echo
_rule
echo -e "  \e[1;33m!\e[0m \e[1mThis will take a while — usually 15-25 minutes, longer on small boxes.\e[0m"
echo "    Some steps sit on one line for several minutes with no output; that is"
echo "    normal. Keep this terminal open. If the connection drops, just re-run"
echo "    the installer: finished steps are skipped. Progress: tail -f $LOG"
_rule
echo
for m in common openlitespeed php-multiphp php-fpm mariadb; do run_module_or_die "$m"; done

# panel DB BEFORE the mail modules (they grant on its tables)
say "Panel database and admin account"
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

for m in exim-dovecot-spamassassin vsftpd bind fail2ban-ssh cron-ssl roundcube phpmyadmin panel-deploy restic; do run_module_or_die "$m"; done
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
  run_module clamav || say "WARNING: ClamAV not installed — mail scans fail open; retry later with WITH_CLAMAV=1 bash installer/install.sh"
else
  echo -e "  \e[2m– ClamAV skipped (mail scans fail open; WITH_CLAMAV=1 to include)\e[0m"
fi

say "Panel scripts"
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
"$SCRIPTS_DIR/panel-user.sh" ensure-user >>"$LOG" 2>&1 || die "could not create the karnak-panel user (see $LOG)"
"$SCRIPTS_DIR/panel-sudoers.sh" nobody >>"$LOG" 2>&1 || die "could not write /etc/sudoers.d/skypanel (see $LOG)"

# customer PHP (FPM) may not create symlinks + customer vhosts serve
# owner-matched links only (cross-account leak; symlink-guard.sh)
bash "$SCRIPTS_DIR/symlink-guard.sh" >>"$LOG" 2>&1 || say "WARNING: symlink-guard failed (see $LOG)"
# outbound SMTP: hosting accounts can't connect to other servers' port 25
# (spam scripts/webshells); mail() still goes via Exim. Admin › Firewall
# changes the mode; upgraded boxes stay off until the admin turns it on.
"$SCRIPTS_DIR/smtp-restrict.sh" set mx >>"$LOG" 2>&1 || say "WARNING: outbound SMTP restriction not applied (see $LOG)"

# (database + users seeded earlier, before the mail modules — see above; pass
# files already written there too)

say "Mail maps (repairs existing mailboxes)"
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

say "Self-signed certificates (panel, webmail, mail, FTP)"
bash "$SCRIPTS_DIR/ssl-selfsigned.sh" >>"$LOG" 2>&1 || true
# panel's own domain: onboard it (zone+vhost) and, when DNS already points
# here, LE + services cert. IPs skip this (LE never issues for IPs); the
# printed registrar homework covers the rest.
if [[ "$PANEL_DOMAIN" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]]; then
  say "Panel domain $PANEL_DOMAIN"
  # NOTE: no >>$LOG redirect here (unlike the lines above) — panel-domain.sh
  # is interactive (r/c/a prompt) and exec already tees everything to both
  # the terminal AND the log. Redirecting hid the prompt and looked frozen.
  bash "$SCRIPTS_DIR/panel-domain.sh" "$PANEL_DOMAIN" "$ADMIN_EMAIL" || true
else
  say "WARNING: '$PANEL_DOMAIN' is not a public domain name — domain setup skipped."
  echo "      To reach the panel by name later: add the domain in Domains, point its"
  echo "      A record at $IP0, then Issue SSL."
fi

# apache2 must never run here (php deps reinstall it; OLS owns :80)
say "Web server restart (Apache kept off)"
systemctl stop apache2 2>/dev/null || true
systemctl disable apache2 2>/dev/null || true
systemctl mask apache2 2>/dev/null || true
# via systemd, never `lswsctrl ... | tail`: a cold start daemonises holding
# the pipe open and the installer hangs forever (seen on Debian 13).
systemctl restart lshttpd 2>/dev/null || /usr/local/lsws/bin/lswsctrl restart >/dev/null 2>&1 || true

say "Webmail and phpMyAdmin on their own users"
"$SCRIPTS_DIR/app-isolate.sh" >>"$LOG" 2>&1 </dev/null || say "WARNING: app-isolate failed — webmail/phpMyAdmin still on the shared worker; rerun $SCRIPTS_DIR/app-isolate.sh"
say "Calendar and contacts sync (CalDAV/CardDAV)"
"$SCRIPTS_DIR/dav-setup.sh" >>"$LOG" 2>&1 </dev/null || say "WARNING: calendar/contacts sync not set up — rerun $SCRIPTS_DIR/dav-setup.sh"
say "Panel on its own user (karnak-panel)"
"$SCRIPTS_DIR/panel-user.sh" >>"$LOG" 2>&1 </dev/null || say "WARNING: panel still runs as nobody (switch rolled back) — retry: PANEL_USER_RETRY=1 $SCRIPTS_DIR/panel-user.sh"

# health check: most steps above are best-effort (|| true), so the banner
# alone proves nothing — verify the parts a working panel needs, loudly.
# Retried (6 x 5s): app-isolate + the lsws restart above have just bounced
# FPM/OLS, and a still-starting service must not read as a broken install.
say "Health check"
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
# final summary: written twice — to the terminal with the admin password,
# and to $LOG with the password left out (it is never logged)
_SSH_FP=""
if [[ -n "${SKY_SSH_KEY:-}" ]]; then
  _SSH_FP="$(ssh-keygen -lf /dev/stdin <<<"$SKY_SSH_KEY" 2>/dev/null | awk '{print $2}' || true)"
  [[ -n "$_SSH_FP" ]] || _SSH_FP="deployed"
fi
_bar(){ echo -e "  \e[1;$1m════════════════════════════════════════════════════════════════════\e[0m"; }
summary(){ # summary <password text>
  local f
  echo
  if (( ${#HC_FAIL[@]} )); then
    _bar 33
    echo -e "  \e[1;33m! KarnakPanel $SKY_VER installed WITH PROBLEMS\e[0m"
    for f in "${HC_FAIL[@]}"; do echo "      - $f"; done
    echo "    Re-running the installer is safe; details in $LOG"
    _bar 33
  else
    _bar 32
    echo -e "  \e[1;32m✓ KarnakPanel $SKY_VER is installed\e[0m \e[2m(health check passed)\e[0m"
    _bar 32
  fi
  echo
  _head "Log in"
  _row Panel "https://${IP0}:8090"
  if [[ "$PANEL_DOMAIN" != "$IP0" ]]; then _row "" "https://$PANEL_DOMAIN:8090 once DNS and SSL are set"; fi
  _row Username admin
  _row Password "$1"
  _row "" "saved root-only in /root/.skypanel-adminpass"
  echo
  _head "Next steps"
  echo "    1. Log in, change the admin password and turn on 2FA"
  echo "    2. Create a daily-use account and keep admin for server tasks"
  echo "       only (a stolen daily session must not own the box)"
  if [[ -n "$_SSH_FP" ]]; then
    echo "    3. Back up the SSH private key that matches the deployed key:"
    echo "       losing it means the provider console is the only way in"
  else
    echo "    3. Add your SSH key (panel: Services → SSH), check that a key"
    echo "       login works, then set PasswordAuthentication no"
  fi
  echo
  _head "Good to know"
  if [[ -n "$_SSH_FP" ]]; then _row "SSH key" "$_SSH_FP"
  else _row SSH "no key deployed — password logins are still on"; fi
  _row Webmail "https://${IP0}:8091"
  _row phpMyAdmin "https://${IP0}:8092"
  _row "OLS admin" "firewalled: ssh -L 7080:127.0.0.1:7080 root@${IP0}"
  _row "" "then open https://127.0.0.1:7080"
  _row "DB pass" "/root/.skypanel-dbpass"
  _row "" "provisioner: /root/.skypanel-provpass"
  _row Log "$LOG"
  echo
}
if { : >/dev/tty; } 2>/dev/null; then
  summary "(shown on screen only — not logged)" >>"$LOG"
  sleep 0.3   # let tee flush the lines above before writing to the terminal
  summary "$(echo -e "\e[1;33m$ADMIN_PASS\e[0m  \e[2m(shown once, not logged)\e[0m")" >/dev/tty
else
  summary "(not shown — read /root/.skypanel-adminpass)"
fi
