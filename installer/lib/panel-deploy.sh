#!/usr/bin/env bash
# Deploy panel code into /usr/local/skypanel/panel + wire OLS vhost docroot
set -euo pipefail
SRC="$(cd "$(dirname "$0")/../.." && pwd)"
PANEL_DIR="/usr/local/skypanel/panel"
mkdir -p "$PANEL_DIR"
cp -a "$SRC/app" "$SRC/public" "$SRC/config" "$SRC/database" "$SRC/cli" "$PANEL_DIR/" 2>/dev/null || cp -a ./app ./public ./config ./database ./cli "$PANEL_DIR/" || true
cp -f "$SRC/VERSION" "$PANEL_DIR/VERSION" 2>/dev/null || cp -f ./VERSION "$PANEL_DIR/VERSION" 2>/dev/null || true
# code is root's alone: `cp -a` carries the tarball's owner (the build user,
# or whichever local account shares its name/uid) and group-write
chown -R root:root "$PANEL_DIR/app" "$PANEL_DIR/public" "$PANEL_DIR/database" "$PANEL_DIR/cli" "$PANEL_DIR/VERSION" 2>/dev/null || true
chmod -R go-w "$PANEL_DIR/app" "$PANEL_DIR/public" "$PANEL_DIR/database" "$PANEL_DIR/cli" 2>/dev/null || true
mkdir -p "$PANEL_DIR/storage/logs" "$PANEL_DIR/storage/sessions" "$PANEL_DIR/storage/tmp-uploads" "$PANEL_DIR/config"
chown nobody:nogroup "$PANEL_DIR/storage/tmp-uploads" 2>/dev/null || true
chmod 700 "$PANEL_DIR/storage/tmp-uploads" 2>/dev/null || true
# panel log path (config paths.logs) must be writable by the OLS worker (nobody)
touch /var/log/karnakpanel/panel.log
chown nobody:nogroup /var/log/karnakpanel/panel.log 2>/dev/null || chown nobody:www-data /var/log/karnakpanel/panel.log 2>/dev/null || chmod 666 /var/log/karnakpanel/panel.log
[[ -f "$PANEL_DIR/config/config.php" ]] || cp "$PANEL_DIR/config/config.sample.php" "$PANEL_DIR/config/config.php"
# config.php holds DB secrets: worker-group readable only (never world-readable)
chown root:nogroup "$PANEL_DIR/config/config.php" 2>/dev/null || chown root:www-data "$PANEL_DIR/config/config.php" 2>/dev/null || true
chmod 640 "$PANEL_DIR/config/config.php" 2>/dev/null || true
chmod 700 "$PANEL_DIR/storage/sessions" 2>/dev/null || true
# OLS runs as nobody by default — allow reads, sessions writable (never abort on chown)
chown -R nobody:nogroup "$PANEL_DIR/storage" 2>/dev/null || chown -R nobody:www-data "$PANEL_DIR/storage" 2>/dev/null || chown -R www-data:www-data "$PANEL_DIR/storage" 2>/dev/null || true
chmod -R 755 "$PANEL_DIR/public"
# CLI symlinks (karnakpanel is canonical; skypanel kept as a legacy alias)
ln -sf "$PANEL_DIR/cli/karnakpanel" /usr/local/bin/karnakpanel
ln -sf "$PANEL_DIR/cli/karnakpanel" /usr/local/bin/skypanel
chmod +x /usr/local/bin/karnakpanel /usr/local/bin/skypanel
/usr/local/lsws/bin/lswsctrl restart || systemctl restart lsws || true
echo "Panel deployed to $PANEL_DIR"
