<?php
declare(strict_types=1);
// App catalog: each entry maps to scripts/<script>.sh. Adding an app =
// new catalog row + new script following the wp-install.sh contract
// (args validated, refuses to clobber, prints URL on success).
class SoftwareController extends Controller {
    public const APPS = [
        'wordpress' => ['name' => 'WordPress', 'icon' => '📝', 'kind' => 'Blog & website', 'desc' => 'The most popular site builder: blogs, business sites, shops with plugins.', 'setup' => 'Create your admin account on first visit to /wp-admin', 'script' => 'wp-install.sh', 'db_suffix' => '_wp'],
        'phpbb' => ['name' => 'phpBB', 'icon' => '💬', 'kind' => 'Forum', 'desc' => 'Classic discussion board with categories, private messages and moderation.', 'setup' => 'Finish the setup at /install (database details are filled in for you)', 'script' => 'phpbb-install.sh'],
        'vvveb' => ['name' => 'Vvveb', 'icon' => '🧱', 'kind' => 'Website builder', 'desc' => 'Drag-and-drop page builder CMS with blog and shop pages.', 'setup' => 'Ready to use: the admin account is created here', 'script' => 'vvveb-install.sh', 'needs_admin' => true, 'root_only' => true],
        'flarum' => ['name' => 'Flarum', 'icon' => '💭', 'kind' => 'Forum', 'desc' => 'Modern, fast forum (2.x release candidate) with tags and mentions.', 'setup' => 'Ready to use: the admin account is created here', 'script' => 'flarum-install.sh', 'needs_admin' => true, 'root_only' => true, 'php_min' => '8.3', 'db_suffix' => '_forum'],  // php-ver-ok: Flarum 2.x floor
        'joomla' => ['name' => 'Joomla', 'icon' => '🌐', 'kind' => 'Website & CMS', 'desc' => 'Popular CMS for business sites, portals and multilingual sites, with thousands of extensions.', 'setup' => 'Ready to use: the admin account is created here', 'script' => 'joomla-install.sh', 'needs_admin' => true, 'pass_min' => 12, 'php_min' => '8.3', 'db_suffix' => '_joomla'],  // php-ver-ok: Joomla 6 floor
        'drupal' => ['name' => 'Drupal', 'icon' => '💧', 'kind' => 'Website & CMS', 'desc' => 'Drupal 11: structured content, user roles and multilingual sites for larger organisations.', 'setup' => 'Ready to use: the admin account is created here', 'script' => 'drupal-install.sh', 'needs_admin' => true, 'pass_min' => 12, 'root_only' => true, 'php_min' => '8.3', 'db_suffix' => '_drupal'],  // php-ver-ok: Drupal 11 floor
        'prestashop' => ['name' => 'PrestaShop', 'icon' => '🛒', 'kind' => 'Online shop', 'desc' => 'Full e-commerce shop (8.2): catalogue, cart, payments, orders.', 'setup' => 'Ready to use: the admin account is created here', 'script' => 'prestashop-install.sh', 'needs_admin' => true, 'root_only' => true, 'needs_php' => '8.2', 'db_suffix' => '_shop'], // php-ver-ok: PS 8.2 tops out at PHP 8.1/8.2 (PS 9 move = separate ticket)
    ];
    public function list(): void {
        Auth::requireLogin();
        $u = Auth::user();
        $domains = DomainModel::forUser((int)$u['id']);
        $installs = [];
        $pg = 1; $pages = 1; $total = 0;
        try {
            $total = (int)Database::query("SELECT COUNT(*) c FROM site_installs WHERE user_id=?", [$u['id']])->fetch()['c'];
            $p = Helpers::paginate($total, max(1, (int)($_GET['page'] ?? 1)));
            $pg = $p['pg']; $pages = $p['pages'];
            $installs = Database::query("SELECT s.*, d.domain FROM site_installs s JOIN domains d ON d.id=s.domain_id WHERE s.user_id=? ORDER BY s.id DESC LIMIT ? OFFSET ?", [$u['id'], (int)$p['limit'], (int)$p['offset']])->fetchAll();
            // live auto-update state per WordPress install (script reads the
            // site itself, so hand edits in wp-admin show truthfully)
            $auto = [];
            foreach ($installs as $s) {
                if (($s['app'] ?? '') !== 'wordpress') continue;
                $doc = "/home/{$u['username']}/{$s['domain']}/public_html".(($s['path'] ?? '') !== '' ? '/'.ltrim($s['path'], '/') : '');
                $r = ServiceManager::run('wp-autoupdate.sh', ['status', $doc]);
                $auto[$s['id']] = (trim($r['out'] ?? '') === 'on') ? 'on' : 'off';
            }
        } catch (Throwable $e) {}
        // an app pinned to a legacy PHP (PrestaShop 8.2) can only go on a
        // domain already on it — new sites can't pick that version any more
        $apps = self::APPS;
        foreach ($apps as $k => $a) {
            $need = $a['needs_php'] ?? null;
            if ($need === null || PhpVersions::isSelectable($need)) continue;
            $onIt = array_filter($domains, fn($d) => (string)($d['php_version'] ?? '') === $need);
            if (!$onIt) {
                $apps[$k]['unavailable'] = true;
                $apps[$k]['why'] = "Needs PHP $need, which is no longer offered for new sites (end of life ".(PhpVersions::eol($need) ?? 'soon').') — only possible on a domain already on it';
            }
        }
        // admin link per install: never guessed — phpBB is still mid-setup
        // while install/ exists, PrestaShop's back office folder is renamed
        $links = [];
        foreach ($installs as $s) {
            $doc = "/home/{$u['username']}/{$s['domain']}/public_html".(($s['path'] ?? '') !== '' ? '/'.ltrim($s['path'], '/') : '');
            $links[$s['id']] = self::adminLink((string)$s['app'], $doc);
        }
        $dbErr = Helpers::limitError($u, 'dbs');
        $bg = self::bgStates(array_map(fn($d) => (string)$d['domain'], $domains));
        $isAdmin = Auth::role() === 'admin';
        $this->view('software', ['apps' => $apps, 'domains' => $domains, 'installs' => $installs, 'pg' => $pg, 'pages' => $pages, 'total' => $total, 'auto' => $auto,
            'links' => $links, 'dbErr' => $dbErr, 'bg' => $bg, 'isAdmin' => $isAdmin]);
    }
    // [label, path relative to the install URL] or null when there is none to offer
    private static function adminLink(string $app, string $doc): ?array {
        switch ($app) {
            case 'wordpress': return ['WP admin', '/wp-admin/'];
            // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- docroot from the user's own install row
            case 'phpbb': return is_dir("$doc/install") ? ['Finish setup', '/install/'] : ['Admin', '/adm/'];
            case 'vvveb': return ['Admin', '/admin/'];
            case 'flarum': return ['Admin', '/admin'];
            case 'joomla': return ['Admin', '/administrator/'];
            case 'drupal': return ['Admin', '/user/login'];
            case 'prestashop':
                // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- docroot from the user's own install row
                foreach (glob("$doc/admin*", GLOB_ONLYDIR) ?: [] as $dir) {
                    $b = basename($dir);
                    if (preg_match('/^admin[a-z0-9_-]{0,40}$/i', $b)) return ['Back office', "/$b/"];
                }
                return null;
        }
        return null;
    }
    // background installs (scripts/sw-state.sh): "domain/sub" => [state, app, started, message],
    // only for the caller's own domains — the listing covers every account
    public static function bgStates(array $own): array {
        $out = [];
        if (!$own) return $out;
        $own = array_flip($own);
        try { $raw = (string)(ServiceManager::run('sw-state.sh', ['pending'])['out'] ?? ''); } catch (Throwable $e) { return $out; }
        foreach (preg_split('/\R/', $raw) as $line) {
            $f = explode("\t", $line, 6);
            if (count($f) < 5 || !isset($own[$f[0]]) || !isset(self::APPS[$f[2]]) || !in_array($f[3], ['running', 'ok', 'fail'], true)) continue;
            $out[$f[0].($f[1] !== '' ? '/'.$f[1] : '')] = [$f[3], $f[2], (int)$f[4], $f[5] ?? ''];
        }
        uasort($out, fn($a, $b) => $b[2] <=> $a[2]);
        return $out;
    }
    // GET /software/pending — the page polls this while an install runs
    public function pending(): void {
        Auth::requireLogin();
        $u = Auth::user();
        $run = 0;
        foreach (self::bgStates(array_map(fn($d) => (string)$d['domain'], DomainModel::forUser((int)$u['id']))) as [$st]) if ($st === 'running') $run++;
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode(['running' => $run]);
        exit;
    }
    public function install(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/software'); }
        $u = Auth::user();
        $app = (string)($_POST['app'] ?? '');
        if (!isset(self::APPS[$app])) { Helpers::flash('err','Unknown app'); Helpers::redirect('/software'); }
        $domainId = (int)($_POST['domain_id'] ?? 0);
        $sub = strtolower(trim($_POST['subdir'] ?? ''));
        $title = trim((string)($_POST['title'] ?? '')); if ($title === '') $title = self::APPS[$app]['name'];
        if ($sub !== '' && !preg_match('/^[a-z0-9_-]{1,64}$/', $sub)) { Helpers::flash('err','Bad folder'); Helpers::redirect('/software'); }
        if ($sub !== '' && !empty(self::APPS[$app]['root_only'])) { Helpers::flash('err', self::APPS[$app]['name'].' installs at domain root only — leave folder empty'); Helpers::redirect('/software'); }
        $d = Database::query("SELECT * FROM domains WHERE id=? AND user_id=?", [$domainId, $u['id']])->fetch();
        if (!$d) { Helpers::flash('err','Domain not found'); Helpers::redirect('/software'); }
        // apps with runtime floors (Flarum 2.x needs PHP 8.3+) or an exact
        // version: refuse early with guidance instead of a broken install
        $need = self::APPS[$app]['needs_php'] ?? null; $min = self::APPS[$app]['php_min'] ?? null;
        $have = (string)($d['php_version'] ?? '');
        if ($need !== null && $have !== $need && !PhpVersions::isSelectable($need)) {
            Helpers::flash('err', self::APPS[$app]['name']." needs PHP $need, which is no longer offered for new sites — it can only be installed on a domain already on PHP $need"); Helpers::redirect('/software');
        }
        if (($need !== null && $have !== $need) || ($min !== null && version_compare($have, $min, '<'))) {
            Helpers::flash('err', self::APPS[$app]['name'].' needs PHP '.($need ?? $min.'+').' — switch the domain first (PHP page), then install'); Helpers::redirect('/software');
        }
        if ($err = Helpers::limitError($u, 'dbs')) { Helpers::flash('err', $err.' (this app needs a database)'); Helpers::redirect('/software'); }
        if ($err = Helpers::diskBlocked($u)) { Helpers::flash('err', $err); Helpers::redirect('/software'); }
        // unique db name/user from the panel username (same scheme as the DB wizard)
        $db = null; $dbuser = null;
        $sfx = self::APPS[$app]['db_suffix'] ?? '_wp';
        for ($i = 0; $i < 20; $i++) {
            $try = strtolower($u['username'].($sub !== '' ? '_'.$sub : $sfx).($i > 0 ? $i : ''));
            $try = substr(preg_replace('/[^a-z0-9_]/', '', $try), 0, 60);
            if (strlen($try) < 3) continue;
            if (MysqlDb::nameFree($try, substr($try, 0, 32))) { $db = $try; $dbuser = substr($try, 0, 32); break; }
        }
        if (!$db) { Helpers::flash('err','Could not pick a free database name'); Helpers::redirect('/software'); }
        // synchronous pre-check: target must be empty (script re-checks defensively).
        // nobody lists via the home ACL — same as the file manager.
        $docroot = "/home/{$u['username']}/{$d['domain']}/public_html".($sub !== '' ? "/$sub" : '');
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- docroot from owned domain + validated sub
        if (is_dir($docroot)) {
            // panel scaffold doesn't count: placeholder index.html + mail
            // autoconfig (.well-known/, autodiscover/) ship with every domain
            $left = array_diff(scandir($docroot) ?: [], ['.','..','index.html','.well-known','autodiscover']);
            if (!empty($left)) { Helpers::flash('err','Folder is not empty — pick an empty folder'); Helpers::redirect('/software'); }
        }
        $dbpass = bin2hex(random_bytes(12));
        // apps that store their own URL (Flarum baseUrl, Vvveb links) must get
        // the scheme users actually visit, or APIs/assets break on mismatch
        $scheme = !empty($d['ssl_enabled']) ? 'https://' : 'http://';
        $url = $scheme.$d['domain'].($sub !== '' ? '/'.$sub : '');
        $args = [$d['domain'], $u['username'], $sub, $db, $dbuser, new Secret($dbpass), substr($title, 0, 80), (string)$u['id'], (string)$domainId, $url];
        if (!empty(self::APPS[$app]['needs_admin'])) {
            $am = trim((string)($_POST['admin_email'] ?? ''));
            $au = trim((string)($_POST['admin_user'] ?? ''));
            $ap = (string)($_POST['admin_pass'] ?? '');
            $pmin = (int)(self::APPS[$app]['pass_min'] ?? 10);
            if (!filter_var($am, FILTER_VALIDATE_EMAIL) || !preg_match('/^[a-zA-Z0-9_.-]{3,32}$/', $au) || strlen($ap) < $pmin || strlen($ap) > 128 || preg_match('/[\x00-\x1f\x7f]/', $ap) || ($app === 'joomla' && $ap !== trim($ap))) {
                Helpers::flash('err',"Admin email/user (3-32)/password ($pmin+) required"); Helpers::redirect('/software');
            }
            $args = array_merge($args, [$am, $au, new Secret($ap)]);
        }
        // DETACHED: download exceeds the sudo timeout — script backgrounds itself,
        // writes tracking rows on success, logs to Logs → software either way
        $r = ServiceManager::run(self::APPS[$app]['script'], $args);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Install refused: '.trim($r['out']));
        // the script's own "watch Logs → software" points at an admin-only page
        // (a log shared by every account), so only admins get that hint
        else Helpers::flash('ok', 'Installing '.self::APPS[$app]['name']." at $url (database $db). Usually 1–2 minutes: progress shows below and the page refreshes when it's done."
            .(Auth::role() === 'admin' ? ' Details: Logs → software installs.' : ''));
        Helpers::redirect('/software');
    }
    // WordPress auto-update toggle: minor core + plugins on, everything else
    // manual. Runs against the live files (never the DB), so it also repairs
    // hand-removed mu-plugins. Backups stay the safety net — the view says so.
    public function autoupdate(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/software'); }
        $u = Auth::user();
        $id = (int)($_POST['id'] ?? 0);
        $s = Database::query("SELECT s.*, d.domain FROM site_installs s JOIN domains d ON d.id=s.domain_id WHERE s.id=? AND s.user_id=? AND s.app='wordpress'", [$id, $u['id']])->fetch();
        if (!$s) { Helpers::flash('err','WordPress install not found'); Helpers::redirect('/software'); }
        $on = (isset($_POST['on']) && $_POST['on'] === '1');
        $doc = "/home/{$u['username']}/{$s['domain']}/public_html".(($s['path'] ?? '') !== '' ? '/'.ltrim($s['path'], '/') : '');
        $r = ServiceManager::run('wp-autoupdate.sh', [$on ? 'on' : 'off', $doc]);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Not applied: '.trim($r['out']));
        else { Helpers::log("wp-autoupdate ".($on ? 'on' : 'off')." for {$s['url']} by {$u['username']}"); Helpers::flash('ok', trim($r['out'])); }
        Helpers::redirect('/software');
    }
    // Uninstall: the script deletes the files (keeping builder sites and other
    // apps on a root install) and reports the DB name from the app's config;
    // the DB is dropped only when it is a mysql_dbs row of this same user.
    public function uninstall(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/software'); }
        $u = Auth::user();
        $id = (int)($_POST['id'] ?? 0);
        $s = Database::query("SELECT s.*, d.domain FROM site_installs s JOIN domains d ON d.id=s.domain_id WHERE s.id=? AND s.user_id=?", [$id, $u['id']])->fetch();
        if (!$s) { Helpers::flash('err','App not found'); Helpers::redirect('/software'); }
        $name = self::APPS[$s['app']]['name'] ?? $s['app'];
        $r = ServiceManager::run('app-uninstall.sh', [(string)$s['id']], 300);
        if (($r['code'] ?? 1) !== 0) { Helpers::flash('err', "$name was not removed: ".trim((string)$r['out'])); Helpers::redirect('/software'); }
        Database::query("DELETE FROM site_installs WHERE id=? AND user_id=?", [$s['id'], $u['id']]);
        $dbMsg = '';
        if (($_POST['drop_db'] ?? '') === '1') {
            $db = preg_match('/^DB=([a-z][a-z0-9_]{2,63})$/m', (string)$r['out'], $m) ? $m[1] : '';
            $row = $db !== '' ? Database::query("SELECT * FROM mysql_dbs WHERE db_name=? AND user_id=?", [$db, $u['id']])->fetch() : false;
            if (!$row) $dbMsg = ' Its database could not be identified, so none was deleted: check Databases.';
            else {
                try { MysqlDb::drop($row); $dbMsg = " Database $db deleted."; }
                catch (Throwable $e) { $dbMsg = " Database $db could not be deleted: remove it in Databases."; }
            }
        } else $dbMsg = ' Its database was kept (see Databases).';
        Helpers::log("app-uninstall {$s['app']} #{$s['id']} {$s['url']} by {$u['username']}".($dbMsg !== '' ? ' —'.$dbMsg : ''));
        Helpers::flash('ok', "$name removed from ".preg_replace('#^https?://#', '', (string)$s['url']).'.'.$dbMsg);
        Helpers::redirect('/software');
    }
}
