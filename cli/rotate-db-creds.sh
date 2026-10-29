#!/usr/bin/env bash
# rotate-db-creds.sh — rotate the panel's MariaDB passwords (skypanel app user
# + skypanel_prov provisioning user). Root-only via `karnakpanel
# rotate-db-creds`; deliberately NOT in scripts/ (the panel worker may sudo
# anything there, and it has no business rotating its own credentials).
#
# Updates, all-or-nothing: MariaDB users, /root/.skypanel-dbpass +
# /root/.skypanel-provpass (scripts read these at runtime), and config.php in
# EVERY release dir (rollback targets must keep working) plus a pre-release
# live panel dir. Verifies the new creds as the panel worker (PANEL_U) before
# declaring success; any failure after the ALTER rolls MariaDB back.
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo "run as root"; exit 1; }
SKY=/usr/local/skypanel
. "$SKY/scripts/panel-id.sh" 2>/dev/null || { PANEL_U=nobody; PANEL_G=nogroup; }  # the panel worker
DBF=/root/.skypanel-dbpass; PVF=/root/.skypanel-provpass
LIVE="$SKY/panel/config/config.php"
[[ -f "$LIVE" ]] || { echo "no live config: $LIVE"; exit 1; }

# current values come from the LIVE config (the source the panel really uses)
read -r OLD_DB OLD_PV < <(php -r '$c = include $argv[1]; echo $c["db"]["pass"], " ", $c["db"]["provision_pass"], "\n";' "$LIVE")
[[ "$OLD_DB" =~ ^[A-Za-z0-9]+$ && "$OLD_PV" =~ ^[A-Za-z0-9]+$ ]] || { echo "current passwords not alphanumeric — rotate by hand"; exit 1; }
NEW_DB="$(openssl rand -hex 24)"; NEW_PV="$(openssl rand -hex 24)"

CONFIGS=()
while IFS= read -r -d '' f; do CONFIGS+=("$f"); done < <(find "$SKY" -path '*/storage' -prune -o -path '*/config/config.php' -type f -print0)
STAGED=()
cleanup_staged() { for s in "${STAGED[@]}"; do rm -f "$s"; done; }
trap cleanup_staged EXIT
for f in "${CONFIGS[@]}"; do
  t="$f.rotate.$$"
  # exact quoted-literal swap; then prove the result parses to the new values
  OD="$OLD_DB" OP="$OLD_PV" ND="$NEW_DB" NP="$NEW_PV" php -r '
    $s = file_get_contents($argv[1]);
    $s = str_replace("\x27".getenv("OD")."\x27", "\x27".getenv("ND")."\x27", $s);
    $s = str_replace("\x27".getenv("OP")."\x27", "\x27".getenv("NP")."\x27", $s);
    file_put_contents($argv[2], $s);' "$f" "$t"
  STAGED+=("$t")
  got="$(php -r '$c = include $argv[1]; echo $c["db"]["pass"], " ", $c["db"]["provision_pass"];' "$t")"
  if [[ "$got" != "$NEW_DB $NEW_PV" ]]; then
    # a rollback target with different (older) creds: leave it, say so
    echo "WARN: $f holds other creds — left unchanged"; rm -f "$t"; continue
  fi
  chown root:"$PANEL_G" "$t"; chmod 640 "$t"
done
printf '%s\n' "$NEW_DB" > "$DBF.rotate.$$"; printf '%s\n' "$NEW_PV" > "$PVF.rotate.$$"
chmod 600 "$DBF.rotate.$$" "$PVF.rotate.$$"; STAGED+=("$DBF.rotate.$$" "$PVF.rotate.$$")

alter() { mysql -uroot -e "ALTER USER 'skypanel'@'localhost' IDENTIFIED BY '$1'; ALTER USER 'skypanel_prov'@'localhost' IDENTIFIED BY '$2';"; }
alter "$NEW_DB" "$NEW_PV" || { echo "ALTER USER failed — nothing changed"; exit 1; }
rollback() {
  echo "ROLLING BACK MariaDB passwords"; alter "$OLD_DB" "$OLD_PV" && echo "rolled back (configs untouched or restored)"
}
# swap files in (rename is atomic per file)
for f in "${CONFIGS[@]}"; do
  [[ -f "$f.rotate.$$" ]] || continue
  cp -p "$f" "$f.pre-rotate.$$"
  mv -f "$f.rotate.$$" "$f"
done
mv -f "$DBF.rotate.$$" "$DBF"; mv -f "$PVF.rotate.$$" "$PVF"

# verify as the worker, through the real config, both handles
if sudo -u "$PANEL_U" php -r '$c = include $argv[1]; $d = $c["db"];
     new PDO("mysql:host=".$d["host"].";dbname=".$d["name"], $d["user"], $d["pass"]);
     new PDO("mysql:host=".$d["host"], $d["provision_user"], $d["provision_pass"]); echo "ok";' "$LIVE" 2>/dev/null | grep -qx ok \
   && mysql -uskypanel -p"$(cat "$DBF")" skypanel -Nse "SELECT 1" >/dev/null 2>&1; then
  for f in "${CONFIGS[@]}"; do rm -f "$f.pre-rotate.$$"; done
  echo "rotated: skypanel + skypanel_prov (${#CONFIGS[@]} config file(s), pass files updated)"
else
  for f in "${CONFIGS[@]}"; do [[ -f "$f.pre-rotate.$$" ]] && mv -f "$f.pre-rotate.$$" "$f"; done
  printf '%s\n' "$OLD_DB" > "$DBF"; printf '%s\n' "$OLD_PV" > "$PVF"
  rollback; exit 1
fi
