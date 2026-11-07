<?php
// KarnakPanel CalDAV/CardDAV — shared DB access (server + reconcile CLI).
// The DAV database (karnakdav) holds the SabreDAV tables + karnak_principals,
// which binds each principal (mailbox address) to skypanel email_accounts.id:
// a deleted-then-recreated address gets a NEW id, so its old calendars and
// contacts are purged before the new owner ever sees them.
namespace KarnakDav;

final class Store
{
    public const CONFIG = '/etc/karnak-dav/config.php';
    // per-principal caps: DAV data is not counted in the account's disk quota
    public const MAX_OBJECT_BYTES = 2097152;   // one event / contact (incl. photo)
    public const MAX_OBJECTS = 20000;          // events + contacts together
    public const MAX_COLLECTIONS = 30;         // calendars + address books together

    public static function pdo(): \PDO
    {
        static $pdo = null;
        if ($pdo instanceof \PDO) return $pdo;
        $cfg = require self::CONFIG;
        $pdo = new \PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    }

    /** Mailbox row for an address, or null. Only the columns the DB user may read. */
    public static function mailbox(string $address): ?array
    {
        if (!self::validAddress($address)) return null;
        [$local, $domain] = explode('@', $address, 2);
        $st = self::pdo()->prepare(
            'SELECT e.id, e.password_hash, u.status, d.mail_handling
               FROM skypanel.email_accounts e
               JOIN skypanel.domains d ON d.id = e.domain_id
               JOIN skypanel.users u ON u.id = e.user_id
              WHERE e.localpart = ? AND d.domain = ?'
        );
        $st->execute([$local, $domain]);
        $r = $st->fetch();
        return $r ?: null;
    }

    public static function validAddress(string $a): bool
    {
        return strlen($a) <= 254 && (bool)preg_match('/^[a-z0-9._-]{1,64}@[a-z0-9.-]+\.[a-z]{2,}$/', $a);
    }

    public static function principalUri(string $address): string
    {
        return 'principals/' . $address;
    }

    /**
     * Bind the principal to the mailbox id; purge data left by a previous
     * mailbox of the same address. Then make sure a default calendar and
     * address book exist (phones expect at least one of each).
     */
    public static function bind(string $address, int $accountId): void
    {
        $pdo = self::pdo();
        $st = $pdo->prepare('SELECT account_id FROM karnak_principals WHERE address = ?');
        $st->execute([$address]);
        $cur = $st->fetchColumn();
        if ($cur !== false && (int)$cur === $accountId) return;
        if ($cur !== false) self::purge($address);
        // a phone's first sync is several parallel requests: every insert here must tolerate a twin
        $pdo->prepare('INSERT INTO karnak_principals(address, account_id) VALUES(?, ?) ON DUPLICATE KEY UPDATE account_id = VALUES(account_id)')->execute([$address, $accountId]);
        $p = self::principalUri($address);
        $st = $pdo->prepare('SELECT COUNT(*) FROM calendarinstances WHERE principaluri = ?');
        $st->execute([$p]);
        if ((int)$st->fetchColumn() === 0) {
            $pdo->prepare('INSERT INTO calendars(synctoken, components) VALUES(1, ?)')->execute(['VEVENT,VTODO']);
            $cid = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT IGNORE INTO calendarinstances(calendarid, principaluri, access, displayname, uri, calendarcolor) VALUES(?, ?, 1, ?, ?, ?)');
            $ins->execute([$cid, $p, 'Calendar', 'default', '#3b82f6']);
            if ($ins->rowCount() === 0) $pdo->prepare('DELETE FROM calendars WHERE id = ?')->execute([$cid]);
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM addressbooks WHERE principaluri = ?');
        $st->execute([$p]);
        if ((int)$st->fetchColumn() === 0) {
            $pdo->prepare('INSERT IGNORE INTO addressbooks(principaluri, displayname, uri, synctoken) VALUES(?, ?, ?, 1)')
                ->execute([$p, 'Contacts', 'default']);
        }
    }

    /** Remove every calendar, contact and the binding of one address. */
    public static function purge(string $address): void
    {
        $pdo = self::pdo();
        $p = self::principalUri($address);
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT calendarid FROM calendarinstances WHERE principaluri = ? AND access = 1');
            $st->execute([$p]);
            foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $cid) {
                foreach (['calendarobjects', 'calendarchanges', 'calendarinstances'] as $t) {
                    $pdo->prepare("DELETE FROM $t WHERE calendarid = ?")->execute([(int)$cid]);
                }
                $pdo->prepare('DELETE FROM calendars WHERE id = ?')->execute([(int)$cid]);
            }
            foreach (['calendarinstances', 'calendarsubscriptions', 'schedulingobjects'] as $t) {
                $pdo->prepare("DELETE FROM $t WHERE principaluri = ?")->execute([$p]);
            }
            $st = $pdo->prepare('SELECT id FROM addressbooks WHERE principaluri = ?');
            $st->execute([$p]);
            foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $aid) {
                foreach (['cards', 'addressbookchanges'] as $t) {
                    $pdo->prepare("DELETE FROM $t WHERE addressbookid = ?")->execute([(int)$aid]);
                }
            }
            $pdo->prepare('DELETE FROM addressbooks WHERE principaluri = ?')->execute([$p]);
            $pdo->prepare('DELETE FROM karnak_principals WHERE address = ?')->execute([$address]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Usage of one principal: [objects, collections]. */
    public static function usage(string $principalUri): array
    {
        $pdo = self::pdo();
        $st = $pdo->prepare(
            'SELECT (SELECT COUNT(*) FROM calendarobjects o JOIN calendarinstances i ON i.calendarid = o.calendarid AND i.access = 1 WHERE i.principaluri = ?)
                  + (SELECT COUNT(*) FROM cards c JOIN addressbooks a ON a.id = c.addressbookid WHERE a.principaluri = ?) AS objects,
                    (SELECT COUNT(*) FROM calendarinstances WHERE principaluri = ?)
                  + (SELECT COUNT(*) FROM addressbooks WHERE principaluri = ?) AS collections'
        );
        $st->execute([$principalUri, $principalUri, $principalUri, $principalUri]);
        $r = $st->fetch();
        return [(int)$r['objects'], (int)$r['collections']];
    }
}
