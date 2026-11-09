#!/usr/bin/env bash
# panel-transfer-import.sh <bundle.tar.gz> [--finish] [--force] — restore a
# panel-transfer.sh bundle onto a FRESH box (run as root on the TARGET).
#
# VERSION GATE: bundle MANIFEST version must equal the local panel VERSION.
# Update the target first (panel-update), then import — this is what keeps
# future panel updates from breaking transfers.
#
# Two phases (big trees move between them):
#   1. import <bundle>   — secrets, panel DB, customer DBs (new random MySQL
#      passwords → mapping file), system users, FTP logins (new random
#      passwords → mapping file), zones registry + render, mail maps + DKIM.
#   2. rsync /home + /var/mail/virtual from source, then import --finish —
#      fix ownership/ACLs, vhosts per domain, mail reconcile, reloads.
#
# Passwords that CANNOT transfer (never stored): MySQL db users, FTP logins.
# They are regenerated and saved root-only in /root/transfer-passwords-<stamp>
# — update app configs (wp-config.php etc.) + FTP clients from that file.
# Portable as-is (bcrypt in DB): panel logins, mailbox passwords.
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/db-caps.sh"  # DB_CAPS: per-user MariaDB caps (admin-set)
. "$(dirname "$(readlink -f "$0")")/php-versions.sh"
[[ "$(id -u)" == "0" ]] || { echo "run as root"; exit 1; }
FINISH=0; FORCE=0; BUNDLE=""
for a in "$@"; do case "$a" in --finish) FINISH=1;; --force) FORCE=1;; *) BUNDLE="$a";; esac; done
P="/usr/local/skypanel/panel"
[[ -f "$P/VERSION" ]] || { echo "no panel at $P (fresh-install + match versions first)"; exit 1; }
S="/usr/local/skypanel/scripts"
PANEL_PHP(){ cd "$P" && php -r "
spl_autoload_register(function(\$c){foreach (['app/Core/','app/Controllers/','app/Models/'] as \$d){\$f=\"\$d\$c.php\"; if(file_exists(\$f)){require_once \$f; return;}}});
Config::load('config/config.php'); $1"; }

if [[ "$FINISH" == "1" ]]; then
  echo "=== phase 2: ownership, vhosts, reconcile ==="
  while IFS=$'\t' read -r U PHPV; do
    [[ -n "$U" ]] || continue
    "$S/create-account.sh" --repair "$U" "$PHPV" 2>&1 | tail -1
  done < <(MYSQL_PWD="$(cat /root/.skypanel-dbpass)" mysql -uskypanel skypanel -N -e \
    "SELECT u.username, COALESCE(p.php_version,'$PHP_DEFAULT') FROM users u LEFT JOIN packages p ON p.id=u.package_id WHERE u.role!='admin';" 2>/dev/null || true)
  while IFS=$'\t' read -r D U PHPV; do
    [[ -n "$D" ]] || continue
    "$S/vhost-add.sh" "$D" "$U" "$PHPV" 2>&1 | tail -1 || echo "WARN: vhost $D"
  done < <(MYSQL_PWD="$(cat /root/.skypanel-dbpass)" mysql -uskypanel skypanel -N -e \
    "SELECT d.domain,u.username,COALESCE(d.php_version,'$PHP_DEFAULT') FROM domains d JOIN users u ON u.id=d.user_id;" 2>/dev/null || true)
  "$S/email-reconcile.sh" 2>&1 | tail -3 || true
  # customer cron jobs (DB) -> /etc/cron.d/skypanel-user-* via cron-run.sh,
  # plus the FPM no-symlink filter / vhost owner-match on this box
  "$S/symlink-guard.sh" all 2>&1 | tail -5 || echo "WARN: symlink-guard"
  "$S/home-perms.sh" all 2>&1 | tail -5 || echo "WARN: home-perms"
  chown -R vmail:vmail /var/mail/virtual 2>/dev/null || true
  systemctl reload bind9 2>/dev/null || true
  systemctl restart lsws 2>/dev/null || service litespeed restart 2>/dev/null || true
  echo "=== finish done — verify: login 200, dig per domain, mail in/out, FTP, backups ==="
  echo "=== still manual: PTR/rDNS, glue, LE reissue (all domains), TTLs back up, reputation watch ==="
  exit 0
fi

[[ -n "$BUNDLE" && -f "$BUNDLE" ]] || { echo "usage: $0 <bundle.tar.gz> [--finish] [--force]"; exit 1; }
WORK="$(mktemp -d /tmp/timport-XXXXXX)"; trap 'rm -rf "$WORK"' EXIT
tar -xzf "$BUNDLE" -C "$WORK"
B="$WORK/bundle"
grep -q "^karnakpanel-transfer=1$" "$B/MANIFEST" || { echo "not a transfer bundle"; exit 1; }
BV="$(grep '^version=' "$B/MANIFEST" | cut -d= -f2)"
LV="$(cat "$P/VERSION")"
[[ "$BV" == "$LV" ]] || { echo "VERSION MISMATCH: bundle=$BV target=$LV — panel-update the target first"; exit 1; }
echo "version gate OK ($LV)"
if [[ "$FORCE" == "0" ]]; then
  NC="$(MYSQL_PWD="$(cat /root/.skypanel-dbpass)" mysql -uskypanel skypanel -N -e "SELECT COUNT(*) FROM users WHERE role!='admin';" 2>/dev/null || echo 0)"
  [[ "$NC" == "0" ]] || { echo "target already has $NC customer(s) — import onto a FRESH box (or --force)"; exit 1; }
fi
STAMP="$(date +%Y%m%d-%H%M)"
cp -a /etc/bind/zones.skypanel "/root/zones.skypanel-pre-transfer-$STAMP" 2>/dev/null || true
MYSQL_PWD="$(cat /root/.skypanel-dbpass)" mysqldump -uskypanel --single-transaction skypanel > "/root/skypanel-pre-transfer-$STAMP.sql"
echo "target panel DB backed up to /root/skypanel-pre-transfer-$STAMP.sql"
echo "— secrets…"
for f in .skypanel-dbpass .skypanel-provpass .dovecot-dbpass .roundcube-dbpass; do
  [[ -f "$B/$f" ]] && install -m600 -o root -g root "$B/$f" "/root/$f"
done
[[ -f "$B/rclone.conf" ]] && { mkdir -p /root/.config/rclone; install -m600 -o root -g root "$B/rclone.conf" /root/.config/rclone/rclone.conf; }
echo "— panel DB…"
mysql -u root skypanel < "$B/db-skypanel.sql"
echo "— customer databases…"
if [[ -f "$B/db-data.sql" ]]; then
  while IFS= read -r DB; do
    [[ -n "$DB" ]] || continue
    NPW="$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c20 || true)"
    DU="$(mysql -u root -N -e "SELECT db_user FROM skypanel.mysql_dbs WHERE db_name='$DB';" || true)"
    mysql -u root <<<"CREATE DATABASE IF NOT EXISTS \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS '$DU'@'localhost' IDENTIFIED BY '$NPW'; GRANT ALL PRIVILEGES ON \`$DB\`.* TO '$DU'@'localhost'; GRANT USAGE ON *.* TO '$DU'@'localhost' WITH $DB_CAPS;" \
      && echo "$DB / $DU : $NPW" >> "/root/transfer-passwords-$STAMP.txt"
  done < <(mysql -u root -N -e "SELECT db_name FROM skypanel.mysql_dbs;" || true)
  mysql -u root < "$B/db-data.sql" && echo "data imported"
  chmod 600 "/root/transfer-passwords-$STAMP.txt" 2>/dev/null || true
  echo "!!! MySQL passwords regenerated → /root/transfer-passwords-$STAMP.txt (update wp-config.php etc.)"
else echo "(no customer DB dump in bundle)"; fi
echo "— system users (no passwords yet — owners set them in panel)…"
while IFS=$'\t' read -r U PHPV KIDS; do
  [[ -n "$U" ]] || continue
  "$S/create-account.sh" "$U" "${PHPV:-$PHP_DEFAULT}" "" "${KIDS:-10}" 2>&1 | tail -1 || echo "WARN: user $U"
done < <(MYSQL_PWD="$(cat /root/.skypanel-dbpass)" mysql -uskypanel skypanel -N -e \
  "SELECT u.username, COALESCE(p.php_version,'$PHP_DEFAULT'), COALESCE(p.max_children,10) FROM users u LEFT JOIN packages p ON p.id=u.package_id WHERE u.role!='admin';" 2>/dev/null || true)
echo "— FTP logins (NEW random passwords → mapping file)…"
while IFS=$'\t' read -r F H; do
  [[ -n "$F" ]] || continue
  NPW="$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c16 || true)"
  printf '%s\0' "$NPW" | "$S/ftp-add.sh" "$F" @stdin "$H" >/dev/null 2>&1 && echo "$F : $NPW  (home $H)" >> "/root/transfer-passwords-$STAMP.txt" || echo "WARN: ftp $F"
done < <(MYSQL_PWD="$(cat /root/.skypanel-dbpass)" mysql -uskypanel skypanel -N -e "SELECT ftp_user,homedir FROM ftp_accounts;" 2>/dev/null || true)
chmod 600 "/root/transfer-passwords-$STAMP.txt" 2>/dev/null || true
echo "— zones…"
tar -xf "$B/zones.tar" -C / 2>/dev/null || true
[[ -f "$B/dnskeys.tar" ]] && { tar -xf "$B/dnskeys.tar" -C /; chown -R bind:bind /var/cache/bind/keys 2>/dev/null || chown -R named:named /var/cache/bind/keys 2>/dev/null || true; }
while IFS= read -r D; do
  [[ -n "$D" ]] || continue
  grep -q "zone \"$D\"" /etc/bind/zones.skypanel 2>/dev/null || echo "zone \"$D\" { type master; file \"/etc/bind/zones/db.$D\"; };" >> /etc/bind/zones.skypanel
  PANEL_PHP "\$c=new DnsController(); \$id=(int)Database::query('SELECT id FROM domains WHERE domain=\"$D\"')->fetch()['id']; if(!\$id){echo \"$D NO-ROW\".PHP_EOL; exit(0);} \$r=\$c->apply(\$id,\"$D\"); echo \"$D \".\$r['code'].(\$r['code'] ? ' '.substr(str_replace(chr(10),' | ',\$r['out'] ?? ''),0,200) : '').PHP_EOL;" || echo "WARN: render $D"
done < <(MYSQL_PWD="$(cat /root/.skypanel-dbpass)" mysql -uskypanel skypanel -N -e "SELECT domain FROM domains;" 2>/dev/null || true)
echo "— mail maps + DKIM…"
tar -xf "$B/mail.tar" -C / 2>/dev/null || echo "WARN: no mail.tar"
# merge, never copy over: this box's own release table stays; the source's
# shipped lines are added/updated, anything else is refused (cron-table-sync)
[[ -f "$B/skypanel.cron" ]] && { "$S/cron-table-sync.sh" "$B/skypanel.cron" || echo "WARN: cron table merge"; }
"$S/email-reconcile.sh" 2>&1 | tail -2 || true
echo "=== phase 1 done ==="
echo "next: rsync -a --delete /home/ root@$(hostname -I | awk '{print $1}'):/home/  (FROM the source)"
echo "      rsync -a --delete /var/mail/virtual/ root@TARGET:/var/mail/virtual/"
echo "then: $0 --finish   (same command, no bundle needed)"
