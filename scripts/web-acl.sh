# web-acl.sh — sourced (never run): which uids get ACL entries on customer
# files. Sourced by as-user.sh, so every script that writes into a home has it.
#   nobody        OLS's own workers: serve static files, so rX INSIDE docroots
#                 and --x on the dirs above them — nothing else in a home.
#   karnak-panel  the panel worker (panel-user.sh): reads the whole home
#                 (file manager, stats, cron + PHP logs).
# Until the panel has moved off nobody (panel-id.sh), nobody IS the panel and
# keeps its old home-wide read: both uids everywhere, as before ("unsplit").
# WEB_ACL_PANEL=nobody forces unsplit (panel-user.sh revert puts nobody's
# reads back BEFORE the worker returns to it).
# Paths given to acl_for are literal (fm_resolve refuses links), so a path
# reached through a link is classed by its literal name — never followed.
. "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/panel-id.sh"
if [[ "$PANEL_U" == karnak-panel && "${WEB_ACL_PANEL:-}" != nobody ]]; then WEB_ACL_SPLIT=1; else WEB_ACL_SPLIT=0; fi
if getent passwd karnak-panel >/dev/null 2>&1; then WEB_ACL_KP=1; else WEB_ACL_KP=0; fi
WEB_ACL_VH=/usr/local/lsws/conf/vhosts

# acl_web <perm>: inside a docroot — the web server and the panel
acl_web() { if (( WEB_ACL_KP )); then echo "u:nobody:$1,u:karnak-panel:$1"; else echo "u:nobody:$1"; fi; }
# acl_panel <perm>: outside every docroot — the panel only
acl_panel() { if (( WEB_ACL_SPLIT )); then echo "u:karnak-panel:$1"; else acl_web "$1"; fi; }
# acl_anc <perm>: a dir above a docroot — the web server only traverses it
acl_anc() { if (( WEB_ACL_SPLIT )); then echo "u:nobody:x,u:karnak-panel:$1"; else acl_web "$1"; fi; }

# user_docroots <home>: docroots under that home, one per line — vhost
# docRoot lines (literal paths, no links, no ..) plus ~/public_html
user_docroots() {
  local H="${1%/}" VC DOC
  for VC in "$WEB_ACL_VH"/*/vhost.conf; do
    [[ -f "$VC" ]] || continue
    DOC="$(sed -nE 's#^docRoot[[:space:]]+(/home/[^[:space:]]+)[[:space:]]*$#\1#p' "$VC" | head -1)"
    DOC="${DOC%/}"
    [[ "$DOC" == "$H/"* && "$DOC" != *..* && -d "$DOC" && "$(readlink -f -- "$DOC")" == "$DOC" ]] && echo "$DOC"
  done
  [[ -d "$H/public_html" && ! -L "$H/public_html" ]] && echo "$H/public_html"
  return 0
}
# acl_for <home> <path> <perm>: acl_web inside a docroot of <home>, else acl_panel
acl_for() {
  local P="${2%/}" d
  while IFS= read -r d; do
    [[ "$P" == "$d" || "$P" == "$d/"* ]] && { acl_web "$3"; return 0; }
  done < <(user_docroots "$1")
  acl_panel "$3"
}
# acl_panel_only <file>: a panel-only file (logs) that may still carry the
# old u:nobody entry — dropped once split (caller owns or is root on it)
acl_panel_only() {
  setfacl -m "$(acl_panel r),m::r" -- "$1" 2>/dev/null || true
  (( WEB_ACL_SPLIT )) && { setfacl -x u:nobody -- "$1" 2>/dev/null || true; }
  return 0
}
