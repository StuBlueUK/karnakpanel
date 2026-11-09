<?php
// KarnakPanel CalDAV/CardDAV server (SabreDAV, vendored + pinned in vendor/).
// Served at https://<panel host>:8091/dav/ by the Webmail vhost's /dav/
// context, in its own php-fpm pool as karnak-dav (dav-setup.sh). Never reached
// through the panel docroot: app/ is not web-served.
// Login = full mailbox address + mailbox password.
declare(strict_types=1);

// Basic auth carries the mailbox password on every request: TLS only.
if (($_SERVER['HTTPS'] ?? '') !== 'on') {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "HTTPS required\n";
    exit;
}
// cheap early refusal before anything is read (LimitsPlugin re-checks the body)
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 4 * 1048576) {
    http_response_code(413);
    exit;
}

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/lib/Store.php';
require __DIR__ . '/lib/AuthBackend.php';
require __DIR__ . '/lib/PrincipalBackend.php';
require __DIR__ . '/lib/LimitsPlugin.php';

use KarnakDav\AuthBackend;
use KarnakDav\LimitsPlugin;
use KarnakDav\PrincipalBackend;
use KarnakDav\Store;
use Sabre\CalDAV;
use Sabre\CardDAV;
use Sabre\DAV;
use Sabre\DAVACL;

date_default_timezone_set('UTC');

try {
    $pdo = Store::pdo();
} catch (\Throwable $e) {
    error_log('karnak-dav: database unavailable: ' . $e->getMessage());
    http_response_code(503);
    header('Retry-After: 60');
    exit;
}

$principals = new PrincipalBackend();
$tree = [
    new DAVACL\PrincipalCollection($principals),
    new CalDAV\CalendarRoot($principals, new CalDAV\Backend\PDO($pdo)),
    new CardDAV\AddressBookRoot($principals, new CardDAV\Backend\PDO($pdo)),
];

$server = new DAV\Server($tree);
$server->setBaseUri('/dav/');
$server->debugExceptions = false;

$authPlugin = new DAV\Auth\Plugin(new AuthBackend());
$server->addPlugin($authPlugin);
// right after auth (priority 10): scope the principal backend to the caller
$server->on('beforeMethod:*', function () use ($authPlugin, $principals) {
    $principals->setCurrent((string)$authPlugin->getCurrentPrincipal());
}, 11);

$acl = new DAVACL\Plugin();
$acl->hideNodesFromListings = true;
$acl->allowUnauthenticatedAccess = false;
$server->addPlugin($acl);
$server->addPlugin(new CalDAV\Plugin());
$server->addPlugin(new CardDAV\Plugin());
$server->addPlugin(new DAV\Sync\Plugin());
$server->addPlugin(new LimitsPlugin());

$server->start();
