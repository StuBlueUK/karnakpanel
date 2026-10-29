<?php
// PhpExtensions — the curated extensions Admin > PHP extensions may install
// (mirror: PHP_EXTS in scripts/php-ext.sh; tests/run.php fails if the two
// drift). Key = ondrej package suffix = module name. Server-wide only: one
// FPM master per version serves every pool, and an extension loaded for one
// pool was seen in the others, so there is no per-account switch.
final class PhpExtensions {
    // group => [ext => what it is for]
    public const CATALOG = [
        'Images' => [
            'imagick' => 'ImageMagick — sharper resizing and more formats (WordPress, Drupal, Nextcloud)',
        ],
        'Caching' => [
            'redis' => 'Redis client — object-cache plugins (needs a Redis server)',
            'memcached' => 'Memcached client — object-cache plugins (needs a Memcached server)',
            'igbinary' => 'Compact serializer used by redis/memcached',
            'msgpack' => 'MessagePack serializer used by redis/memcached',
        ],
        'Data & formats' => [
            'soap' => 'SOAP web services (payment gateways, shipping, ERP plugins)',
            'gmp' => 'Big-number maths (crypto libraries, some payment SDKs)',
            'bz2' => 'bzip2 archives',
            'sqlite3' => 'SQLite databases (file-based apps, some CMS caches)',
            'pgsql' => 'PostgreSQL client (apps whose database is elsewhere)',
            'mongodb' => 'MongoDB client (apps whose database is elsewhere)',
            'yaml' => 'YAML parsing',
            'tidy' => 'HTML clean-up',
            'xmlrpc' => 'XML-RPC (older integrations)',
            'uuid' => 'UUID generation',
            'maxminddb' => 'Fast GeoIP lookups (MaxMind databases)',
        ],
        'Mail & directory' => [
            'imap' => 'Read mailboxes from PHP (helpdesks, ticket systems)',
            'mailparse' => 'Parse raw emails (helpdesks, mail importers)',
            'ldap' => 'LDAP / Active Directory logins',
            'enchant' => 'Spell checking',
        ],
        'Remote access' => [
            'ssh2' => 'SSH/SFTP from PHP (deploy and backup plugins)',
        ],
    ];

    /** Flat list of extension names, catalog order. */
    public static function names(): array {
        return array_merge(...array_map('array_keys', array_values(self::CATALOG)));
    }

    public static function valid(string $e): bool {
        return in_array($e, self::names(), true);
    }
}
