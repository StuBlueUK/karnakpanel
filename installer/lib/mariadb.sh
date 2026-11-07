#!/usr/bin/env bash
# MariaDB
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
apt-get install -y mariadb-server mariadb-client
# MariaDB 11.x (Debian 13) moved the mysql/mysqldump names into -compat;
# every panel script calls them by those names.
command -v mysql >/dev/null 2>&1 || apt-get install -y mariadb-client-compat
command -v mysql >/dev/null 2>&1 && command -v mysqldump >/dev/null 2>&1 \
  || { echo "FATAL: mysql/mysqldump commands missing after MariaDB install"; exit 1; }
systemctl enable mariadb; systemctl start mariadb
mysql -uroot -e "DELETE FROM mysql.user WHERE User=''; DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost','127.0.0.1','::1'); DROP DATABASE IF EXISTS test; FLUSH PRIVILEGES;" || true
# tune for small VPS
cat > /etc/mysql/mariadb.conf.d/60-skypanel.cnf <<'EOF'
[mysqld]
bind-address = 127.0.0.1
max_connections = 150
innodb_buffer_pool_size = 256M
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci
EOF
systemctl restart mariadb
ufw deny 3306/tcp || true
