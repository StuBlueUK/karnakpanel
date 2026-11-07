#!/usr/bin/env bash
# panel-transfer.sh [--out DIR] — export a server-transfer bundle (run as root
# on the SOURCE box). READ-ONLY: dumps config + databases, never mutates.
#
# Bundle holds everything small and machine-specific: panel DB dump, customer
# MySQL dumps, BIND zones, DKIM keys + Exim virtual maps, /root secrets, panel
# cron file, and a MANIFEST with the panel VERSION (the importer REFUSES a
# version mismatch — update the target first, then import).
#
# Big trees (/home/*, /var/mail/virtual) are NOT bundled — the script prints
# the exact rsync commands for those instead (run after the import creates
# the system users, then re-run the importer with --finish for perms/vhosts).
set -euo pipefail
[[ "$(id -u)" == "0" ]] || { echo "run as root"; exit 1; }
OUT="/root"
while [[ $# -gt 0 ]]; do case "$1" in --out) OUT="${2:?}"; shift 2;; *) echo "usage: $0 [--out DIR]"; exit 1;; esac; done
P="/usr/local/skypanel/panel"
[[ -f "$P/VERSION" ]] || { echo "no panel at $P"; exit 1; }
PASS="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
[[ -n "$PASS" ]] || { echo "no panel db pass (/root/.skypanel-dbpass)"; exit 1; }
VER="$(cat "$P/VERSION")"
STAMP="$(date +%Y%m%d-%H%M)"
WORK="$(mktemp -d /tmp/transfer-XXXXXX)"; trap 'rm -rf "$WORK"' EXIT
B="$WORK/bundle"; mkdir -p "$B"
{
  echo "karnakpanel-transfer=1"
  echo "version=$VER"
  echo "date=$(date -u +%FT%TZ)"
  echo "host=$(hostname -f 2>/dev/null || hostname)"
} > "$B/MANIFEST"
MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT COUNT(*) FROM users; SELECT COUNT(*) FROM domains;" 2>/dev/null | tr '\n' ' ' | awk '{print "users="$1"\ndomains="$2}' >> "$B/MANIFEST" || true
echo "— dumping panel DB…"
MYSQL_PWD="$PASS" mysqldump -uskypanel --single-transaction --routines skypanel > "$B/db-skypanel.sql"
echo "— dumping customer databases…"
DBS="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT db_name FROM mysql_dbs;" 2>/dev/null || true)"
if [[ -n "$DBS" ]]; then
  # shellcheck disable=SC2086
  MYSQL_PWD="$(cat /root/.skypanel-provpass 2>/dev/null)" mysqldump -uskypanel_prov --single-transaction --databases $DBS > "$B/db-data.sql" 2>/dev/null \
    || { echo "WARN: prov dump failed (missing /root/.skypanel-provpass?) — customer DB data NOT bundled"; rm -f "$B/db-data.sql"; }
else
  echo "(no customer databases)"
fi
echo "— zones, mail maps, DKIM…"
tar -cf "$B/zones.tar" -C / etc/bind/zones 2>/dev/null || echo "WARN: no /etc/bind/zones"
[[ -d /var/cache/bind/keys ]] && tar -cf "$B/dnskeys.tar" -C / var/cache/bind/keys 2>/dev/null || echo "(no DNSSEC keys)"
tar -cf "$B/mail.tar" -C / etc/exim4/dkim etc/exim4/virtual 2>/dev/null || echo "WARN: no dkim/virtual maps"
echo "— secrets (root-only, mode 600)…"
cp -a /root/.skypanel-dbpass /root/.skypanel-provpass /root/.dovecot-dbpass /root/.roundcube-dbpass "$B/" 2>/dev/null || true
[[ -f /root/.config/rclone/rclone.conf ]] && cp -a /root/.config/rclone/rclone.conf "$B/rclone.conf" || true
chmod 600 "$B"/.skypanel* "$B"/.dovecot* "$B"/.roundcube* "$B/rclone.conf" 2>/dev/null || true
cp -a /etc/cron.d/skypanel "$B/skypanel.cron" 2>/dev/null || echo "WARN: no /etc/cron.d/skypanel"
T="panel-transfer-$(hostname -s)-$STAMP"
tar -czf "$OUT/$T.tar.gz" -C "$WORK" bundle
sha256sum "$OUT/$T.tar.gz" > "$OUT/$T.tar.gz.sha256"
chmod 600 "$OUT/$T.tar.gz" "$OUT/$T.tar.gz.sha256"
echo "=== bundle: $OUT/$T.tar.gz ($(du -h "$OUT/$T.tar.gz" | cut -f1)) ==="
tar -tzf "$OUT/$T.tar.gz" | head -20
cat <<EOF
=== next: big trees via rsync (AFTER import creates users on target) ===
rsync -a --delete /home/ root@TARGET:/home/
rsync -a --delete /var/mail/virtual/ root@TARGET:/var/mail/virtual/
Then on the target: panel-transfer-import.sh $T.tar.gz
  followed by the same script with --finish once rsync is done.
Post-cutover: PTR/rDNS, glue, LE reissue, TTLs, reputation watch.
EOF
