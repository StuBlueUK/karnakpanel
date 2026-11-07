# panel-id.sh — sourced (never run): who the panel's PHP worker runs as.
# The SkyPanel vhost's lsphp extprocessor carries `extUser karnak-panel` once
# panel-user.sh has moved it off `nobody` (the OLS worker uid, which then no
# longer gets root-script rights). The vhost is the single source of truth:
# a rollback that restores the old vhost flips every caller back with it.
# Sets PANEL_U / PANEL_G (owner + group of the panel's runtime state and
# the group that reads config.php).
PANEL_VHOST=/usr/local/lsws/conf/vhosts/SkyPanel/vhost.conf
if grep -qE '^[[:space:]]*extUser[[:space:]]+karnak-panel[[:space:]]*$' "$PANEL_VHOST" 2>/dev/null \
   && getent passwd karnak-panel >/dev/null 2>&1; then
  PANEL_U=karnak-panel; PANEL_G=karnak-panel
else
  PANEL_U=nobody; PANEL_G=nogroup
fi
