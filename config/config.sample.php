<?php
return [
    'app' => ['name' => 'KarnakPanel', 'env' => 'production', 'url' => 'https://panel.example.com:8090',
              'webmail_url' => '',  // e.g. 'http://SERVER-IP:8091' — set by installer roundcube module
              'phpmyadmin_url' => '',  // e.g. 'https://SERVER-IP:8092' — set by installer phpmyadmin module
              'donate_url' => ''],  // e.g. Ko-fi/PayPal link — shows a ❤ Support button in the sidebar
    'db' => ['host'=>'localhost','name'=>'skypanel','user'=>'skypanel','pass'=>'CHANGE_ME',
              'provision_user'=>'skypanel_prov','provision_pass'=>'CHANGE_ME_TOO'],
    'paths' => [
        'logs' => '/var/log/karnakpanel/panel.log',
        'scripts' => '/usr/local/skypanel/scripts',
        'sessions' => '/var/lib/skypanel/sessions',
    ],
    'security' => ['allowed_bin' => ['sudo /usr/local/skypanel/scripts/']],
    'ols' => ['port' => 8090, 'admin_port' => 7080],
];
