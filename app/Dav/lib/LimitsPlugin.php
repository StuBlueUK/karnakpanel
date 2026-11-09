<?php
// Per-principal caps (DAV data lives in MariaDB, outside the account's disk
// quota): object size, total objects, total collections.
namespace KarnakDav;

use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\InsufficientStorage;
use Sabre\DAV\INode;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;

final class LimitsPlugin extends ServerPlugin
{
    private Server $server;

    public function initialize(Server $server)
    {
        $this->server = $server;
        $server->on('beforeBind', [$this, 'beforeBind'], 50);
        $server->on('beforeCreateFile', [$this, 'beforeCreateFile'], 50);
        $server->on('beforeWriteContent', [$this, 'beforeWriteContent'], 50);
    }

    public function getPluginName()
    {
        return 'karnak-limits';
    }

    private function me(): string
    {
        $auth = $this->server->getPlugin('auth');
        $p = $auth ? (string)$auth->getCurrentPrincipal() : '';
        if ($p === '') throw new Forbidden('Not authenticated');
        return $p;
    }

    // every new node: an object (depth 4: calendars/<addr>/<cal>/<obj>) or a
    // collection (depth 3: calendars/<addr>/<cal>)
    public function beforeBind($uri)
    {
        $depth = count(explode('/', trim((string)$uri, '/')));
        [$objects, $collections] = Store::usage($this->me());
        if ($depth >= 4 && $objects >= Store::MAX_OBJECTS) {
            throw new InsufficientStorage('Limit of ' . Store::MAX_OBJECTS . ' events and contacts reached');
        }
        if ($depth === 3 && $collections >= Store::MAX_COLLECTIONS) {
            throw new InsufficientStorage('Limit of ' . Store::MAX_COLLECTIONS . ' calendars and address books reached');
        }
        return true;
    }

    public function beforeCreateFile($uri, &$data, $parent, &$modified)
    {
        $this->capSize($data);
        return true;
    }

    public function beforeWriteContent($uri, INode $node, &$data, &$modified)
    {
        $this->capSize($data);
        return true;
    }

    private function capSize(&$data): void
    {
        if (is_resource($data)) {
            $s = stream_get_contents($data, Store::MAX_OBJECT_BYTES + 1);
            $data = $s === false ? '' : $s;
        }
        if (is_string($data) && strlen($data) > Store::MAX_OBJECT_BYTES) {
            throw new InsufficientStorage('Item larger than ' . (Store::MAX_OBJECT_BYTES >> 20) . ' MB');
        }
    }
}
