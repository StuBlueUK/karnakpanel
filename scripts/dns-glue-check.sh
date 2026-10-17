#!/usr/bin/env bash
# dns-glue-check.sh — verify nameserver glue exists at the parent for the
# panel domain's apex, cache the verdict in settings (glue_ok 1/0 +
# glue_checked_at). The dashboard checklist reads the cache — never do live
# DNS on page loads. Nightly via cron. Fails safe: any DNS doubt leaves the
# old verdict (or 0 when never checked) rather than flipping green on noise.
#
# Method: resolve through the box's normal resolver (NOT direct-to-TLD: some
# providers filter outbound 53 to arbitrary servers — seen live on netcup).
# If a public-path recursive can return NS <apex> = ns1/ns2.<apex> AND the
# matching A glue pointing here, the delegation chain is functional (a
# resolver can only walk there via the parent referral). Fails safe: resolver
# trouble leaves the old verdict alone (timestamp still recorded).
# Limitation: apex = last two labels (wrong for co.uk-style suffixes — the
# check then fails closed, step stays unticked, honestly).
set -euo pipefail
command -v dig >/dev/null 2>&1 || { echo "no dig (dnsutils missing)"; exit 1; }
PASS="$(cat /root/.skypanel-dbpass 2>/dev/null || echo "")"
[[ -n "$PASS" ]] || { echo "no panel db pass (/root/.skypanel-dbpass)"; exit 1; }
PANEL="$(MYSQL_PWD="$PASS" mysql -uskypanel skypanel -N -e "SELECT value FROM settings WHERE name='panel_domain' LIMIT 1;" 2>/dev/null || true)"
[[ "$PANEL" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "no panel_domain setting yet"; exit 1; }
# apex: strip one left label when present (panel.example.com -> example.com)
APEX="$PANEL"
if [[ "$PANEL" =~ ^[^.]+\.([^.]+\.[^.]+)$ ]]; then APEX="${BASH_REMATCH[1]}"; fi
PUBIP="$(hostname -I 2>/dev/null | awk '{print $1}')"
[[ -n "$PUBIP" ]] || { echo "no local IP"; exit 1; }
# NOTE: box-local primary IP (same source as server-ip.sh). Behind NAT it
# never matches public glue — the check then fails closed (honest), never a
# false green.
mark() { # mark <0|1> <note> — records verdict + WHEN we looked
  MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "INSERT INTO settings(name,value) VALUES('glue_ok','$1'),('glue_checked_at','$(date -Is)') ON DUPLICATE KEY UPDATE value=VALUES(value);" 2>/dev/null || true
  echo "$2"
}
stamp() { # stamp <note> — resolver trouble: record the LOOK, keep old verdict
  MYSQL_PWD="$PASS" mysql -uskypanel skypanel -e "INSERT INTO settings(name,value) VALUES('glue_checked_at','$(date -Is)') ON DUPLICATE KEY UPDATE value=VALUES(value);" 2>/dev/null || true
  echo "$1"; exit 1
}
NS="$(timeout 15 dig +short +time=5 +tries=2 NS "$APEX." 2>/dev/null | tr 'A-Z' 'a-z' || true)"
[[ -n "$NS" ]] || stamp "glue UNKNOWN ($APEX): resolver gave nothing"
echo "$NS" | grep -qiE "^ns1\.${APEX}\.$" || { mark 0 "glue MISSING ($APEX): NS not delegated here"; exit 1; }
echo "$NS" | grep -qiE "^ns2\.${APEX}\.$" || { mark 0 "glue MISSING ($APEX): NS not delegated here"; exit 1; }
G1="$(timeout 15 dig +short +time=5 +tries=2 A "ns1.$APEX." 2>/dev/null | head -1 || true)"
G2="$(timeout 15 dig +short +time=5 +tries=2 A "ns2.$APEX." 2>/dev/null | head -1 || true)"
if [[ "$G1" == "$PUBIP" && "$G2" == "$PUBIP" ]]; then mark 1 "glue OK ($APEX): ns1/ns2 point here"; else mark 0 "glue MISSING ($APEX): no matching A glue"; fi
