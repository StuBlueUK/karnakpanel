#!/usr/bin/env bash
# db-caps.sh — per-database-user MariaDB resource caps for the shell side
# (app installers + account importers). app/Models/MysqlDb.php is the PHP
# side; tests/run.php checks both carry the same defaults.
# Sourced: sets DB_CAPS to the GRANT ... WITH clause from the admin settings
#   db_cap_queries_hour / db_cap_updates_hour / db_cap_conn_hour /
#   db_cap_user_conn (Databases page, admin card), defaults below. 0 = no cap.
#   Unreadable settings fall back to the defaults, never to "no caps".
# Run:  db-caps.sh upgrade — users listed in mysql_dbs that still carry the
#   pre-1.5.35 caps exactly (36000 queries / 3600 updates / 360 connections
#   an hour / 25 at once) get the current ones. 360 connections an hour is
#   one PHP request every 10 s: busy sites 500'd with error 1226. Users the
#   admin customised are left alone. Quiet when there is nothing to do.
#   Called by host-converge.sh (once per installed version).
DB_CAP_DEFAULTS=(1000000 100000 36000 25)   # queries/h updates/h connections/h simultaneous
DB_CAP_OLD="36000 3600 360 25"              # max_questions max_updates max_connections max_user_connections
db_caps_clause() {
  local names=(db_cap_queries_hour db_cap_updates_hour db_cap_conn_hour db_cap_user_conn)
  local vals=("${DB_CAP_DEFAULTS[@]}") p out i v
  p="$(cat /root/.skypanel-dbpass 2>/dev/null || true)"
  if [[ -n "$p" ]]; then
    out="$(MYSQL_PWD="$p" mysql -uskypanel skypanel -N -e "SELECT name,value FROM settings WHERE name LIKE 'db\\_cap\\_%';" 2>/dev/null || true)"
    for i in 0 1 2 3; do
      v="$(awk -F'\t' -v n="${names[$i]}" '$1==n{print $2}' <<<"$out")"
      if [[ "$v" =~ ^[0-9]{1,9}$ ]]; then vals[i]=$((10#$v)); fi
    done
  fi
  echo "MAX_QUERIES_PER_HOUR ${vals[0]} MAX_UPDATES_PER_HOUR ${vals[1]} MAX_CONNECTIONS_PER_HOUR ${vals[2]} MAX_USER_CONNECTIONS ${vals[3]}"
}
DB_CAPS="$(db_caps_clause)"
# db_new <db> <user> <pass> — a NEW database + NEW login for an app installer.
# Plain CREATEs: an existing name is someone's (usernames hold "_", so
# "bob"+"x_wp" can equal "bob_x"+"wp"), never adopted or re-passworded.
# Nothing is left behind on refusal. SQL goes on stdin (no password in argv).
# The MariaDB client echoes a failing statement to stderr — with the password
# in it — so only its ERROR line reaches the caller's (software) log.
db_new() {
  local e
  e="$(mysql -uroot 2>&1 >/dev/null <<<"CREATE DATABASE \`$1\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;")" || { grep '^ERROR' <<<"$e" >&2 || true; return 1; }
  e="$(mysql -uroot 2>&1 >/dev/null <<<"CREATE USER '$2'@'localhost' IDENTIFIED BY '$3'; GRANT ALL PRIVILEGES ON \`$1\`.* TO '$2'@'localhost'; GRANT USAGE ON *.* TO '$2'@'localhost' WITH $DB_CAPS; FLUSH PRIVILEGES;")" && return 0
  grep '^ERROR' <<<"$e" >&2 || true
  mysql -uroot <<<"DROP DATABASE IF EXISTS \`$1\`;" || true
  return 1
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  set -uo pipefail
  [[ "${1:-}" == "upgrade" ]] || { echo "usage: db-caps.sh upgrade"; exit 2; }
  P="$(cat /root/.skypanel-dbpass 2>/dev/null || true)"
  [[ -n "$P" ]] || exit 0
  USERS="$(MYSQL_PWD="$P" mysql -uskypanel skypanel -N -e "SELECT DISTINCT db_user FROM mysql_dbs;" 2>/dev/null)" \
    || { echo "db-caps: cannot read mysql_dbs"; exit 1; }
  rc=0; n=0
  while read -r u; do
    [[ "$u" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || continue
    cur="$(mysql -uroot -N -e "SELECT CONCAT_WS(' ',max_questions,max_updates,max_connections,max_user_connections) FROM mysql.user WHERE User='$u' AND Host='localhost';" 2>/dev/null || true)"
    [[ "$cur" == "$DB_CAP_OLD" ]] || continue
    if mysql -uroot -e "GRANT USAGE ON *.* TO '$u'@'localhost' WITH $DB_CAPS;" 2>/dev/null; then n=$((n+1)); else echo "db-caps: could not raise $u"; rc=1; fi
  done <<<"$USERS"
  if (( n )); then
    mysql -uroot -e "FLUSH USER_RESOURCES;" 2>/dev/null || rc=1
    echo "db-caps: raised $n database user(s) from the old 360 connections/hour caps ($DB_CAPS)"
  fi
  exit $rc
fi
