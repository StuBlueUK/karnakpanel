#!/usr/bin/env bash
# app-status.sh <username> <name> — "active|inactive enabled|disabled" for one daemon.
# app-status.sh show <username>|--all — one line per daemon, one systemctl call:
#   user TAB name TAB active-state TAB sub-state TAB enabled|disabled TAB result
#   TAB restarts TAB memory-bytes|- TAB cpu-nsec|- TAB active-since-epoch|0
# result "start-limit-hit" = the crash-loop guard stopped it (5 starts in 5 min).
# systemd 255 leaves Result at the last run's (exit-code…), so it is derived:
# with Restart=always a unit only stays failed when the restart was refused —
# unless it exited 75 (the runner refused: suspended…, never restarted).
# Marked unit files only (karnak-sysupdate & co are not customer daemons).
set -euo pipefail
if [[ "${1:-}" == show ]]; then
  T="${2:?}"
  [[ "$T" == --all || "$T" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad user"; exit 1; }
  units=()
  for f in /etc/systemd/system/karnak-*.service; do
    [[ -f "$f" ]] && head -1 "$f" | grep -qF "; KarnakPanel supervised app" || continue
    b="$(basename "$f" .service)"; r="${b#karnak-}"; u="${r%%-*}"; n="${r#*-}"
    [[ "$u" =~ ^[a-z][a-z0-9_]{2,31}$ && "$n" =~ ^[a-z0-9_-]{1,32}$ ]] || continue
    [[ "$T" == --all || "$T" == "$u" ]] || continue
    units+=("$b.service")
  done
  (( ${#units[@]} )) || exit 0
  P=Id,ActiveState,SubState,UnitFileState,Result,NRestarts,MemoryCurrent,CPUUsageNSec,ActiveEnterTimestamp,ExecMainStatus
  # --timestamp=unix is newer than some supported systemd: without it the
  # timestamp is a date string and "since" falls back to 0
  out="$(systemctl show --timestamp=unix -p "$P" -- "${units[@]}" 2>/dev/null)" || out="$(systemctl show -p "$P" -- "${units[@]}" 2>/dev/null || true)"
  printf '%s\n' "$out" | awk 'BEGIN { RS = ""; FS = "\n" }
      { delete v
        for (i = 1; i <= NF; i++) { k = $i; sub(/=.*/, "", k); x = $i; sub(/^[^=]*=/, "", x); v[k] = x }
        id = v["Id"]; sub(/\.service$/, "", id); sub(/^karnak-/, "", id)
        u = id; sub(/-.*/, "", u); n = id; sub(/^[^-]*-/, "", n)
        m = (v["MemoryCurrent"] ~ /^[0-9]+$/) ? v["MemoryCurrent"] : "-"
        c = (v["CPUUsageNSec"] ~ /^[0-9]+$/) ? v["CPUUsageNSec"] : "-"
        t = v["ActiveEnterTimestamp"]; sub(/^@/, "", t); if (t !~ /^[0-9]+$/ || v["ActiveState"] != "active") t = 0
        e = (v["UnitFileState"] == "enabled") ? "enabled" : "disabled"
        r = (v["NRestarts"] ~ /^[0-9]+$/) ? v["NRestarts"] : 0
        res = v["Result"]; if (v["ActiveState"] == "failed" && v["ExecMainStatus"] != "75") res = "start-limit-hit"
        printf "%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n", u, n, v["ActiveState"], v["SubState"], e, res, r, m, c, t }'
  exit 0
fi
U="${1:?}"; N="${2:?}"
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad user"; exit 1; }
[[ "$N" =~ ^[a-z0-9_-]{1,32}$ ]] || { echo "bad name"; exit 1; }
UNIT="karnak-$U-$N"
ST="inactive"; systemctl is-active --quiet "$UNIT" 2>/dev/null && ST="active"
EN="disabled"; systemctl is-enabled --quiet "$UNIT" 2>/dev/null && EN="enabled"
echo "$ST $EN"
