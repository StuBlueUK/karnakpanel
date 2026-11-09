#!/usr/bin/env bash
# panel-logrotate.sh — weekly rotation for /var/log/karnakpanel/panel.log.
# It was never rotated (~1.5 MB/month on a quiet box, every privileged exec
# logs a line), so it grew forever. The fail2ban [panel] jail watches it:
# `create` (new file, same owner/mode as the old one — karnak-panel or nobody,
# panel-user.sh decides) keeps the jail following it; never copytruncate.
# Writers open per line (Helpers::log FILE_APPEND, scripts' >>), so no reopen
# signal is needed. Run by host-converge (39) and the installer. IDEMPOTENT.
set -euo pipefail
LR=/etc/logrotate.d/karnakpanel-panel
LRW="# managed by panel-logrotate.sh
/var/log/karnakpanel/panel.log {
  weekly
  maxsize 20M
  rotate 12
  compress
  delaycompress
  missingok
  notifempty
  create
}"
[[ "$(cat "$LR" 2>/dev/null)" == "$LRW" ]] && exit 0
printf '%s\n' "$LRW" > "$LR"; chown root:root "$LR"; chmod 644 "$LR"
if command -v logrotate >/dev/null && ! logrotate -d "$LR" >/dev/null 2>&1; then
  rm -f "$LR"; echo "panel-logrotate: logrotate rejected the config, removed"; exit 1
fi
echo "panel-logrotate: $LR installed"
