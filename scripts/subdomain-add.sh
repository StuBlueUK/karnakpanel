#!/usr/bin/env bash
# subdomain-add.sh <fqdn> <owner> <php_version> — a subdomain is a normal panel
# domain row with its own docroot (/home/<user>/<fqdn>/public_html, same
# layout as every other domain). DNS is an A record in the PARENT zone, which
# the controller writes; this script only builds the site side.
set -euo pipefail
. "$(dirname "$(readlink -f "$0")")/php-versions.sh"
D="${1:?}"; U="${2:?}"; PHPV="${3:-$PHP_DEFAULT}"
[[ "$D" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || { echo "bad domain"; exit 1; }
[[ "$U" =~ ^[a-z][a-z0-9_]{2,31}$ ]] || { echo "bad username"; exit 1; }
php_known "$PHPV" || { echo "bad php version"; exit 1; }
[[ ! -e "/home/$U/$D" ]] || { echo "docroot path already exists: /home/$U/$D"; exit 1; }
"$(dirname "$0")/vhost-add.sh" "$D" "$U" "$PHPV"
echo "subdomain $D provisioned"
