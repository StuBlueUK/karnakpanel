# prod/ — web-box-only tooling (NEVER shipped: not in release tarballs, but
# published on the GitHub mirror for audit). Deployed by hand, never by the
# installer or panel-update (both ignore this dir).

## download telemetry (download-digest.sh + download-stats.sh)
Counts panel downloads from the website access log. Prod-only: customer
boxes have no downloads log (the scripts exit silent there anyway).

One-time deploy (already done 2026-10-12 — re-run only if lost):
  mkdir -p /root/prod-scripts
  cp prod/download-digest.sh prod/download-stats.sh /root/prod-scripts/
  chmod +x /root/prod-scripts/*.sh
  # hand-managed cron line on the WEB box only (backup /etc/cron.d/skypanel first):
  15 6 * * 1 root /root/prod-scripts/download-digest.sh >/dev/null 2>&1
