#!/usr/bin/env bash
# mail-reports.sh <domain> <on|off|purge> — DMARC/TLS-RPT report intake for
# one domain. On: dmarc-reports@<d> + tls-reports@<d> map to a spool maildir
# (/var/spool/karnak-reports/<d>) via the virtual_reports router, so reports
# never land in (or count against) a customer mailbox. Off: map lines go
# (new reports bounce), already-spooled mail is dropped. Purge = off + remove
# the spool dir (domain delete). Parsed rows live in the panel DB and are
# owned by the controller. lsearch maps are read per lookup: no reload.
# IDEMPOTENT, fails loudly.
set -euo pipefail
D="${1:?}"; MODE="${2:?}"
[[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad domain"; exit 1; }
[[ "$MODE" == on || "$MODE" == off || "$MODE" == purge ]] || { echo "mode must be on|off|purge"; exit 1; }
MAP=/etc/exim4/virtual/reports
ROOT=/var/spool/karnak-reports
SP="$ROOT/$D"
mkdir -p /etc/exim4/virtual
touch "$MAP"; chmod 644 "$MAP"
ESC="${D//./\\.}"
sed -i "/^dmarc-reports@$ESC: /d;/^tls-reports@$ESC: /d" "$MAP"
if [[ "$MODE" == on ]]; then
  # root:vmail 0750 parent: vmail traverses, customers (and nobody) can't list
  mkdir -p "$ROOT"; chown root:vmail "$ROOT"; chmod 750 "$ROOT"
  # maildir must exist up front or appendfile defers (same lesson as email-add)
  mkdir -p "$SP"/{new,cur,tmp}
  chown -R vmail:vmail "$SP"; chmod 700 "$SP"
  echo "dmarc-reports@$D: $SP" >> "$MAP"
  echo "tls-reports@$D: $SP" >> "$MAP"
  echo "report intake on for $D (dmarc-reports@$D, tls-reports@$D)"
else
  [[ -d "$SP" ]] && find "$SP" -type f -path '*/new/*' -delete 2>/dev/null || true
  [[ -d "$SP" ]] && find "$SP" -type f -path '*/cur/*' -delete 2>/dev/null || true
  if [[ "$MODE" == purge ]]; then
    [[ -d "$SP" && "$(realpath -m "$SP")" == "$ROOT/$D" ]] && rm -rf "$SP"
  fi
  echo "report intake off for $D"
fi
