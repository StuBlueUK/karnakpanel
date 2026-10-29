<?php
declare(strict_types=1);
// Karnak Builder: site rows, the privileged bridge (scripts/builder.sh ->
// builder-user.sh as the account user), credit rule and the renderer that
// turns the editor's exported pages into static files.
//
// Trust model: everything in draft/project JSON came from the customer's
// browser. It may hold anything, so the renderer re-parses every page and
// keeps only an allow-listed tag/attribute set — published pages carry no
// script, no event handlers, no foreign frames. The panel origin only ever
// serves this content under a CSP sandbox (preview, assets).
class Builder {
    public const SUB_RE = '/^[a-z0-9_-]{1,64}$/';
    public const SLUG_RE = '/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/';
    public const ASSET_RE = '/^[a-z0-9][a-z0-9_-]{0,80}\.(webp|jpg|png|gif)$/';
    public const MAX_PAGES = 50;
    public const MAX_POSTS = 200;
    public const POSTS_PER_PAGE = 10;
    // post addresses that would land on generated blog paths
    public const POST_RESERVED = ['tag', 'page', 'feed'];
    // blog context for the block filler in walk() (set by render())
    private static array $blog = ['posts' => [], 'cur' => null, 'pager' => null];
    public const MAX_JSON = 8 * 1024 * 1024;
    public const MAX_PAGE_BYTES = 2 * 1024 * 1024;
    public const LOCK_IDLE = 600;
    public const EMBED_HOSTS = ['www.youtube-nocookie.com', 'player.vimeo.com', 'www.openstreetmap.org'];
    public const FONTS = [
        'Inter' => 'Inter, system-ui, sans-serif', 'Space Grotesk' => '"Space Grotesk", system-ui, sans-serif',
        'Playfair Display' => '"Playfair Display", Georgia, serif', 'Lato' => 'Lato, system-ui, sans-serif',
        'Archivo' => 'Archivo, system-ui, sans-serif', 'Oswald' => 'Oswald, "Arial Narrow", sans-serif',
        'Cormorant' => 'Cormorant, Georgia, serif', 'Karla' => 'Karla, system-ui, sans-serif',
        'Plus Jakarta Sans' => '"Plus Jakarta Sans", system-ui, sans-serif', 'DM Serif Display' => '"DM Serif Display", Georgia, serif',
        'DM Sans' => '"DM Sans", system-ui, sans-serif', 'Manrope' => 'Manrope, system-ui, sans-serif',
        'Nunito' => 'Nunito, system-ui, sans-serif', 'System' => 'system-ui, -apple-system, "Segoe UI", sans-serif',
        'Serif' => 'Georgia, "Times New Roman", serif',
    ];
    // site styles: '' = the original look (site.css alone); the others add
    // app/Builder/looks/<key>.css after it. c6 (second accent) feeds their
    // gradients and highlights; the original look never reads it.
    public const LOOKS = ['' => 'Classic', 'aurora' => 'Aurora (glowing gradients)', 'vivid' => 'Vivid (bold gradient bands)',
        'glass' => 'Glass (frosted cards on colour)', 'midnight' => 'Midnight (dark with neon)', 'bold' => 'Bold (thick outlines, flat colour)',
        'editorial' => 'Editorial (serif, hairline rules)', 'soft' => 'Soft (pastel washes, pill shapes)', 'retro' => 'Retro (warm stripes, 70s print)',
        'luxe' => 'Luxe (dark with gold)'];
    public const CREDIT_TEXT = 'KarnakBuilder - By Karnakpanel.com';
    public const CREDIT_URL = 'https://karnakpanel.com';

    // ---- rows (always scoped to the signed-in account: admins reach a
    // customer's sites through login-as, never by id) ----
    public static function forUser(int $uid): array {
        return Database::query("SELECT s.*, d.domain FROM builder_sites s JOIN domains d ON d.id=s.domain_id AND d.user_id=s.user_id WHERE s.user_id=? ORDER BY d.domain, s.subdir", [$uid])->fetchAll();
    }
    public static function find(int $id, array $user): ?array {
        $r = Database::query("SELECT s.*, d.domain FROM builder_sites s JOIN domains d ON d.id=s.domain_id AND d.user_id=s.user_id WHERE s.id=? AND s.user_id=?", [$id, (int)$user['id']])->fetch();
        return $r ?: null;
    }
    public static function url(array $site): string {
        return 'https://'.$site['domain'].'/'.($site['subdir'] !== '' ? $site['subdir'].'/' : '');
    }
    // a template's sample business name -> the name the customer typed
    // (site title plus the text inside the pages), on project or draft JSON
    public static function retitle(string $json, string $title): string {
        $j = json_decode($json, true);
        if (!is_array($j) || $title === '') return $json;
        $k = isset($j['kbSite']) ? 'kbSite' : 'site';
        $old = (string)($j[$k]['title'] ?? '');
        if (!is_array($j[$k] ?? null) || $old === '') return $json;
        $j[$k]['title'] = $title;
        $e = fn($s) => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $list = $k === 'kbSite' ? 'kbSeed' : 'pages';
        foreach ((array)($j[$list] ?? []) as $i => $pg) {
            if (is_array($pg) && is_string($pg['html'] ?? null)) $j[$list][$i]['html'] = str_replace($e($old), $e($title), $pg['html']);
            if (is_array($pg) && is_string($pg['desc'] ?? null)) $j[$list][$i]['desc'] = str_replace($old, $title, $pg['desc']);
        }
        // project page meta (descriptions) is plain text, keyed by page id
        foreach ((array)($j['kbPages'] ?? []) as $id => $pm) {
            if (is_array($pm) && is_string($pm['desc'] ?? null)) $j['kbPages'][$id]['desc'] = str_replace($old, $title, $pm['desc']);
        }
        return (string)json_encode($j, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    public static function label(array $site): string {
        return $site['domain'].($site['subdir'] !== '' ? '/'.$site['subdir'] : '');
    }
    // fleet switch (settings row absent = on)
    public static function enabled(): bool { return self::setting('builder_enabled', '1') === '1'; }
    public static function setting(string $name, string $def): string {
        try {
            $v = Database::query("SELECT value FROM settings WHERE name=?", [$name])->fetch()['value'] ?? null;
        } catch (Throwable $e) { $v = null; }
        return is_string($v) ? $v : $def;
    }
    public static function setSetting(string $name, string $value): void {
        Database::query("INSERT INTO settings(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [$name, $value]);
    }

    // ---- privileged bridge ----
    // op through scripts/builder.sh; $payload (string) is staged and handed
    // over as a file the root wrapper feeds to the user-side worker
    public static function run(array $site, array $user, string $op, string $arg = '', ?string $payload = null, int $timeout = 30): array {
        $args = [(string)$user['username'], (string)$site['domain'], (string)$site['subdir'], $op, $arg];
        $tmp = null;
        if ($payload !== null) {
            $tmp = self::stage($payload);
            if ($tmp === null) return ['code' => 500, 'out' => 'could not stage the upload'];
            $args[] = $tmp;
        }
        $r = ServiceManager::run('builder.sh', $args, $timeout);
        if ($tmp !== null && is_file($tmp)) @unlink($tmp);
        return $r;
    }
    public static function stageDir(): string {
        $d = realpath(__DIR__.'/../../storage/tmp-uploads');
        return $d !== false ? $d : __DIR__.'/../../storage/tmp-uploads';
    }
    private static function stage(string $bytes): ?string {
        $p = self::stageDir().'/kb-'.bin2hex(random_bytes(12));
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- random name in the panel's own staging dir
        if (@file_put_contents($p, $bytes, LOCK_EX) !== strlen($bytes)) { @unlink($p); return null; }
        @chmod($p, 0600);
        return $p;
    }
    // read one site file; null = absent, false = error (message in $err)
    public static function read(array $site, array $user, string $file, ?string &$err = null): string|null|false {
        $r = self::run($site, $user, 'read', $file);
        if ($r['code'] === 3) return null;
        if ($r['code'] !== 0) { $err = self::reason($r); return false; }
        return (string)$r['out'];
    }
    public static function reason(array $r): string {
        $lines = array_values(array_filter(explode("\n", (string)($r['out'] ?? '')), fn($l) => trim($l) !== ''));
        $last = $lines ? end($lines) : 'failed (code '.(int)($r['code'] ?? 1).')';
        return mb_substr(trim($last), 0, 300);
    }

    // ---- edit lock: a random token per editor tab, idle-expiring ----
    public static function lockFree(array $site, string $token): bool {
        if ($site['lock_token'] === '' || hash_equals((string)$site['lock_token'], $token)) return true;
        return $site['lock_at'] === null || strtotime((string)$site['lock_at']) < time() - self::LOCK_IDLE;
    }
    public static function claimLock(array $site): string {
        $t = bin2hex(random_bytes(16));
        Database::query("UPDATE builder_sites SET lock_token=?, lock_at=NOW() WHERE id=?", [$t, $site['id']]);
        return $t;
    }
    public static function touchLock(array $site, string $token): bool {
        if (!preg_match('/^[0-9a-f]{32}$/', $token) || !self::lockFree($site, $token)) return false;
        Database::query("UPDATE builder_sites SET lock_token=?, lock_at=NOW() WHERE id=?", [$token, $site['id']]);
        return true;
    }

    // the tab is closing: free the lock only if it is still this tab's
    public static function releaseLock(array $site, string $token): void {
        if (!preg_match('/^[0-9a-f]{32}$/', $token)) return;
        Database::query("UPDATE builder_sites SET lock_token='', lock_at=NULL WHERE id=? AND lock_token=?", [$site['id'], $token]);
    }

    // ---- footer credit: admin on AND (reseller opt-out locked OR the owning
    // reseller's switch on). Prefs live in settings so a branding reset
    // never flips them. ----
    public static function resellerOf(array $owner): int {
        if (($owner['role'] ?? '') === 'reseller') return (int)$owner['id'];
        if (($owner['role'] ?? '') === 'user' && !empty($owner['owner_id'])) {
            $o = Database::query("SELECT id, role FROM users WHERE id=?", [(int)$owner['owner_id']])->fetch();
            if ($o && $o['role'] === 'reseller') return (int)$o['id'];
        }
        return 0;
    }
    public static function resellerMayOptOut(): bool { return self::setting('builder_credit_reseller_optout', '1') === '1'; }
    public static function resellerCredit(int $rid): bool { return self::setting('builder_credit_reseller:'.$rid, '1') === '1'; }
    public static function creditOn(array $owner): bool {
        if (self::setting('builder_credit', '1') !== '1') return false;
        $rid = self::resellerOf($owner);
        if ($rid === 0 || !self::resellerMayOptOut()) return true;
        return self::resellerCredit($rid);
    }

    // ---- package limit (0 = builder off for the plan; no plan = unlimited) ----
    public static function limit(array $user): ?int {
        try {
            $pkgId = Database::query("SELECT package_id FROM users WHERE id=?", [$user['id']])->fetch()['package_id'] ?? null;
            if (!$pkgId) return null;
            $v = Database::query("SELECT max_builder_sites FROM packages WHERE id=?", [$pkgId])->fetch()['max_builder_sites'] ?? null;
            return $v === null ? null : (int)$v;
        } catch (Throwable $e) { return 0; }  // fail closed: a new feature never opens on a DB error
    }

    // ---- theme ----
    public static function theme(array $t): array {
        $out = ['c1' => '#1f3a8a', 'c2' => '#3b82f6', 'c3' => '#0f172a', 'c4' => '#f8fafc', 'c5' => '#ffffff',
            'head' => 'Inter', 'body' => 'Inter', 'radius' => 12];
        foreach (['c1', 'c2', 'c3', 'c4', 'c5'] as $k) {
            if (isset($t[$k]) && is_string($t[$k]) && preg_match('/^#[0-9a-fA-F]{6}$/', $t[$k])) $out[$k] = strtolower($t[$k]);
        }
        foreach (['head', 'body'] as $k) {
            if (isset($t[$k]) && is_string($t[$k]) && isset(self::FONTS[$t[$k]])) $out[$k] = $t[$k];
        }
        if (isset($t['radius']) && is_numeric($t['radius'])) $out['radius'] = max(0, min(40, (int)$t['radius']));
        // only when set, so a classic site's theme (and its CSS) is unchanged
        if (isset($t['c6']) && is_string($t['c6']) && preg_match('/^#[0-9a-fA-F]{6}$/', $t['c6'])) $out['c6'] = strtolower($t['c6']);
        if (isset($t['style']) && is_string($t['style']) && $t['style'] !== '' && isset(self::LOOKS[$t['style']])) $out['style'] = $t['style'];
        return $out;
    }
    public static function themeCss(array $t): string {
        $t = self::theme($t);
        $c6 = isset($t['style']) ? ';--kb-c6:'.($t['c6'] ?? $t['c2']) : '';
        return ":root{--kb-c1:{$t['c1']};--kb-c2:{$t['c2']};--kb-c3:{$t['c3']};--kb-c4:{$t['c4']};--kb-c5:{$t['c5']}$c6;"
            .'--kb-head:'.self::FONTS[$t['head']].';--kb-body:'.self::FONTS[$t['body']].";--kb-radius:{$t['radius']}px}\n";
    }
    public static function baseCss(): string {
        $f = __DIR__.'/../Builder/site.css';
        return is_file($f) ? (string)file_get_contents($f) : '';
    }
    // the style's own rules, after site.css ('' for the classic look)
    public static function lookCss(string $look): string {
        if ($look === '' || !isset(self::LOOKS[$look])) return '';
        $f = __DIR__."/../Builder/looks/$look.css";
        return is_file($f) ? (string)file_get_contents($f) : '';
    }
    // one-click theme presets: the shipped templates that use a style, plus
    // looks/presets.json (palettes for styles no template uses yet)
    public static function presets(): array {
        $src = [];
        foreach ((array)glob(__DIR__.'/../Builder/templates/*/meta.json') as $f) $src[] = json_decode((string)file_get_contents((string)$f), true);
        $f = __DIR__.'/../Builder/looks/presets.json';
        if (is_file($f)) foreach ((array)json_decode((string)file_get_contents($f), true) as $m) $src[] = $m;
        $o = []; $seen = [];
        foreach ($src as $m) {
            if (!is_array($m) || !is_array($m['theme'] ?? null) || empty($m['theme']['style'])) continue;
            $th = self::theme($m['theme']);
            // a presets.json palette a template now ships is listed once, under the template
            $k = json_encode($th);
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $o[] = ['name' => self::line((string)($m['name'] ?? ''), 40), 'theme' => $th];
        }
        usort($o, fn($a, $b) => [array_search($a['theme']['style'], array_keys(self::LOOKS)), $a['name']] <=> [array_search($b['theme']['style'], array_keys(self::LOOKS)), $b['name']]);
        return $o;
    }
    public static function looksCss(): array {
        $o = [];
        foreach (self::LOOKS as $k => $label) if ($k !== '') $o[$k] = self::lookCss($k);
        return $o;
    }

    // ---- draft validation (save) ----
    // returns [normalised draft, null] or [null, error]
    public static function checkDraft(mixed $d): array {
        if (!is_array($d)) return [null, 'Draft is not valid'];
        $site = is_array($d['site'] ?? null) ? $d['site'] : [];
        $title = self::line((string)($site['title'] ?? ''), 120);
        $lang = (string)($site['lang'] ?? 'en');
        if (!preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', $lang)) $lang = 'en';
        $pages = $d['pages'] ?? null;
        if (!is_array($pages) || !$pages) return [null, 'A site needs at least one page'];
        if (count($pages) > self::MAX_PAGES + self::MAX_POSTS) return [null, 'At most '.self::MAX_PAGES.' pages and '.self::MAX_POSTS.' posts per site'];
        $out = []; $seen = ['page' => [], 'post' => []]; $total = 0; $nk = ['page' => 0, 'post' => 0];
        foreach (array_values($pages) as $i => $p) {
            if (!is_array($p)) return [null, 'Bad page data'];
            $slug = (string)($p['slug'] ?? '');
            $kind = $i > 0 && ($p['kind'] ?? '') === 'post' ? 'post' : 'page';  // the home page is never a post
            if ($i === 0) $slug = '';  // first page is always the home page
            elseif (!preg_match(self::SLUG_RE, $slug) || in_array($slug, ['assets', '_kb', 'index', '404', 'sitemap', 'robots'], true)) {
                return [null, "Page address \"".mb_substr($slug, 0, 64)."\" is not allowed (lower-case letters, digits and dashes; not assets, _kb, index, 404)"];
            }
            if ($kind === 'post' && in_array($slug, self::POST_RESERVED, true)) return [null, "Post address \"$slug\" is reserved for the blog (pick another)"];
            if (isset($seen[$kind][$slug])) return [null, $kind === 'post' ? "Two posts share the address /blog/$slug/" : "Two pages share the address /$slug/"];
            $seen[$kind][$slug] = true;
            if (++$nk[$kind] > ($kind === 'post' ? self::MAX_POSTS : self::MAX_PAGES)) return [null, $kind === 'post' ? 'At most '.self::MAX_POSTS.' posts per site' : 'At most '.self::MAX_PAGES.' pages per site'];
            $date = ''; $tags = [];
            if ($kind === 'post') {
                $date = (string)($p['date'] ?? '');
                if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $dm) || !checkdate((int)$dm[2], (int)$dm[3], (int)$dm[1])) {
                    return [null, 'Post "'.self::line((string)($p['title'] ?? $slug), 60).'" needs a valid date'];
                }
                $raw = is_array($p['tags'] ?? null) ? $p['tags'] : explode(',', (string)($p['tags'] ?? ''));
                foreach ($raw as $t) {
                    $label = self::line((string)$t, 40); $ts = self::tagSlug($label);
                    if ($ts !== '' && !isset($tags[$ts]) && count($tags) < 10) $tags[$ts] = $label;
                }
            }
            $html = (string)($p['html'] ?? ''); $css = (string)($p['css'] ?? '');
            if (strlen($html) + strlen($css) > self::MAX_PAGE_BYTES) return [null, 'Page "'.self::line((string)($p['title'] ?? $slug), 60).'" is too large'];
            $total += strlen($html) + strlen($css);
            $out[] = ['slug' => $slug, 'title' => self::line((string)($p['title'] ?? ''), 120) ?: ($slug === '' ? 'Home' : ucfirst($slug)),
                'desc' => self::line((string)($p['desc'] ?? ''), 300), 'noindex' => !empty($p['noindex']),
                'nav' => $kind === 'page' && (!array_key_exists('nav', $p) || !empty($p['nav'])), 'html' => $html, 'css' => $css,
                'kind' => $kind, 'date' => $date, 'tags' => array_values($tags), 'draft' => $kind === 'post' && !empty($p['draft'])];
        }
        if ($total > self::MAX_JSON) return [null, 'Site is too large to save'];
        return [['site' => ['title' => $title, 'lang' => $lang, 'theme' => self::theme(is_array($site['theme'] ?? null) ? $site['theme'] : [])], 'pages' => $out], null];
    }
    private static function line(string $s, int $max): string {
        $s = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '');
        return mb_substr($s, 0, $max);
    }

    public static function tagSlug(string $label): string {
        $s = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($label)), '-');
        return preg_match(self::SLUG_RE, substr($s, 0, 64)) ? substr($s, 0, 64) : '';
    }
    // site-relative folder of a page or post ('' = the home page)
    public static function path(array $p): string {
        if ($p['slug'] === '') return '';
        return (($p['kind'] ?? 'page') === 'post' ? 'blog/' : '').$p['slug'].'/';
    }
    // published posts, newest first
    public static function posts(array $draft): array {
        $out = array_values(array_filter($draft['pages'], fn($p) => ($p['kind'] ?? 'page') === 'post' && empty($p['draft'])));
        usort($out, fn($a, $b) => [$b['date'], $a['title']] <=> [$a['date'], $b['title']]);
        return $out;
    }
    // RSS 2.0 of the newest 20 posts; null when nothing is published
    public static function feed(array $draft, string $base): ?string {
        $posts = array_slice(self::posts($draft), 0, 20);
        if (!$posts) return null;
        $x = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<rss version="2.0"><channel>'
            .'<title>'.$x($draft['site']['title'] !== '' ? $draft['site']['title'] : 'Blog').'</title>'
            .'<link>'.$x($base.'blog/').'</link><description>'.$x('Latest posts').'</description>'
            .'<language>'.$x($draft['site']['lang']).'</language>'."\n";
        foreach ($posts as $p) {
            $u = $base.self::path($p);
            $out .= '<item><title>'.$x($p['title']).'</title><link>'.$x($u).'</link><guid isPermaLink="true">'.$x($u).'</guid>'
                .'<pubDate>'.gmdate(DATE_RSS, (int)strtotime($p['date'].' 12:00:00 UTC')).'</pubDate>'
                .($p['desc'] !== '' ? '<description>'.$x($p['desc']).'</description>' : '')
                .implode('', array_map(fn($t) => '<category>'.$x($t).'</category>', $p['tags'])).'</item>'."\n";
        }
        return $out.'</channel></rss>'."\n";
    }
    // the first image of a post that is ours or https (card picture)
    private static function cover(array $p, string $prefix): ?string {
        if (!preg_match('/<img\b[^>]*?\ssrc\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $p['html'], $m)) return null;
        return self::asset(html_entity_decode($m[2] !== '' ? $m[2] : ($m[3] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $prefix);
    }

    // ---- renderer ----
    // $prefix: '/' or '/sub/' for publish, '/builder/preview/<token>/' for
    // preview. Returns [relative path => bytes].
    public static function render(array $draft, string $prefix, bool $credit): array {
        $posts = self::posts($draft);
        $all = $draft['pages'];
        // a post-only site still gets its /blog/ index
        $blogPage = null;
        foreach ($all as $p) if ($p['kind'] === 'page' && $p['slug'] === 'blog') $blogPage = $p;
        if ($blogPage === null && $posts) {
            $blogPage = ['slug' => 'blog', 'kind' => 'page', 'title' => 'Blog', 'desc' => '', 'noindex' => false, 'nav' => false, 'draft' => false,
                'date' => '', 'tags' => [], 'html' => '<nav data-kb="menu"></nav><section class="kb-section"><div class="kb-container"><h1>Blog</h1><div data-kb="posts-all"></div></div></section>', 'css' => ''];
            $all[] = $blogPage;
        }
        $live = array_values(array_filter($all, fn($p) => empty($p['draft'])));
        // link targets: page slugs as before, posts as blog/<slug>
        $slugs = array_map(fn($p) => rtrim(self::path($p), '/'), $live);
        $files = [];
        $nav = [];
        foreach ($live as $p) if ($p['nav']) $nav[] = ['href' => $prefix.self::path($p), 'title' => $p['title']];
        $cards = [];
        foreach ($posts as $p) {
            $tags = [];
            foreach ($p['tags'] as $t) $tags[] = ['label' => $t, 'href' => $prefix.'blog/tag/'.self::tagSlug($t).'/'];
            $cards[] = ['title' => $p['title'], 'href' => $prefix.self::path($p), 'date' => $p['date'], 'desc' => $p['desc'], 'tags' => $tags, 'img' => self::cover($p, $prefix), 'slugs' => array_map([self::class, 'tagSlug'], $p['tags'])];
        }
        $feed = $posts ? $prefix.'blog/feed.xml' : null;
        $one = function (array $p, array $list, ?array $pager, ?array $cur) use ($draft, $prefix, $slugs, $nav, $credit, $feed, $cards): string {
            self::$blog = ['posts' => $list, 'every' => $cards, 'cur' => $cur, 'pager' => $pager, 'all' => $prefix.'blog/'];
            try { return self::page($draft, $p, $prefix, $slugs, $nav, $credit, $feed); }
            finally { self::$blog = ['posts' => [], 'cur' => null, 'pager' => null]; }
        };
        foreach ($live as $p) {
            $cur = null;
            if ($p['kind'] === 'post') foreach ($cards as $c) if ($c['href'] === $prefix.self::path($p)) $cur = $c;
            $isBlog = $p['kind'] === 'page' && $p['slug'] === 'blog';
            $files[self::path($p).'index.html'] = $one($p, $cards, $isBlog ? ['n' => 1, 'per' => self::POSTS_PER_PAGE, 'base' => $prefix.'blog/', 'label' => null] : null, $cur);
        }
        // paged index + tag lists, all rendered from the /blog/ page
        if ($blogPage !== null && $cards) {
            $views = [['blog/', $cards, null]];
            $byTag = [];
            foreach ($cards as $c) foreach ($c['tags'] as $i => $t) { $ts = $c['slugs'][$i]; $byTag[$ts]['label'] ??= $t['label']; $byTag[$ts]['cards'][] = $c; }
            foreach ($byTag as $ts => $t) $views[] = ["blog/tag/$ts/", $t['cards'], $t['label']];
            foreach ($views as [$base, $list, $label]) {
                $pages = (int)ceil(count($list) / self::POSTS_PER_PAGE);
                for ($n = 1; $n <= $pages; $n++) {
                    if ($n === 1 && $label === null) continue;  // that is /blog/ itself, done above
                    $rel = $base.($n > 1 ? "page/$n/" : '');
                    $title = ($label !== null ? 'Posts tagged '.$label : $blogPage['title']).($n > 1 ? " (page $n)" : '');
                    $files[$rel.'index.html'] = $one(array_merge($blogPage, ['title' => $title, 'desc' => '']), $list, ['n' => $n, 'per' => self::POSTS_PER_PAGE, 'base' => $prefix.$base, 'label' => $label], null);
                }
            }
        }
        $files['404.html'] = self::page($draft, ['slug' => '404', 'title' => 'Page not found', 'desc' => '', 'noindex' => true,
            'html' => '<section class="kb-section"><div class="kb-container"><h1>Page not found</h1><p>The page you asked for is not here.</p><p><a class="kb-btn" href="/">Go to the home page</a></p></div></section>', 'css' => ''], $prefix, $slugs, $nav, $credit);
        $files['assets/kb/site.css'] = self::themeCss($draft['site']['theme']).self::baseCss().self::lookCss((string)($draft['site']['theme']['style'] ?? ''));
        return $files;
    }
    public static function sitemap(array $draft, string $base): string {
        $x = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        $blog = false;
        foreach ($draft['pages'] as $p) {
            if (!empty($p['draft'])) continue;
            if (($p['kind'] ?? 'page') === 'page' && $p['slug'] === 'blog') $blog = true;
            if ($p['noindex']) continue;
            $x .= '  <url><loc>'.htmlspecialchars($base.self::path($p), ENT_XML1).'</loc>'
                .(($p['kind'] ?? 'page') === 'post' ? '<lastmod>'.$p['date'].'</lastmod>' : '').'</url>'."\n";
        }
        if (!$blog && self::posts($draft)) $x .= '  <url><loc>'.htmlspecialchars($base.'blog/', ENT_XML1).'</loc></url>'."\n";
        return $x.'</urlset>'."\n";
    }
    private static function page(array $draft, array $p, string $prefix, array $slugs, array $nav, bool $credit, ?string $feed = null): string {
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $siteTitle = $draft['site']['title'];
        $title = $p['slug'] === '' && $siteTitle !== '' ? $siteTitle : $p['title'].($siteTitle !== '' ? ' · '.$siteTitle : '');
        $body = self::cleanHtml($p['html'], $prefix, $slugs, $nav);
        $css = self::cleanCss($p['css'], $prefix);
        $h = '<!doctype html>'."\n".'<html lang="'.$e($draft['site']['lang']).'"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.$e($title).'</title>'
            .($p['desc'] !== '' ? '<meta name="description" content="'.$e($p['desc']).'">' : '')
            .($p['noindex'] ? '<meta name="robots" content="noindex">' : '')
            .'<meta name="generator" content="Karnak Builder">'
            .'<link rel="stylesheet" href="'.$e($prefix).'assets/kb/site.css">'
            .($feed !== null ? '<link rel="alternate" type="application/rss+xml" title="'.$e($siteTitle !== '' ? $siteTitle : 'Blog').'" href="'.$e($feed).'">' : '')
            .($css !== '' ? '<style>'.$css.'</style>' : '')
            .'</head><body class="kb-body">'.$body;
        if ($credit) {
            $h .= '<p class="kb-credit"><a href="'.self::CREDIT_URL.'" rel="noopener" target="_blank">'.$e(self::CREDIT_TEXT).'</a></p>';
        }
        return $h.'</body></html>'."\n";
    }

    private const TAGS = ['a', 'abbr', 'address', 'article', 'aside', 'b', 'blockquote', 'br', 'button', 'caption', 'cite', 'code', 'col', 'colgroup',
        'dd', 'del', 'details', 'div', 'dl', 'dt', 'em', 'figcaption', 'figure', 'footer', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr',
        'i', 'iframe', 'img', 'ins', 'kbd', 'label', 'li', 'main', 'mark', 'nav', 'ol', 'p', 'picture', 'pre', 'q', 's', 'section', 'small',
        'source', 'span', 'strong', 'sub', 'summary', 'sup', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'time', 'tr', 'u', 'ul',
        'svg', 'path', 'circle', 'rect', 'line', 'polyline', 'polygon', 'g', 'ellipse'];
    private const DROP_WITH_CONTENT = ['script', 'style', 'template', 'noscript', 'object', 'embed', 'applet', 'base', 'link', 'meta', 'title', 'head',
        'frame', 'frameset', 'math', 'textarea', 'select', 'option', 'input', 'form', 'foreignobject', 'animate', 'set', 'use', 'image'];
    private const ATTRS = ['id', 'class', 'title', 'alt', 'href', 'src', 'srcset', 'sizes', 'width', 'height', 'target', 'rel', 'colspan', 'rowspan',
        'scope', 'datetime', 'loading', 'decoding', 'allow', 'allowfullscreen', 'frameborder', 'aria-label', 'aria-hidden', 'role', 'open', 'type', 'style',
        'data-kb', 'viewbox', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'd', 'cx', 'cy', 'r', 'x', 'y', 'x1', 'y1', 'x2', 'y2',
        'rx', 'ry', 'points', 'xmlns', 'media'];

    // allow-list sanitiser: unknown tags unwrap, dangerous ones go with their
    // content, attributes are allow-listed, URLs are rewritten/limited
    public static function cleanHtml(string $html, string $prefix, array $slugs, array $nav = []): string {
        if (trim($html) === '') return '';
        $doc = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"?><!doctype html><html><body><div id="kb-root">'.$html.'</div></body></html>', LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors(); libxml_use_internal_errors($prev);
        $root = $doc->getElementById('kb-root');
        if (!$root) return '';
        self::walk($root, $doc, $prefix, $slugs, $nav);
        $out = '';
        foreach (iterator_to_array($root->childNodes) as $c) $out .= $doc->saveHTML($c);
        return $out;
    }
    private static function walk(DOMNode $n, DOMDocument $doc, string $prefix, array $slugs, array $nav): void {
        foreach (iterator_to_array($n->childNodes) as $c) {
            if ($c instanceof DOMComment || $c instanceof DOMProcessingInstruction || $c instanceof DOMCdataSection) { $n->removeChild($c); continue; }
            if (!$c instanceof DOMElement) continue;
            $tag = strtolower($c->localName ?? $c->nodeName);
            if (in_array($tag, self::DROP_WITH_CONTENT, true)) { $n->removeChild($c); continue; }
            if (!in_array($tag, self::TAGS, true)) {
                self::walk($c, $doc, $prefix, $slugs, $nav);
                while ($c->firstChild) $n->insertBefore($c->firstChild, $c);
                $n->removeChild($c);
                continue;
            }
            foreach (iterator_to_array($c->attributes) as $a) {
                $an = strtolower($a->nodeName);
                if (!in_array($an, self::ATTRS, true) && !preg_match('/^aria-[a-z]{1,20}$/', $an)) { $c->removeAttribute($a->nodeName); continue; }
                $v = $a->nodeValue ?? '';
                if ($an === 'style') {
                    $s = self::cleanCss($v, $prefix);
                    if ($s === '' || str_contains($s, '{')) $c->removeAttribute($a->nodeName); else $c->setAttribute('style', $s);
                } elseif ($an === 'href') {
                    $u = self::link($v, $prefix, $slugs);
                    if ($u === null) $c->removeAttribute('href'); else $c->setAttribute('href', $u);
                } elseif ($an === 'src') {
                    $u = $tag === 'iframe' ? self::embed($v) : self::asset($v, $prefix);
                    if ($u === null) $c->removeAttribute('src'); else $c->setAttribute('src', $u);
                } elseif ($an === 'srcset') {
                    $c->removeAttribute('srcset');
                } elseif ($an === 'target') {
                    if ($v !== '_blank') $c->removeAttribute('target');
                }
            }
            if ($tag === 'iframe') {
                if (!$c->hasAttribute('src')) { $n->removeChild($c); continue; }
                $c->setAttribute('loading', 'lazy');
                $c->setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
                // embeds get no top navigation, popups or same-origin access to the site
                $c->setAttribute('sandbox', 'allow-scripts allow-same-origin allow-presentation');
                while ($c->firstChild) $c->removeChild($c->firstChild);
            }
            if ($tag === 'a' && $c->getAttribute('target') === '_blank') $c->setAttribute('rel', 'noopener');
            if ($tag === 'button') $c->setAttribute('type', 'button');
            // blog blocks: emptied and refilled from the published posts
            $kb = $c->getAttribute('data-kb');
            if (preg_match('/^posts-(all|[1-9]|1[0-2])$/', $kb, $bm) || $kb === 'post-meta') {
                while ($c->firstChild) $c->removeChild($c->firstChild);
                $kb === 'post-meta' ? self::fillMeta($c, $doc) : self::fillPosts($c, $doc, $bm[1]);
                continue;
            }
            // auto menu: <nav data-kb="menu"> is rebuilt from the page list
            if ($tag === 'nav' && $c->getAttribute('data-kb') === 'menu') {
                while ($c->firstChild) $c->removeChild($c->firstChild);
                foreach ($nav as $item) {
                    $a = $doc->createElement('a');
                    $a->setAttribute('href', $item['href']);
                    $a->appendChild($doc->createTextNode($item['title']));
                    $c->appendChild($a);
                }
                continue;
            }
            self::walk($c, $doc, $prefix, $slugs, $nav);
        }
    }
    // every value goes in as a text node; hrefs are built by render()
    private static function el(DOMDocument $doc, string $tag, array $attrs = [], ?string $text = null): DOMElement {
        $n = $doc->createElement($tag);
        foreach ($attrs as $k => $v) $n->setAttribute($k, $v);
        if ($text !== null) $n->appendChild($doc->createTextNode($text));
        return $n;
    }
    private static function day(string $ymd): string {
        $t = strtotime($ymd.' 12:00:00 UTC');
        return $t === false ? $ymd : gmdate('j M Y', $t);
    }
    private static function fillMeta(DOMElement $c, DOMDocument $doc): void {
        $cur = self::$blog['cur'];
        if ($cur === null) return;
        $c->setAttribute('class', trim($c->getAttribute('class').' kb-post-meta'));
        $c->appendChild(self::el($doc, 'time', ['datetime' => $cur['date']], self::day($cur['date'])));
        foreach ($cur['tags'] as $t) $c->appendChild(self::el($doc, 'a', ['class' => 'kb-tag', 'href' => $t['href']], $t['label']));
    }
    private static function fillPosts(DOMElement $c, DOMDocument $doc, string $mode): void {
        $pager = self::$blog['pager'];
        // latest-N always means the whole blog, even on a tag page
        $list = $mode === 'all' ? self::$blog['posts'] : (self::$blog['every'] ?? []);
        $c->setAttribute('class', trim($c->getAttribute('class').' kb-posts'));
        if ($mode === 'all' && $pager !== null) {
            if ($pager['label'] !== null) $c->appendChild(self::el($doc, 'h2', ['class' => 'kb-posts-title'], 'Posts tagged “'.$pager['label'].'”'));
            $pages = max(1, (int)ceil(count($list) / $pager['per']));
            $list = array_slice($list, ($pager['n'] - 1) * $pager['per'], $pager['per']);
        } else {
            // a full list outside /blog/ shows the first page and links on
            $list = array_slice($list, 0, $mode === 'all' ? $pager['per'] ?? self::POSTS_PER_PAGE : (int)$mode);
        }
        if (!$list) { $c->appendChild(self::el($doc, 'p', ['class' => 'kb-posts-none'], 'No posts yet.')); return; }
        $grid = self::el($doc, 'div', ['class' => 'kb-post-grid']);
        foreach ($list as $p) {
            $a = self::el($doc, 'article', ['class' => 'kb-post-card']);
            if ($p['img'] !== null) {
                $ia = self::el($doc, 'a', ['class' => 'kb-post-img', 'href' => $p['href'], 'tabindex' => '-1', 'aria-hidden' => 'true']);
                $ia->appendChild(self::el($doc, 'img', ['src' => $p['img'], 'alt' => '', 'loading' => 'lazy']));
                $a->appendChild($ia);
            }
            $h = self::el($doc, 'h3'); $h->appendChild(self::el($doc, 'a', ['href' => $p['href']], $p['title'])); $a->appendChild($h);
            $a->appendChild(self::el($doc, 'p', ['class' => 'kb-post-date'])); $a->lastChild->appendChild(self::el($doc, 'time', ['datetime' => $p['date']], self::day($p['date'])));
            if ($p['desc'] !== '') $a->appendChild(self::el($doc, 'p', [], $p['desc']));
            $grid->appendChild($a);
        }
        $c->appendChild($grid);
        if ($mode === 'all' && $pager !== null && $pages > 1) {
            $nv = self::el($doc, 'nav', ['class' => 'kb-pager', 'aria-label' => 'More posts']);
            $at = fn(int $n) => $pager['base'].($n > 1 ? "page/$n/" : '');
            if ($pager['n'] > 1) $nv->appendChild(self::el($doc, 'a', ['href' => $at($pager['n'] - 1), 'rel' => 'prev'], '← Newer'));
            $nv->appendChild(self::el($doc, 'span', [], 'Page '.$pager['n'].' of '.$pages));
            if ($pager['n'] < $pages) $nv->appendChild(self::el($doc, 'a', ['href' => $at($pager['n'] + 1), 'rel' => 'next'], 'Older →'));
            $c->appendChild($nv);
        } elseif ($mode !== 'all' || $pager === null) {
            $c->appendChild(self::el($doc, 'p', ['class' => 'kb-posts-more'])); $c->lastChild->appendChild(self::el($doc, 'a', ['class' => 'kb-btn', 'href' => self::$blog['all'] ?? '/blog/'], 'All posts'));
        }
    }
    // page links: "/" or "/<slug>/" of a page in this site -> prefixed;
    // otherwise only http(s), mailto, tel and in-page anchors survive
    public static function link(string $v, string $prefix, array $slugs): ?string {
        $v = trim($v);
        if ($v === '' || str_starts_with($v, '#')) return $v === '' ? null : (preg_match('/^#[A-Za-z0-9_-]{0,80}$/', $v) ? $v : null);
        if (preg_match('~^/(blog/[a-z0-9-]{1,64}|[a-z0-9-]{0,64})/?(#[A-Za-z0-9_-]{0,80})?$~', $v, $m)) {
            if ($m[1] === '' || in_array($m[1], $slugs, true)) return $prefix.($m[1] === '' ? '' : $m[1].'/').($m[2] ?? '');
        }
        if (str_starts_with($v, '/builder/a/')) return self::asset($v, $prefix);
        if (preg_match('~^https?://[^\s"\'<>\\\\]{1,2000}$~i', $v) && filter_var($v, FILTER_VALIDATE_URL)) return $v;
        if (preg_match('/^mailto:[^\s"\'<>:\/\\\\]{3,254}$/i', $v)) return $v;
        if (preg_match('/^tel:\+?[0-9 ().-]{3,32}$/i', $v)) return $v;
        return null;
    }
    // images: our own uploads (canvas URL /builder/a/<id>/<name>) -> the
    // published copy; https images elsewhere allowed; nothing else
    public static function asset(string $v, string $prefix): ?string {
        $v = trim($v);
        if (preg_match('~^/builder/a/\d{1,10}/([a-z0-9][a-z0-9_-]{0,80}\.(?:webp|jpg|png|gif))$~', $v, $m)) return $prefix.'assets/kb/img/'.$m[1];
        if (preg_match('~^(?:\.\./)*assets/kb/img/([a-z0-9][a-z0-9_-]{0,80}\.(?:webp|jpg|png|gif))$~', $v, $m)) return $prefix.'assets/kb/img/'.$m[1];
        if (preg_match('~^https://[^\s"\'<>\\\\()]{1,2000}$~i', $v) && filter_var($v, FILTER_VALIDATE_URL)) return $v;
        return null;
    }
    public static function embed(string $v): ?string {
        $v = trim($v);
        if (!filter_var($v, FILTER_VALIDATE_URL) || !preg_match('~^https://~i', $v)) return null;
        $h = strtolower((string)parse_url($v, PHP_URL_HOST));
        return in_array($h, self::EMBED_HOSTS, true) ? $v : null;
    }
    // CSS: no markup break-out, no imports/expressions/behaviours, url()
    // only to our images or https
    public static function cleanCss(string $css, string $prefix): string {
        if ($css === '') return '';
        $css = str_replace(["\0", "\x01", '<', '>'], '', $css);
        $css = preg_replace('~/\*.*?\*/~s', '', $css) ?? '';
        $css = preg_replace('/\\\\[0-9a-fA-F]{1,6}\s?|\\\\./', '', $css) ?? '';   // no escapes: they hide keywords
        if (preg_match('/@import|@charset|@namespace|expression\s*\(|javascript:|vbscript:|behavior\s*:|-moz-binding|binding\s*:/i', $css)) {
            $css = preg_replace('/@import[^;]*;?|@charset[^;]*;?|@namespace[^;]*;?/i', '', $css) ?? '';
            $css = preg_replace('/expression\s*\(|javascript:|vbscript:|behavior\s*:|-moz-binding|binding\s*:/i', 'x', $css) ?? '';
        }
        // vetted url()s park as \x01N; anything url-like still left over
        // (unclosed, nested, image-set strings) is neutralised
        $keep = [];
        $css = preg_replace_callback('/url\(\s*([\'"]?)([^\'")]*)\1\s*\)/i', function ($m) use ($prefix, &$keep) {
            $u = self::asset($m[2], $prefix);
            if ($u === null) return 'none';
            $keep[] = 'url("'.str_replace(['"', '\\', "\n", "\r"], '', $u).'")';
            return "\x01".(count($keep) - 1)."\x01";
        }, $css) ?? '';
        $css = preg_replace('/\b(?:url|image-set|-webkit-image-set|src|image)\s*\(/i', 'x(', $css) ?? '';
        $css = preg_replace_callback('/\x01(\d+)\x01/', fn($m) => $keep[(int)$m[1]] ?? 'none', $css) ?? '';
        return trim(str_replace("\x01", '', $css));
    }

    // ---- uploads: decode + re-encode with GD (drops metadata, payloads,
    // polyglots); SVG never accepted ----
    public static function reencode(string $tmp, string $origName, ?string &$err = null): ?array {
        $info = @getimagesize($tmp);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) { $err = 'Only JPG, PNG, GIF or WebP images'; return null; }
        [$w, $h] = $info;
        if ($w < 1 || $h < 1 || $w * $h > 40_000_000) { $err = 'Image is too large (40 megapixels max)'; return null; }
        $src = @imagecreatefromstring((string)file_get_contents($tmp));
        if (!$src) { $err = 'Could not read that image'; return null; }
        $max = 2560;
        if ($w > $max || $h > $max) {
            $s = min($max / $w, $max / $h);
            $nw = max(1, (int)round($w * $s)); $nh = max(1, (int)round($h * $s));
            $dst = imagecreatetruecolor($nw, $nh);
            imagealphablending($dst, false); imagesavealpha($dst, true);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($src); $src = $dst; $w = $nw; $h = $nh;
        } elseif (!imageistruecolor($src)) {
            imagepalettetotruecolor($src);
        }
        imagesavealpha($src, true);
        $base = strtolower(pathinfo($origName, PATHINFO_FILENAME));
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', $base) ?? '', '-');
        $base = substr($base !== '' ? $base : 'image', 0, 60);
        ob_start();
        $ok = function_exists('imagewebp') ? imagewebp($src, null, 82) : imagejpeg($src, null, 85);
        $bytes = (string)ob_get_clean();
        imagedestroy($src);
        if (!$ok || $bytes === '') { $err = 'Could not convert that image'; return null; }
        $ext = function_exists('imagewebp') ? 'webp' : 'jpg';
        return ['name' => $base.'-'.bin2hex(random_bytes(3)).'.'.$ext, 'bytes' => $bytes, 'w' => $w, 'h' => $h];
    }
    public static function mime(string $name): ?string {
        $m = ['webp' => 'image/webp', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif'];
        return $m[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? null;
    }
}
