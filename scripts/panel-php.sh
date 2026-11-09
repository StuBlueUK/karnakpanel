#!/usr/bin/env bash
# panel-php.sh — SOURCED. PANEL_PHP '<php code>' runs panel code as root
# against the live panel tree (prepared statements, validators). Arguments
# go in through the environment only (getenv), never spliced into the code.
PANEL_PHP() { ( cd /usr/local/skypanel/panel && php -r "
spl_autoload_register(function(\$c){foreach (['app/Core/','app/Controllers/','app/Models/'] as \$d){\$f=\"\$d\$c.php\"; if(file_exists(\$f)){require_once \$f; return;}}});
Config::load('config/config.php'); $1" ); }
