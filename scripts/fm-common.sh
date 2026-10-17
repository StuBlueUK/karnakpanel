#!/usr/bin/env bash
# fm-common.sh — shared guards for the file-manager scripts (file-*.sh).
# SOURCED, never run for effect (running it directly only defines functions).
# One copy of the containment doctrine instead of one per script:
#   - fm_name_ok:    one path component, charset-gated (dotfiles, spaces and
#                    ()+@,~ allowed; no leading '-', no trailing space),
#                    never "." or ".."
#                    (a "." name makes the resolver return the PARENT dir —
#                    rm -rf on a whole site folder; refused explicitly)
#   - fm_rel_ok:     a relative dir path (same charset + '/', no "..")
#   - fm_resolve:    component walk, no symlinks anywhere, trailing-slash
#                    boundary (realpath+prefix is NOT containment)
#   - fm_panel_path: panel-owned dot paths inside a home (never user-editable
#                    through the file manager, whatever the operation)
#   - fm_live_root:  a live vhost's <domain>/ or <domain>/public_html (domain
#                    delete owns those — it tears down vhost/DNS/mail too)

# keep in sync with Helpers::fmName() (PHP)
FM_NAME_RE='^[a-zA-Z0-9_.][a-zA-Z0-9_. ()+@,~-]{0,127}$'
FM_REL_RE='^[a-zA-Z0-9_. ()+@,~/-]{0,1024}$'

fm_name_ok() {
  [[ "$1" != "." && "$1" != ".." && "$1" != *" " && "$1" =~ $FM_NAME_RE ]]
}

fm_rel_ok() {
  [[ "$1" != *".."* && "$1" != /* && "$1" =~ $FM_REL_RE ]]
}

# trash entry ids: <epoch>-<8 hex>
FM_TRASH_ID_RE='^[0-9]{9,11}-[0-9a-f]{8}$'

# fm_resolve <base> <rel> <name> — prints the canonical path; fails closed.
fm_resolve() {
  local base="${1%/}" rel="$2" name="$3" cur part
  [[ -n "$base" && "$base" != "/" ]] || return 1
  [[ "$rel" != *".."* && "$rel" != /* && "$name" != *"/"* ]] || return 1
  [[ "$name" != "." && "$name" != ".." ]] || return 1
  cur="$base"
  local IFS='/'
  local -a parts
  read -ra parts <<< "$rel/$name"
  for part in "${parts[@]}"; do
    case "$part" in ""|".") continue;; "..") return 1;; esac
    cur="$cur/$part"
    [[ -L "$cur" ]] && return 1
  done
  [[ "$cur" != "$base" && "$cur" != "$base"/* ]] && return 1
  printf '%s' "$cur"
}

# fm_resolve_leaf <base> <rel> <name> — like fm_resolve, but the LAST
# component may itself be a symlink (delete/trash act on the link, never its
# target: rm/mv without a trailing slash don't follow). Parent walk unchanged.
fm_resolve_leaf() {
  local p
  fm_name_ok "$3" || return 1
  p="$(fm_resolve "$1" "$2" "")" || return 1
  printf '%s/%s' "$p" "$3"
}

# fm_panel_path <abs path> <user> — true for panel-owned paths in the home.
fm_panel_path() {
  local r="${1#/home/$2/}"
  [[ "$r" != "$1" ]] || return 1
  case "$r" in
    .ssh|.ssh/*|.php-sessions|.php-sessions/*|.skypanel-cron|.skypanel-cron/*|.trash|.trash/*) return 0 ;;
  esac
  return 1
}

# fm_live_root <abs path> <user> — true for a live domain's folder or docroot.
fm_live_root() {
  local re="^/home/$2/([a-z0-9.-]+\.[a-z]{2,})(/public_html)?$"
  [[ "$1" =~ $re ]] || return 1
  grep -q "virtualhost ${BASH_REMATCH[1]} {" /usr/local/lsws/conf/httpd_config.conf 2>/dev/null
}
