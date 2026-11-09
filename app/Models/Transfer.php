<?php
declare(strict_types=1);
// Account Transfer (docs/account-transfer-spec.md §3): one hosting account's
// panel rows out of the SOURCE as one JSON document, back in on the TARGET
// with every id remapped in ONE transaction (all or nothing). Runs as root
// through PANEL_PHP (transfer-serve.sh / transfer-pull.sh), never from a web
// request with untrusted input.
//
// Every table in schema.sql is classified here; tests/run.php fails when a
// new table isn't, so nothing is silently left behind.
class Transfer {
    // moved with the account, in dependency (insert) order.
    //   by:    how rows are picked on export — 'id' (the user row), 'user_id',
    //          or a parent column (rows whose <col> is in that parent's ids)
    //   remap: column => table whose new ids replace the old ones
    public const MOVED = [
        'users'              => ['by' => 'id',          'remap' => []],
        'domains'            => ['by' => 'user_id',     'remap' => ['user_id' => 'users']],
        'dns_records'        => ['by' => 'domain_id',   'remap' => ['domain_id' => 'domains']],
        'domain_aliases'     => ['by' => 'user_id',     'remap' => ['user_id' => 'users', 'domain_id' => 'domains']],
        'email_accounts'     => ['by' => 'user_id',     'remap' => ['user_id' => 'users', 'domain_id' => 'domains']],
        'email_forwards'     => ['by' => 'user_id',     'remap' => ['user_id' => 'users', 'domain_id' => 'domains']],
        'email_filters'      => ['by' => 'user_id',     'remap' => ['user_id' => 'users', 'domain_id' => 'domains']],
        'email_lists'        => ['by' => 'user_id',     'remap' => ['user_id' => 'users', 'domain_id' => 'domains']],
        'email_list_members' => ['by' => 'list_id',     'remap' => ['list_id' => 'email_lists']],
        'ftp_accounts'       => ['by' => 'user_id',     'remap' => ['user_id' => 'users']],
        'mysql_dbs'          => ['by' => 'user_id',     'remap' => ['user_id' => 'users']],
        'cron_jobs'          => ['by' => 'user_id',     'remap' => ['user_id' => 'users']],
        'app_daemons'        => ['by' => 'user_id',     'remap' => ['user_id' => 'users']],
        'site_installs'      => ['by' => 'user_id',     'remap' => ['user_id' => 'users', 'domain_id' => 'domains']],
        'builder_sites'      => ['by' => 'user_id',     'remap' => ['user_id' => 'users', 'domain_id' => 'domains']],
        'staging_clones'     => ['by' => 'user_id',     'remap' => ['user_id' => 'users', 'live_install_id' => 'site_installs', 'staging_domain_id' => 'domains']],
        'staging_snapshots'  => ['by' => 'clone_id',    'remap' => ['clone_id' => 'staging_clones']],
        'app_versions'       => ['by' => 'install_id',  'remap' => ['install_id' => 'site_installs'], 'noid' => true],
        'fleet_snapshots'    => ['by' => 'install_id',  'remap' => ['install_id' => 'site_installs']],
    ];
    // left behind on purpose (shown on the summary screen)
    public const DROPPED = [
        'user_passkeys'   => 'tied to the old panel host name: re-add after the move (TOTP still works)',
        'api_tokens'      => 'may be IP-pinned and the API host changes: issue new ones',
        'preview_tokens'  => 'short-lived share links',
        'backups'         => 'backup files stay on the old server',
        'notifications'   => 'history', 'bandwidth_daily' => 'history', 'disk_daily' => 'history',
        'disk_tree_cache' => 'rebuilt on the new server', 'login_attempts' => 'history',
        'dmarc_reports'   => 'history', 'dmarc_records' => 'history', 'tlsrpt_reports' => 'history',
        'dmarc_ignored'   => 'history', 'mail_alerts' => 'history', 'form_alerts' => 'history', 'mail_scores' => 'rechecked on the new server',
        'mail_imports'    => 'finished import jobs',
        'branding'        => 'reseller branding: resellers are not moved in v1',
    ];
    // server-wide, never per account
    public const GLOBAL = ['packages', 'settings', 'applied_migrations', 'server_stats', 'import_jobs',
                           'blacklist_status', 'transfer_jobs', 'transfer_items'];

    // ------------------------------------------------------------- source
    private static function userRow(string $u): ?array {
        $r = Database::query("SELECT * FROM users WHERE username=? AND role='user'", [$u])->fetch();
        return $r ?: null;
    }
    public static function movable(string $u): bool {
        if (!preg_match('/^[a-z][a-z0-9_]{2,31}$/', $u) || !self::userRow($u)) return false;
        $pw = function_exists('posix_getpwnam') ? posix_getpwnam($u) : false;
        return $pw ? ($pw['uid'] >= 1000 && $pw['dir'] === "/home/$u") : is_dir("/home/$u");
    }
    public static function ownsDomain(string $u, string $d): bool {
        return (bool)Database::query("SELECT 1 FROM domains d JOIN users u ON u.id=d.user_id WHERE u.username=? AND u.role='user' AND d.domain=?", [$u, $d])->fetch();
    }
    public static function ownsDb(string $u, string $db): bool {
        return (bool)Database::query("SELECT 1 FROM mysql_dbs m JOIN users u ON u.id=m.user_id WHERE u.username=? AND u.role='user' AND m.db_name=?", [$u, $db])->fetch();
    }
    public static function dbUsers(string $u): array {
        return array_column(Database::query("SELECT DISTINCT m.db_user FROM mysql_dbs m JOIN users u ON u.id=m.user_id WHERE u.username=? AND u.role='user' ORDER BY 1", [$u])->fetchAll(), 'db_user');
    }
    public static function ftpLogins(string $u): array {
        return array_column(Database::query("SELECT f.ftp_user FROM ftp_accounts f JOIN users u ON u.id=f.user_id WHERE u.username=? AND u.role='user' ORDER BY 1", [$u])->fetchAll(), 'ftp_user');
    }
    public static function markSuspended(string $u, bool $on): void {
        Database::query("UPDATE users SET status=? WHERE username=? AND role='user'", [$on ? 'suspended' : 'active', $u]);
    }
    // the account picker on the target: what each account is, and how big
    public static function accounts(): array {
        $out = [];
        foreach (Database::query("SELECT u.id, u.username, u.status, u.disk_used_mb, p.name AS package
                                  FROM users u LEFT JOIN packages p ON p.id=u.package_id
                                  WHERE u.role='user' ORDER BY u.username")->fetchAll() as $u) {
            $uid = (int)$u['id'];
            $doms = Database::query("SELECT domain, php_version FROM domains WHERE user_id=? ORDER BY domain", [$uid])->fetchAll();
            $c = fn(string $t) => (int)Database::query("SELECT COUNT(*) c FROM $t WHERE user_id=?", [$uid])->fetch()['c'];
            $out[] = [
                'username' => $u['username'], 'status' => $u['status'], 'package' => $u['package'],
                'disk_mb' => $u['disk_used_mb'] === null ? null : (int)$u['disk_used_mb'],
                'domains' => array_column($doms, 'domain'),
                'php' => array_values(array_unique(array_column($doms, 'php_version'))),
                'mailboxes' => $c('email_accounts'), 'dbs' => $c('mysql_dbs'), 'ftp' => $c('ftp_accounts'),
                'cron' => $c('cron_jobs'), 'daemons' => $c('app_daemons'),
            ];
        }
        return $out;
    }
    public static function export(string $u): array {
        $user = self::userRow($u);
        if (!$user) throw new DomainException("no movable account $u");
        $ids = [];
        $tables = [];
        foreach (self::MOVED as $t => $cfg) {
            $by = $cfg['by'];
            if ($by === 'id') {
                $rows = [$user];
            } elseif ($by === 'user_id') {
                $rows = Database::query("SELECT * FROM `$t` WHERE user_id=? ORDER BY 1", [(int)$user['id']])->fetchAll();
            } else {
                $parent = $ids[$cfg['remap'][$by]] ?? [];
                if (!$parent) { $tables[$t] = []; continue; }
                $in = implode(',', array_fill(0, count($parent), '?'));
                $rows = Database::query("SELECT * FROM `$t` WHERE `$by` IN ($in) ORDER BY 1", array_values($parent))->fetchAll();
            }
            $tables[$t] = $rows;
            if (empty($cfg['noid'])) $ids[$t] = array_map('intval', array_column($rows, 'id'));
        }
        $pkg = $user['package_id'] ? Database::query("SELECT * FROM packages WHERE id=?", [(int)$user['package_id']])->fetch() : null;
        return ['v' => 1, 'panel' => trim((string)@file_get_contents(dirname(__DIR__, 2).'/VERSION')),
                'user' => $u, 'exported_at' => gmdate('c'), 'package' => $pkg ?: null, 'tables' => $tables];
    }

    // ------------------------------------------------------------- target
    private static function columns(string $t): array {
        static $c = [];
        return $c[$t] ??= array_column(Database::query("SHOW COLUMNS FROM `$t`")->fetchAll(), 'Field');
    }
    private static function check(array $doc): void {
        if (($doc['v'] ?? null) !== 1 || !is_array($doc['tables'] ?? null)) throw new DomainException('not a transfer document (v1)');
        foreach ($doc['tables'] as $t => $rows) {
            if (!isset(self::MOVED[$t])) throw new DomainException("unknown table in transfer: $t");
            if (!is_array($rows)) throw new DomainException("bad rows for $t");
            $cols = self::columns($t);
            foreach ($rows as $r) {
                if (!is_array($r)) throw new DomainException("bad row in $t");
                foreach ($r as $k => $v) {
                    if (!in_array($k, $cols, true)) throw new DomainException("$t.$k doesn't exist on this server: update this panel to the source's version");
                    if (!is_null($v) && !is_scalar($v)) throw new DomainException("bad value $t.$k");
                }
            }
        }
        $u = $doc['tables']['users'][0] ?? null;
        if (!$u || count($doc['tables']['users']) !== 1 || ($u['role'] ?? '') !== 'user' || ($u['username'] ?? '') !== ($doc['user'] ?? '')
            || !preg_match('/^[a-z][a-z0-9_]{2,31}$/', (string)$u['username'])) throw new DomainException('the document must hold exactly one hosting account');
        $home = '/home/'.$u['username'].'/';
        foreach ($doc['tables']['domains'] ?? [] as $d) {
            if (!preg_match('/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$/', (string)$d['domain']) || !str_starts_with((string)$d['docroot'], $home) || str_contains((string)$d['docroot'], '..'))
                throw new DomainException('odd domain row: '.substr((string)$d['domain'], 0, 80));
        }
        foreach ($doc['tables']['ftp_accounts'] ?? [] as $f) {
            if (!str_starts_with((string)$f['homedir'].'/', $home) || str_contains((string)$f['homedir'], '..')) throw new DomainException('FTP home outside the account');
        }
        // DNS rows are rendered into zone files by root scripts: re-run the
        // same validator the UI/Hestia importer use (newlines/injections out).
        $dc = new DnsController();
        foreach ($doc['tables']['dns_records'] ?? [] as $r) {
            $err = $dc->validateRecord((string)($r['type'] ?? ''), (string)($r['host'] ?? ''),
                (string)($r['value'] ?? ''), (int)($r['priority'] ?? 0));
            if ($err !== null) throw new DomainException('bad DNS record: '.$err);
        }
    }
    // everything that would collide here. Empty = safe to import.
    public static function clashes(array $doc): array {
        self::check($doc);
        $t = $doc['tables'];
        $out = [];
        $u = (string)$t['users'][0]['username'];
        if (Database::query("SELECT 1 FROM users WHERE username=?", [$u])->fetch()) $out[] = "panel user $u already exists here";
        if (function_exists('posix_getpwnam') && posix_getpwnam($u)) $out[] = "system user $u already exists here";
        foreach ($t['domains'] ?? [] as $d) {
            if (Database::query("SELECT 1 FROM domains WHERE domain=?", [$d['domain']])->fetch()
                || Database::query("SELECT 1 FROM domain_aliases WHERE alias=?", [$d['domain']])->fetch()) $out[] = "domain {$d['domain']} is already on this server";
        }
        foreach ($t['domain_aliases'] ?? [] as $a) {
            if (Database::query("SELECT 1 FROM domains WHERE domain=?", [$a['alias']])->fetch()
                || Database::query("SELECT 1 FROM domain_aliases WHERE alias=?", [$a['alias']])->fetch()) $out[] = "alias {$a['alias']} is already on this server";
        }
        foreach ($t['mysql_dbs'] ?? [] as $m) {
            // system schemas/logins: the copy runs CREATE/GRANT/ALTER USER as
            // root with the peer's hash (transfer-pull.sh db_login_ok re-checks)
            if (preg_match('/^(mysql|information_schema|performance_schema|sys|skypanel|roundcube.*|phpmyadmin)$/', (string)$m['db_name'])
                || preg_match('/^(root|mysql|mariadb\.sys|skypanel|debian-sys-maint|roundcube.*|phpmyadmin|pma)$/', (string)$m['db_user'])) { $out[] = "database {$m['db_name']} / login {$m['db_user']} is a system name"; continue; }
            if (Database::query("SELECT 1 FROM mysql_dbs WHERE db_name=? OR db_user=?", [$m['db_name'], $m['db_user']])->fetch()
                || Database::query("SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?", [$m['db_name']])->fetch()) $out[] = "database {$m['db_name']} / login {$m['db_user']} is already on this server";
        }
        foreach ($t['ftp_accounts'] ?? [] as $f) {
            if (Database::query("SELECT 1 FROM ftp_accounts WHERE ftp_user=?", [$f['ftp_user']])->fetch()
                || (function_exists('posix_getpwnam') && posix_getpwnam((string)$f['ftp_user']))) $out[] = "FTP login {$f['ftp_user']} is already on this server";
        }
        return array_values(array_unique($out));
    }
    // insert every row with new ids; returns [table => [old id => new id]].
    // $packageId/$ownerId: chosen on the target (null = none / admin-owned).
    public static function import(array $doc, ?int $packageId, ?int $ownerId): array {
        if ($c = self::clashes($doc)) throw new DomainException(implode('; ', $c));
        $db = Database::conn();
        $map = [];
        $db->beginTransaction();
        try {
            foreach (self::MOVED as $t => $cfg) {
                foreach ($doc['tables'][$t] ?? [] as $r) {
                    $old = isset($r['id']) ? (int)$r['id'] : null;
                    if (empty($cfg['noid'])) unset($r['id']);
                    foreach ($cfg['remap'] as $col => $ref) {
                        if (!array_key_exists($col, $r) || $r[$col] === null) continue;
                        $new = $map[$ref][(int)$r[$col]] ?? null;
                        if ($new === null) throw new DomainException("$t.$col points at a $ref row that wasn't moved");
                        $r[$col] = $new;
                    }
                    if ($t === 'users') { $r['package_id'] = $packageId; $r['owner_id'] = $ownerId; }
                    if ($t === 'builder_sites') { $r['lock_token'] = ''; $r['lock_at'] = null; $r['preview_token'] = ''; $r['preview_expires'] = null; }
                    $cols = array_keys($r);
                    $sql = "INSERT INTO `$t` (`".implode('`,`', $cols)."`) VALUES (".implode(',', array_fill(0, count($cols), '?')).")";
                    Database::query($sql, array_values($r));
                    if ($old !== null && empty($cfg['noid'])) $map[$t][$old] = (int)$db->lastInsertId();
                }
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return $map;
    }
    // import a document file (root 600, written by transfer-pull.sh). The
    // package is matched by name among the admin's own packages, else none.
    // Returns the new user id.
    public static function importFile(string $file): int {
        $doc = json_decode((string)file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
        $pkgId = null;
        if (is_array($doc['package'] ?? null) && isset($doc['package']['name'])) {
            $p = Database::query("SELECT id FROM packages WHERE name=? AND reseller_id IS NULL", [(string)$doc['package']['name']])->fetch();
            $pkgId = $p ? (int)$p['id'] : null;
        }
        $map = self::import($doc, $pkgId, null);
        return (int)$map['users'][(int)$doc['tables']['users'][0]['id']];
    }
    // jobs must not run on both servers at once: the moved cron jobs are
    // paused here (the UI shows them paused) and their ids kept; cutover
    // turns exactly those back on. App daemons have no unit until cutover
    // creates one, so they can't start before then.
    public static function holdJobs(int $uid): string {
        $on = array_map('intval', array_column(Database::query("SELECT id FROM cron_jobs WHERE user_id=? AND active=1", [$uid])->fetchAll(), 'id'));
        Database::query("UPDATE cron_jobs SET active=0 WHERE user_id=?", [$uid]);
        return json_encode(['cron' => $on]);
    }
    // undo a half-made import's ROWS (system side is torn down by the
    // normal delete-account path). Reverse dependency order.
    public static function purgeRows(int $uid): void {
        $u = Database::query("SELECT id FROM users WHERE id=? AND role='user'", [$uid])->fetch();
        if (!$u) return;
        $doms = array_map('intval', array_column(Database::query("SELECT id FROM domains WHERE user_id=?", [$uid])->fetchAll(), 'id'));
        $inst = array_map('intval', array_column(Database::query("SELECT id FROM site_installs WHERE user_id=?", [$uid])->fetchAll(), 'id'));
        $clones = array_map('intval', array_column(Database::query("SELECT id FROM staging_clones WHERE user_id=?", [$uid])->fetchAll(), 'id'));
        $lists = array_map('intval', array_column(Database::query("SELECT id FROM email_lists WHERE user_id=?", [$uid])->fetchAll(), 'id'));
        $in = fn(array $ids) => $ids ? implode(',', $ids) : '0';   // ints only
        Database::query("DELETE FROM fleet_snapshots WHERE install_id IN (".$in($inst).")");
        Database::query("DELETE FROM app_versions WHERE install_id IN (".$in($inst).")");
        Database::query("DELETE FROM staging_snapshots WHERE clone_id IN (".$in($clones).")");
        Database::query("DELETE FROM email_list_members WHERE list_id IN (".$in($lists).")");
        Database::query("DELETE FROM dns_records WHERE domain_id IN (".$in($doms).")");
        foreach (array_reverse(array_keys(self::MOVED)) as $t) {
            if (in_array($t, ['users', 'dns_records', 'email_list_members', 'staging_snapshots', 'app_versions', 'fleet_snapshots'], true)) continue;
            Database::query("DELETE FROM `$t` WHERE user_id=?", [$uid]);
        }
        Database::query("DELETE FROM users WHERE id=?", [$uid]);
    }
    // sites + zones for every moved domain, BEFORE the files arrive (these
    // only mkdir the docroots, never write into them). One line per domain.
    public static function rebuildSites(int $uid): array {
        $owner = Database::query("SELECT * FROM users WHERE id=? AND role='user'", [$uid])->fetch();
        if (!$owner) throw new DomainException('no such account');
        $out = [];
        $line = fn(string $d, array $r) => "$d: ".(($r['code'] ?? 1) === 0 ? 'ok' : 'FAILED '.substr(trim((string)($r['out'] ?? '')), -160));
        foreach (Database::query("SELECT * FROM domains WHERE user_id=? ORDER BY LENGTH(domain), domain", [$uid])->fetchAll() as $d) {
            $php = DomainProvisioner::effectivePhp($uid, (string)$d['php_version']);
            if (DomainModel::parentOf((string)$d['domain'], $uid)) {
                $out[] = $line($d['domain'].' (subdomain)', ServiceManager::run('subdomain-add.sh', [$d['domain'], $owner['username'], $php], 120));
                continue;
            }
            [$ns1, $ns2] = DnsController::effectiveNs($uid, (string)$d['domain'], (int)$d['id']);
            ServiceManager::run('dns-add-zone.sh', [$d['domain'], $ns1, $ns2], 60);
            $z = (new DnsController())->apply((int)$d['id'], (string)$d['domain']);
            $v = ServiceManager::run('vhost-add.sh', [$d['domain'], $owner['username'], $php], 120);
            $out[] = $line($d['domain'], $v).(($z['code'] ?? 1) === 0 ? ', zone ok' : ', zone FAILED');
        }
        foreach (Database::query("SELECT a.alias, d.domain FROM domain_aliases a JOIN domains d ON d.id=a.domain_id WHERE a.user_id=?", [$uid])->fetchAll() as $a) {
            $out[] = $line("alias {$a['alias']}", ServiceManager::run('vhost-alias.sh', [$a['domain'], $a['alias']], 60));
        }
        return $out;
    }
    // per-domain switches, AFTER the files are in. Returns report lines
    // (things the admin must redo are prefixed TODO).
    public static function applySettings(int $uid): array {
        $out = [];
        foreach (Database::query("SELECT * FROM domains WHERE user_id=? ORDER BY domain", [$uid])->fetchAll() as $d) {
            $dom = (string)$d['domain'];
            $h = (int)$d['sec_headers'] ? [$dom, 'on'] : [$dom, 'off'];
            if ((int)$d['sec_headers'] && (int)$d['csp']) $h[] = '--csp';
            ServiceManager::run('vhost-headers.sh', $h, 60);
            if ((int)$d['hsts']) $out[] = "TODO $dom: HSTS was on: switch it on again once SSL is issued here";
            $n = (int)$d['noexec'] ? [$dom, 'on'] : [$dom, 'off'];
            if ((int)$d['noexec']) foreach (array_filter(explode(',', (string)$d['noexec_paths'])) as $p) {
                if (preg_match('#^[A-Za-z0-9_-][A-Za-z0-9._/-]{0,119}$#', $p) && !preg_match('#(^|/)\.\.?(/|$)#', $p)) { $n[] = '--path'; $n[] = $p; }
            }
            ServiceManager::run('vhost-noexec.sh', $n, 60);
            if (!(int)$d['waf']) ServiceManager::run('waf-shield.sh', [$dom, 'off'], 60);
            if (trim((string)$d['rewrite_rules']) !== '') ServiceManager::run('rewrite-sync.sh', [$dom], 60);
            if ((string)$d['mail_handling'] === 'remote') ServiceManager::run('mail-routing.sh', [$dom, 'remote'], 60);
            if ((int)$d['maintenance']) ServiceManager::run('maintenance.sh', [$dom, 'on'], 60);
            if ((int)$d['dnssec']) $out[] = "TODO $dom: DNSSEC was on: enable it here after cutover and update the DS record at the registrar";
            if ((int)$d['ssl_enabled']) $out[] = "TODO $dom: SSL: issue a certificate here once DNS points at this server";
            // the panel must show what THIS server has: no cert, no DNSSEC keys yet
            Database::query("UPDATE domains SET ssl_enabled=0, dnssec=0 WHERE id=?", [(int)$d['id']]);
        }
        return $out;
    }

    // ------------------------------------------------------------- cutover
    // OLD server: the account's sites (with their maintenance flag) and every
    // name its mail arrives for (sites + aliases)
    public static function siteFlags(string $u): array {
        $user = self::userRow($u);
        if (!$user) return [];
        return Database::query("SELECT domain, maintenance FROM domains WHERE user_id=? ORDER BY domain", [(int)$user['id']])->fetchAll();
    }
    public static function mailDomains(string $u): array {
        $user = self::userRow($u);
        if (!$user) return [];
        $d = array_column(Database::query("SELECT domain FROM domains WHERE user_id=?", [(int)$user['id']])->fetchAll(), 'domain');
        $a = array_column(Database::query("SELECT alias FROM domain_aliases WHERE user_id=?", [(int)$user['id']])->fetchAll(), 'alias');
        $all = array_values(array_unique(array_merge($d, $a)));
        sort($all);
        return $all;
    }
    public static function setMaintenance(string $u, string $d, bool $on): void {
        Database::query("UPDATE domains d JOIN users u ON u.id=d.user_id SET d.maintenance=? WHERE u.username=? AND u.role='user' AND d.domain=?", [$on ? 1 : 0, $u, $d]);
    }
    public static function userId(string $u): int {
        return (int)(self::userRow($u)['id'] ?? 0);
    }
    // BOTH servers: the account's zones answer with the new server's address.
    // A records equal to $old and ip4:$old inside SPF are rewritten; zones are
    // re-rendered from the rows. Other addresses (mail elsewhere, CDNs) stay.
    public static function repoint(int $uid, string $old, string $new): array {
        foreach ([$old, $new] as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) throw new DomainException('bad address');
        }
        if ($old === $new) return ["DNS: already $new"];
        $out = [];
        $spf = '/(?<![0-9A-Za-z])ip4:'.preg_quote($old, '/').'(?![0-9.\/])/';
        foreach (Database::query("SELECT id, domain FROM domains WHERE user_id=? ORDER BY LENGTH(domain), domain", [$uid])->fetchAll() as $d) {
            $id = (int)$d['id'];
            $a = Database::query("UPDATE dns_records SET value=? WHERE domain_id=? AND type='A' AND value=?", [$new, $id, $old])->rowCount();
            $t = 0;
            foreach (Database::query("SELECT id, value FROM dns_records WHERE domain_id=? AND type='TXT' AND value LIKE ?", [$id, '%ip4:'.$old.'%'])->fetchAll() as $r) {
                $v = preg_replace($spf, 'ip4:'.$new, (string)$r['value']);
                if ($v !== null && $v !== $r['value']) { Database::query("UPDATE dns_records SET value=? WHERE id=?", [$v, (int)$r['id']]); $t++; }
            }
            $zone = '/etc/bind/zones/db.'.preg_replace('/[^a-z0-9.-]/', '', (string)$d['domain']);
            if (!is_file($zone)) continue;   // subdomain: its records live in the parent's zone
            $z = (new DnsController())->apply($id, (string)$d['domain']);
            $out[] = "DNS {$d['domain']}: $a address record(s)".($t ? " + $t SPF" : '')." now $new"
                   .((($z['code'] ?? 1) === 0) ? '' : ' (zone reload FAILED: check the DNS page)');
        }
        return $out;
    }
    // TARGET, before the last copy: what the OLD account gained since the
    // first copy. The top-up carries files, databases and mail, not panel
    // rows, so anything listed here means "copy it again" instead.
    public static function drift(array $doc, int $uid): array {
        $t = $doc['tables'] ?? [];
        $here = fn(string $sql) => array_column(Database::query($sql, [$uid])->fetchAll(), 'k');
        $out = [];
        foreach ([
            'site'      => [array_column($t['domains'] ?? [], 'domain'),       "SELECT domain k FROM domains WHERE user_id=?"],
            'alias'     => [array_column($t['domain_aliases'] ?? [], 'alias'), "SELECT alias k FROM domain_aliases WHERE user_id=?"],
            'database'  => [array_column($t['mysql_dbs'] ?? [], 'db_name'),    "SELECT db_name k FROM mysql_dbs WHERE user_id=?"],
            // the login too: the top-up sets the PEER's hash on it as root
            'database login' => [array_map(fn($m) => $m['db_name'].' / '.$m['db_user'], $t['mysql_dbs'] ?? []), "SELECT CONCAT(db_name,' / ',db_user) k FROM mysql_dbs WHERE user_id=?"],
            'FTP login' => [array_column($t['ftp_accounts'] ?? [], 'ftp_user'), "SELECT ftp_user k FROM ftp_accounts WHERE user_id=?"],
            'app daemon'=> [array_column($t['app_daemons'] ?? [], 'name'),     "SELECT name k FROM app_daemons WHERE user_id=?"],
        ] as $what => [$there, $sql]) {
            foreach (array_diff($there, $here($sql)) as $n) $out[] = "new $what $n";
        }
        $byId = array_column($t['domains'] ?? [], 'domain', 'id');
        $boxes = array_map(fn($r) => $r['localpart'].'@'.($byId[$r['domain_id']] ?? '?'), $t['email_accounts'] ?? []);
        foreach (array_diff($boxes, $here("SELECT CONCAT(e.localpart,'@',d.domain) k FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE e.user_id=?")) as $n) $out[] = "new mailbox $n";
        foreach (['email_forwards' => 'forwarders', 'email_lists' => 'mailing lists', 'cron_jobs' => 'cron jobs', 'dns_records' => 'DNS records'] as $tb => $what) {
            $n = (int)Database::query("SELECT COUNT(*) c FROM `$tb` WHERE ".($tb === 'dns_records' ? "domain_id IN (SELECT id FROM domains WHERE user_id=?)" : "user_id=?"), [$uid])->fetch()['c'];
            if (count($t[$tb] ?? []) > $n) $out[] = "more $what there (".count($t[$tb])." vs $n here)";
        }
        return $out;
    }
    // TARGET, at cutover: exactly the cron jobs paused at copy time come back
    public static function releaseHeld(int $item): int {
        $it = Database::query("SELECT held, new_user_id FROM transfer_items WHERE id=?", [$item])->fetch();
        if (!$it || !$it['new_user_id']) return 0;
        $ids = array_values(array_filter(array_map('intval', (array)(json_decode((string)$it['held'], true)['cron'] ?? []))));
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            Database::query("UPDATE cron_jobs SET active=1 WHERE user_id=? AND id IN ($in)", array_merge([(int)$it['new_user_id']], $ids));
        }
        Database::query("UPDATE transfer_items SET held=NULL WHERE id=?", [$item]);
        return count($ids);
    }
    // TARGET, at cutover: the moved app daemons get their units (they had none)
    public static function startDaemons(int $uid): array {
        $u = Database::query("SELECT username FROM users WHERE id=? AND role='user'", [$uid])->fetch();
        if (!$u) return [];
        $out = [];
        foreach (Database::query("SELECT * FROM app_daemons WHERE user_id=? ORDER BY name", [$uid])->fetchAll() as $d) {
            $r = ServiceManager::run('app-daemon.sh', ['create', $u['username'], (string)$d['name'], (string)$d['command'], (string)$d['workdir'], (string)(int)$d['max_hours']], 120);
            $ok = ($r['code'] ?? 1) === 0;
            if ($ok && !(int)$d['autostart']) {   // as it was: not started on boot, start it by hand
                ServiceManager::run('app-daemon.sh', ['off', $u['username'], (string)$d['name']], 60);
                ServiceManager::run('app-daemon.sh', ['stop', $u['username'], (string)$d['name']], 60);
            }
            $out[] = "app daemon {$d['name']}: ".($ok ? ((int)$d['autostart'] ? 'started' : 'set up, not started (autostart was off)') : 'FAILED: '.substr(trim((string)($r['out'] ?? '')), -160));
        }
        return $out;
    }

    // ---------------------------------------------------- job/item rows (UI)
    /** a transfer still running over this pairing (revoke guard) */
    public static function busyForPairing(string $pairing): int {
        return (int)Database::query(
            "SELECT COUNT(*) c FROM transfer_jobs WHERE pairing=? AND state IN ('running','waiting') AND updated_at > NOW() - INTERVAL 15 MINUTE",
            [$pairing]
        )->fetch()['c'];
    }
    /** a cutover still running anywhere (revoke guard) */
    public static function recentCuts(): int {
        return (int)Database::query(
            "SELECT COUNT(*) c FROM transfer_items WHERE stage LIKE 'cut-%' AND updated_at > NOW() - INTERVAL 15 MINUTE"
        )->fetch()['c'];
    }
    public static function allUsernames(): array {
        return array_column(Database::query("SELECT username FROM users")->fetchAll(), 'username');
    }
    public static function allDomains(): array {
        return array_column(Database::query("SELECT domain FROM domains")->fetchAll(), 'domain');
    }
    /** caller owns the transaction (job + items are created together) */
    public static function createJob(string $pairing, string $sourceIp, ?int $createdBy): int {
        Database::query(
            "INSERT INTO transfer_jobs (pairing, source_ip, state, created_by, updated_at) VALUES (?,?, 'queued', ?, NOW())",
            [$pairing, $sourceIp, $createdBy]
        );
        return (int)Database::conn()->lastInsertId();
    }
    public static function addItem(int $job, string $username): void {
        Database::query("INSERT INTO transfer_items (job_id, username, updated_at) VALUES (?,?,NOW())", [$job, $username]);
    }
    public static function failJob(int $job): void {
        Database::query("UPDATE transfer_jobs SET state='failed' WHERE id=?", [$job]);
        Database::query("UPDATE transfer_items SET stage='failed', detail='could not start' WHERE job_id=?", [$job]);
    }
    public static function finishedJob(int $job): ?array {
        return Database::query(
            "SELECT * FROM transfer_jobs WHERE id=? AND state IN ('done','failed')", [$job]
        )->fetch() ?: null;
    }
    /** failed accounts of a run that are not already here (retry candidates) */
    public static function retryableUsernames(int $job): array {
        return array_column(Database::query(
            "SELECT t.username FROM transfer_items t LEFT JOIN users u ON u.username=t.username
             WHERE t.job_id=? AND t.stage='failed' AND u.id IS NULL", [$job]
        )->fetchAll(), 'username');
    }
    public static function cutItem(int $itemId): ?array {
        return Database::query(
            "SELECT i.username, i.stage FROM transfer_items i JOIN users u ON u.id=i.new_user_id AND u.username=i.username WHERE i.id=?",
            [$itemId]
        )->fetch() ?: null;
    }
    /** the last 14 days of runs, newest first (live bars) */
    public static function recentJobs(): array {
        return Database::query(
            "SELECT id, pairing, source_ip, state, created_at, updated_at, TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS idle
             FROM transfer_jobs j WHERE created_at > NOW() - INTERVAL 14 DAY
                OR EXISTS (SELECT 1 FROM transfer_items i WHERE i.job_id=j.id AND (i.stage='ready' OR i.updated_at > NOW() - INTERVAL 14 DAY))
             ORDER BY id DESC LIMIT 20"
        )->fetchAll();
    }
    public static function itemsFor(int $jobId): array {
        return Database::query(
            "SELECT t.id, t.username, t.stage, t.pct, t.bytes_done, t.bytes_total, t.rate_bps, t.detail, t.checks, t.report, t.started_at, t.updated_at,
                    TIMESTAMPDIFF(SECOND, t.updated_at, NOW()) AS idle, (u.id IS NOT NULL) AS here
             FROM transfer_items t LEFT JOIN users u ON u.id=t.new_user_id AND u.username=t.username
             WHERE t.job_id=? ORDER BY t.id", [$jobId]
        )->fetchAll();
    }
}
