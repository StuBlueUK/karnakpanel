#!/usr/bin/env bash
# download-stats.sh — install/download counts from the WEBSERVER's own logs.
# No beacons, no fingerprints: counts GETs of release artifacts served from
# /downloads (tarball + installer + VERSION), per file and per-day unique IPs.
# Run on the box that serves karnakpanel.com (prod).
set -euo pipefail
LOG="/var/log/karnakpanel/karnakpanel.com.access.log"
[[ -r "$LOG" ]] || { echo "no readable $LOG"; exit 1; }
echo "== release downloads (this log) =="
{ grep -oE 'GET /downloads/[a-zA-Z0-9_.-]+' "$LOG" 2>/dev/null || true; } | sort | uniq -c | sort -rn | head -20
echo "== unique IPs per day fetching releases =="
{ grep -E 'GET /downloads/' "$LOG" 2>/dev/null || true; } | awk '{ip=$1; gsub(/^'"'"'/,"",ip); split($4,a,":"); d=substr(a[1],2); print d" "ip}' | sort -u | awk '{print $1}' | uniq -c | tail -14
