<?php
declare(strict_types=1);
// Karnak Builder: drag-and-drop static sites per domain (or subfolder).
// Every action resolves the site through the SIGNED-IN account (admins and
// resellers reach a customer's sites via login-as, never by id). All file
// work goes through scripts/builder.sh, which drops to the account user.
class BuilderController extends Controller {
    private const EDIT_CSP = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; font-src 'self' data:; connect-src 'self'; frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com https://www.openstreetmap.org; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'";
    // tenant-made bytes on the panel origin: no script, no same-origin, no forms
    private const RAW_CSP = "sandbox; default-src 'none'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline'; font-src 'self'; frame-src https://www.youtube-nocookie.com https://player.vimeo.com https://www.openstreetmap.org; base-uri 'none'; form-action 'none'";

    private function gate(): array {
        Auth::requireLogin();
        $u = Auth::user();
        if (!Builder::enabled()) { Helpers::flash('err', 'Karnak Builder is switched off on this server'); Helpers::redirect('/'); }
        return $u;
    }
    private function jgate(): array {
        Auth::requireLogin();
        if (!Builder::enabled()) $this->json(['ok' => false, 'error' => 'Karnak Builder is switched off on this server'], 403);
        return Auth::user();
    }
    // POSTed JSON endpoints: CSRF + own site + this tab holds the edit lock
    private function jsite(array $u, bool $needLock = true): array {
        if (!Helpers::checkCsrf()) {
            $big = (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && !$_POST;
            $this->json(['ok' => false, 'error' => $big ? 'Too large to send in one go (server limit '.ini_get('post_max_size').')' : 'Session expired — reload the editor'], 400);
        }
        $site = Builder::find((int)($_POST['id'] ?? 0), $u);
        if (!$site) $this->json(['ok' => false, 'error' => 'Site not found'], 404);
        if ($needLock && !Builder::touchLock($site, (string)($_POST['lock'] ?? ''))) {
            $this->json(['ok' => false, 'locked' => true, 'error' => 'This site is open in another editor tab or session'], 409);
        }
        return $site;
    }

    public function list(): void {
        $u = $this->gate();
        $sites = [];
        try { $sites = Builder::forUser((int)$u['id']); } catch (Throwable $e) {}
        $limit = Builder::limit($u);
        $domains = DomainModel::forUser((int)$u['id']);
        $this->view('builder', ['sites' => $sites, 'limit' => $limit, 'domains' => $domains,
            'credit' => Builder::creditOn($u)]);
    }

    public function create(): void {
        $u = $this->gate();
        if (!Helpers::checkCsrf()) { Helpers::flash('err', 'Bad CSRF'); Helpers::redirect('/builder'); }
        $limit = Builder::limit($u);
        $have = count(Builder::forUser((int)$u['id']));
        if ($limit !== null && $have >= $limit) {
            Helpers::flash('err', $limit === 0 ? 'Karnak Builder is not part of your plan — ask your host to enable it' : "Your plan allows $limit builder site(s) and all are in use");
            Helpers::redirect('/builder');
        }
        $dom = null;
        foreach (DomainModel::forUser((int)$u['id']) as $d) if ((int)$d['id'] === (int)($_POST['domain_id'] ?? 0)) $dom = $d;
        if (!$dom) { Helpers::flash('err', 'Pick one of your domains'); Helpers::redirect('/builder'); }
        $sub = strtolower(trim((string)($_POST['subdir'] ?? ''), " /"));
        if ($sub !== '' && !preg_match(Builder::SUB_RE, $sub)) { Helpers::flash('err', 'Folder: letters, digits, - and _ only (64 max), or leave empty for the domain root'); Helpers::redirect('/builder'); }
        if (in_array($sub, ['assets', '_kb', 'cgi-bin', '.well-known'], true)) { Helpers::flash('err', "The folder name $sub is reserved"); Helpers::redirect('/builder'); }
        // an app installed by Software owns that folder (the worker's
        // empty-folder check is the enforcer; this is the friendly message)
        try {
            $clash = Database::query("SELECT app FROM site_installs WHERE domain_id=? AND TRIM(BOTH '/' FROM path)=?", [$dom['id'], $sub])->fetch();
            if ($clash) { Helpers::flash('err', Builder::label(['domain' => $dom['domain'], 'subdir' => $sub])." already runs {$clash['app']} from Software — pick another domain or folder"); Helpers::redirect('/builder'); }
        } catch (Throwable $e) {}
        $title = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)($_POST['title'] ?? '')) ?? ''), 0, 120);
        $tpl = (string)($_POST['template'] ?? 'blank');
        $tplDir = __DIR__.'/../Builder/templates';
        // the path comes from the shipped list, never from the request
        $tplPath = null;
        foreach ((array)glob("$tplDir/*/project.json") as $f) {
            if (basename(dirname((string)$f)) === $tpl) $tplPath = dirname((string)$f);
        }
        if ($tplPath === null) $tpl = 'blank';
        try {
            Database::query("INSERT INTO builder_sites(user_id,domain_id,subdir,title,template) VALUES(?,?,?,?,?)", [$u['id'], $dom['id'], $sub, $title, $tpl]);
        } catch (Throwable $e) { Helpers::flash('err', 'There is already a builder site at '.$dom['domain'].($sub !== '' ? "/$sub" : '')); Helpers::redirect('/builder'); }
        $id = (int)Database::conn()->lastInsertId();
        $site = Builder::find($id, $u);
        // the target must still be free; refuse early instead of at first publish
        $st = $site ? Builder::run($site, $u, 'status') : ['code' => 1, 'out' => 'not found'];
        $out = trim((string)($st['out'] ?? ''));
        if (($st['code'] ?? 1) !== 0 || str_starts_with($out, 'occupied')) {
            Database::query("DELETE FROM builder_sites WHERE id=? AND user_id=?", [$id, $u['id']]);
            $what = str_replace(' ', ', ', trim(substr($out, 8)));
            $why = ($st['code'] ?? 1) !== 0 ? Builder::reason($st) : 'that folder already holds files'.($what !== '' ? " ($what)" : '').' — pick an empty folder, or move them to Trash in the File Manager first';
            Helpers::flash('err', 'Not created: '.$why);
            Helpers::redirect('/builder');
        }
        if ($tplPath !== null && $site) {
            $p = Builder::retitle((string)file_get_contents("$tplPath/project.json"), $title);
            $r = Builder::run($site, $u, 'write', 'project', $p);
            $d = is_file("$tplPath/draft.json") ? Builder::retitle((string)file_get_contents("$tplPath/draft.json"), $title) : null;
            if ($d !== null) Builder::run($site, $u, 'write', 'draft', $d);
            if (($r['code'] ?? 1) !== 0) Helpers::flash('err', 'Site created, but the template could not be copied: '.Builder::reason($r));
        }
        Helpers::log("builder: {$u['username']} created site $id on {$dom['domain']}/$sub ($tpl)");
        Helpers::redirect('/builder/edit?id='.$id);
    }

    public function delete(): void {
        $u = $this->gate();
        if (!Helpers::checkCsrf()) { Helpers::flash('err', 'Bad CSRF'); Helpers::redirect('/builder'); }
        $site = Builder::find((int)($_POST['id'] ?? 0), $u);
        if (!$site) { Helpers::flash('err', 'Site not found'); Helpers::redirect('/builder'); }
        $r = Builder::run($site, $u, 'delete', '', null, 120);
        if (($r['code'] ?? 1) !== 0) { Helpers::flash('err', 'Not deleted: '.Builder::reason($r)); Helpers::redirect('/builder'); }
        Database::query("DELETE FROM builder_sites WHERE id=? AND user_id=?", [$site['id'], $u['id']]);
        Helpers::log("builder: {$u['username']} deleted site {$site['id']} (".Builder::label($site).")");
        Helpers::flash('ok', 'Deleted the builder site for '.Builder::label($site).($site['status'] === 'published' ? ' and took its pages offline' : ''));
        Helpers::redirect('/builder');
    }

    // standalone editor page: no layout, no inline script, own strict CSP
    public function edit(): void {
        $u = $this->gate();
        $site = Builder::find((int)($_GET['id'] ?? 0), $u);
        if (!$site) { Helpers::flash('err', 'Site not found'); Helpers::redirect('/builder'); }
        $lock = Builder::lockFree($site, '') ? Builder::claimLock($site) : '';
        header('Content-Security-Policy: '.self::EDIT_CSP);
        header('Cache-Control: no-store');
        $kbCfg = [
            'id' => (int)$site['id'], 'label' => Builder::label($site), 'url' => Builder::url($site), 'csrf' => Helpers::csrf(), 'lock' => $lock,
            'status' => $site['status'], 'title' => $site['title'], 'fonts' => Builder::FONTS, 'credit' => Builder::creditOn($u),
            'creditText' => Builder::CREDIT_TEXT, 'upload' => self::uploadLimit(), 'embedHosts' => Builder::EMBED_HOSTS,
            'baseCss' => Builder::baseCss(), 'looks' => Builder::looksCss(), 'lookNames' => Builder::LOOKS, 'presets' => Builder::presets(),
        ];
        $kbBrand = Branding::forUser($u);
        require __DIR__.'/../Views/builder-editor.php';
    }
    private static function uploadLimit(): int {
        $b = function (string $k): int {
            $v = trim((string)ini_get($k)); $n = (int)$v;
            return match (strtoupper(substr($v, -1))) { 'G' => $n << 30, 'M' => $n << 20, 'K' => $n << 10, default => $n };
        };
        return max(65536, min($b('upload_max_filesize'), $b('post_max_size') - 65536));
    }

    // editor bootstrap: project data + saved draft (pages as exported)
    public function load(): void {
        $u = $this->jgate();
        $site = Builder::find((int)($_GET['id'] ?? 0), $u);
        if (!$site) $this->json(['ok' => false, 'error' => 'Site not found'], 404);
        $err = null;
        $p = Builder::read($site, $u, 'project.json', $err);
        if ($p === false) $this->json(['ok' => false, 'error' => $err], 500);
        $d = Builder::read($site, $u, 'draft.json', $err);
        $project = $p === null ? null : json_decode($p, true);
        $draft = is_string($d) ? json_decode($d, true) : null;
        $this->json(['ok' => true, 'project' => is_array($project) ? $project : null,
            'site' => is_array($draft['site'] ?? null) ? $draft['site'] : ['title' => $site['title'], 'lang' => 'en', 'theme' => Builder::theme([])],
            'pages' => is_array($draft['pages'] ?? null) ? array_map(fn($x) => array_diff_key((array)$x, ['html' => 1, 'css' => 1]), $draft['pages']) : []]);
    }

    public function save(): void {
        $u = $this->jgate();
        $site = $this->jsite($u);
        $project = (string)($_POST['project'] ?? '');
        $draftRaw = (string)($_POST['draft'] ?? '');
        if (strlen($project) > Builder::MAX_JSON || strlen($draftRaw) > Builder::MAX_JSON) $this->json(['ok' => false, 'error' => 'Site is too large to save'], 413);
        $pj = json_decode($project, true);
        if (!is_array($pj)) $this->json(['ok' => false, 'error' => 'Editor data did not arrive intact — try again'], 400);
        [$draft, $err] = Builder::checkDraft(json_decode($draftRaw, true));
        if ($draft === null) $this->json(['ok' => false, 'error' => $err], 400);
        $r = Builder::run($site, $u, 'write', 'draft', json_encode($draft, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if (($r['code'] ?? 1) === 0) $r = Builder::run($site, $u, 'write', 'project', json_encode($pj, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if (($r['code'] ?? 1) !== 0) $this->json(['ok' => false, 'error' => 'Not saved: '.Builder::reason($r)], 500);
        Database::query("UPDATE builder_sites SET saved_at=NOW(), title=? WHERE id=?", [$draft['site']['title'], $site['id']]);
        $this->json(['ok' => true, 'saved' => date('H:i:s')]);
    }

    public function takeover(): void {
        $u = $this->jgate();
        $site = $this->jsite($u, false);
        $this->json(['ok' => true, 'lock' => Builder::claimLock($site)]);
    }
    // sent by sendBeacon on pagehide, so a reload or re-open is not locked out
    public function release(): void {
        $u = $this->jgate();
        $site = $this->jsite($u, false);
        Builder::releaseLock($site, (string)($_POST['lock'] ?? ''));
        $this->json(['ok' => true]);
    }
    // keep-alive while the editor is open (lock idles out after 10 min)
    public function ping(): void {
        $u = $this->jgate();
        $this->jsite($u);
        $this->json(['ok' => true]);
    }

    public function publish(): void {
        $u = $this->jgate();
        $site = $this->jsite($u);
        [$code, $msg] = self::doPublish($site, $u);
        if ($code !== 0) $this->json(['ok' => false, 'error' => $msg], $code);
        $this->json(['ok' => true, 'url' => Builder::url($site), 'msg' => $msg]);
    }
    // list page "Put back online": republish the saved draft (no edit lock
    // needed — it only reads what was saved)
    public function republish(): void {
        $u = $this->gate();
        if (!Helpers::checkCsrf()) { Helpers::flash('err', 'Bad CSRF'); Helpers::redirect('/builder'); }
        $site = Builder::find((int)($_POST['id'] ?? 0), $u);
        if (!$site) { Helpers::flash('err', 'Site not found'); Helpers::redirect('/builder'); }
        [$code, $msg] = self::doPublish($site, $u);
        if ($code !== 0) Helpers::flash('err', $msg);
        else Helpers::flash('ok', Builder::label($site).' is back online.');
        Helpers::redirect('/builder');
    }
    // render the saved draft and hand it to builder.sh; [0, msg] on success,
    // [http code, error] otherwise
    private static function doPublish(array $site, array $u): array {
        $err = null;
        $raw = Builder::read($site, $u, 'draft.json', $err);
        if ($raw === null) return [400, 'Nothing saved yet — open the editor and save first'];
        if ($raw === false) return [500, (string)$err];
        [$draft, $err] = Builder::checkDraft(json_decode($raw, true));
        if ($draft === null) return [400, (string)$err];
        $prefix = '/'.($site['subdir'] !== '' ? $site['subdir'].'/' : '');
        $files = Builder::render($draft, $prefix, Builder::creditOn($u));
        $files['sitemap.xml'] = Builder::sitemap($draft, Builder::url($site));
        if (($feed = Builder::feed($draft, Builder::url($site))) !== null) $files['blog/feed.xml'] = $feed;
        if ($site['subdir'] === '') $files['robots.txt'] = "User-agent: *\nAllow: /\nSitemap: ".Builder::url($site)."sitemap.xml\n";
        $bundle = Builder::stageDir().'/kb-'.bin2hex(random_bytes(12));
        if (!@mkdir("$bundle/site", 0700, true)) return [500, 'Could not stage the publish'];
        foreach ($files as $rel => $bytes) {
            $dir = dirname("$bundle/site/$rel");
            if (!is_dir($dir)) @mkdir($dir, 0700, true);
            // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- paths built from validated slugs (Builder::SLUG_RE) inside a random staging dir
            file_put_contents("$bundle/site/$rel", $bytes);
        }
        $r = ServiceManager::run('builder.sh', [$u['username'], $site['domain'], $site['subdir'], 'publish', '-', $bundle], 180);
        if (is_dir($bundle)) self::rmTree($bundle);
        if (($r['code'] ?? 1) !== 0) return [500, 'Not published: '.Builder::reason($r)];
        Database::query("UPDATE builder_sites SET status='published', published_at=NOW() WHERE id=?", [$site['id']]);
        Helpers::log("builder: {$u['username']} published site {$site['id']} (".Builder::label($site)."): ".Builder::reason($r));
        return [0, Builder::reason($r)];
    }
    private static function rmTree(string $d): void {
        $real = realpath($d);
        $stage = Builder::stageDir();
        if ($real === false || !str_starts_with($real, $stage.'/kb-')) return;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
        @rmdir($real);
    }

    public function unpublish(): void {
        $u = $this->gate();
        if (!Helpers::checkCsrf()) { Helpers::flash('err', 'Bad CSRF'); Helpers::redirect('/builder'); }
        $site = Builder::find((int)($_POST['id'] ?? 0), $u);
        if (!$site) { Helpers::flash('err', 'Site not found'); Helpers::redirect('/builder'); }
        $r = Builder::run($site, $u, 'unpublish', '', null, 120);
        if (($r['code'] ?? 1) !== 0) { Helpers::flash('err', 'Not taken offline: '.Builder::reason($r)); Helpers::redirect('/builder'); }
        Database::query("UPDATE builder_sites SET status='unpublished' WHERE id=?", [$site['id']]);
        Helpers::flash('ok', Builder::label($site).' is offline (a "coming soon" page shows). The design is kept — publish again any time.');
        Helpers::redirect('/builder');
    }

    // ---- images ----
    public function assets(): void {
        $u = $this->jgate();
        $site = Builder::find((int)($_GET['id'] ?? 0), $u);
        if (!$site) $this->json(['ok' => false, 'error' => 'Site not found'], 404);
        $r = Builder::run($site, $u, 'asset-list');
        if (($r['code'] ?? 1) !== 0) $this->json(['ok' => false, 'error' => Builder::reason($r)], 500);
        $out = [];
        foreach (explode("\n", (string)$r['out']) as $l) {
            if (!preg_match('/^(\S+) (\d+)$/', trim($l), $m) || !preg_match(Builder::ASSET_RE, $m[1])) continue;
            $out[] = ['src' => '/builder/a/'.$site['id'].'/'.$m[1], 'name' => $m[1], 'size' => (int)$m[2]];
        }
        $this->json(['ok' => true, 'data' => $out]);
    }
    public function upload(): void {
        $u = $this->jgate();
        $site = $this->jsite($u);
        if ($b = Helpers::diskBlocked($u)) $this->json(['ok' => false, 'error' => $b], 403);
        $f = $_FILES['files'] ?? null;
        if (!is_array($f)) $this->json(['ok' => false, 'error' => 'No image received'], 400);
        // GrapesJS posts files[]; normalise single/multi
        $names = (array)$f['name']; $tmps = (array)$f['tmp_name']; $errs = (array)$f['error']; $sizes = (array)$f['size'];
        $done = [];
        foreach (array_keys($names) as $i) {
            if (count($done) >= 10) break;
            if (($errs[$i] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$tmps[$i])) $this->json(['ok' => false, 'error' => 'Upload failed (too large? limit '.round(self::uploadLimit() / 1048576, 1).' MB)'], 400);
            if ((int)$sizes[$i] > 20 * 1048576) $this->json(['ok' => false, 'error' => 'Image too large'], 413);
            $err = null;
            $img = Builder::reencode((string)$tmps[$i], (string)$names[$i], $err);
            if (!$img) $this->json(['ok' => false, 'error' => (string)$names[$i].': '.$err], 400);
            $r = Builder::run($site, $u, 'asset-put', $img['name'], $img['bytes']);
            if (($r['code'] ?? 1) !== 0) $this->json(['ok' => false, 'error' => Builder::reason($r)], 500);
            $done[] = ['src' => '/builder/a/'.$site['id'].'/'.$img['name'], 'name' => $img['name'], 'width' => $img['w'], 'height' => $img['h'], 'type' => 'image'];
        }
        $this->json(['ok' => true, 'data' => $done]);
    }
    public function assetDelete(): void {
        $u = $this->jgate();
        $site = $this->jsite($u);
        $n = (string)($_POST['name'] ?? '');
        if (!preg_match(Builder::ASSET_RE, $n)) $this->json(['ok' => false, 'error' => 'Bad image name'], 400);
        $r = Builder::run($site, $u, 'asset-del', $n);
        $this->json(['ok' => ($r['code'] ?? 1) === 0, 'error' => ($r['code'] ?? 1) === 0 ? null : Builder::reason($r)]);
    }
    // GET /builder/a/<site id>/<name> — canvas copy of an uploaded image
    public function asset(): void {
        Auth::requireLogin();
        $u = Auth::user();
        $path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        if (!preg_match('~^/builder/a/(\d{1,10})/([^/]+)$~', $path, $m) || !preg_match(Builder::ASSET_RE, $m[2])) self::notFound();
        $site = Builder::find((int)$m[1], $u);
        if (!$site) self::notFound();
        self::sendAsset($site, $u, $m[2], 'private, max-age=300');
    }
    private static function sendAsset(array $site, array $owner, string $name, string $cache): never {
        $mime = Builder::mime($name);
        $b64 = $mime ? Builder::read($site, $owner, 'asset:'.$name) : null;
        $bytes = is_string($b64) ? base64_decode(trim($b64), true) : false;
        if (!is_string($bytes)) self::notFound();
        header('Content-Type: '.$mime);
        header('Content-Length: '.strlen($bytes));
        header('Content-Security-Policy: '.self::RAW_CSP);
        header('Cache-Control: '.$cache);
        echo $bytes;
        exit;
    }
    private static function notFound(): never {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Not found\n";
        exit;
    }

    // ---- preview: the saved draft, rendered on request, behind an
    // expiring token; served sandboxed + noindex, never written to disk ----
    public function previewStart(): void {
        $u = $this->jgate();
        $site = $this->jsite($u);
        $t = bin2hex(random_bytes(16));
        Database::query("UPDATE builder_sites SET preview_token=?, preview_expires=DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id=?", [$t, $site['id']]);
        $this->json(['ok' => true, 'url' => "/builder/preview/$t/"]);
    }
    public function preview(): void {
        $path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        if (!preg_match('~^/builder/preview/([0-9a-f]{32})(?:/(.*))?$~', $path, $m)) self::notFound();
        if (!isset($m[2])) { header('Location: /builder/preview/'.$m[1].'/'); exit; }
        $row = Database::query("SELECT s.*, d.domain, u.username, u.id uid, u.role, u.owner_id FROM builder_sites s JOIN domains d ON d.id=s.domain_id AND d.user_id=s.user_id JOIN users u ON u.id=s.user_id WHERE s.preview_token=? AND s.preview_expires > NOW()", [$m[1]])->fetch();
        if (!$row) self::notFound();
        $owner = ['id' => (int)$row['uid'], 'username' => $row['username'], 'role' => $row['role'], 'owner_id' => $row['owner_id']];
        $rel = $m[2];
        if (preg_match('~^assets/kb/img/([a-z0-9][a-z0-9_-]{0,80}\.(?:webp|jpg|png|gif))$~', $rel, $a)) self::sendAsset($row, $owner, $a[1], 'private, max-age=60');
        $raw = Builder::read($row, $owner, 'draft.json');
        [$draft] = is_string($raw) ? Builder::checkDraft(json_decode($raw, true)) : [null];
        if ($draft === null) self::notFound();
        // Preview shows draft posts too (that is what the switch is for)
        foreach ($draft['pages'] as $i => $pg) $draft['pages'][$i]['draft'] = false;
        $base = "/builder/preview/{$m[1]}/";
        self::sendRendered(Builder::render($draft, $base, Builder::creditOn($owner)), $rel, $base, self::RAW_CSP, 'no-store');
    }
    // ---- template gallery: a shipped template rendered as a site, so the
    // New site picker can show it before anything is created ----
    public function template(): void {
        $u = $this->gate();
        $path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        if (!preg_match('~^/builder/template/([a-z0-9-]{1,32})(?:/(.*))?$~', $path, $m)) self::notFound();
        if (!isset($m[2])) { header('Location: /builder/template/'.$m[1].'/'); exit; }
        // the path comes from the shipped list, never from the request
        $dir = null;
        foreach ((array)glob(__DIR__.'/../Builder/templates/*/draft.json') as $f) {
            if (basename(dirname((string)$f)) === $m[1]) $dir = dirname((string)$f);
        }
        if ($dir === null) self::notFound();
        [$draft] = Builder::checkDraft(json_decode((string)file_get_contents("$dir/draft.json"), true));
        if ($draft === null) self::notFound();
        $base = "/builder/template/{$m[1]}/";
        // the picker frames it (index.php sends DENY for every panel page).
        // allow-same-origin: a bare sandbox's opaque origin makes the frame's
        // own requests (site.css, its links) cross-site, so the Lax session
        // cookie, which gate() needs on each, would be withheld. Still no
        // scripts (default-src 'none') or forms; the content is shipped + checked.
        header('X-Frame-Options: SAMEORIGIN');
        $csp = preg_replace('/^sandbox;/', 'sandbox allow-same-origin;', self::RAW_CSP)."; frame-ancestors 'self'";
        self::sendRendered(Builder::render($draft, $base, Builder::creditOn($u)), $m[2], $base, $csp, 'private, max-age=600');
    }
    // one file of a rendered site (preview, template); a folder without its
    // slash is redirected, anything unknown gets the site's 404 page
    private static function sendRendered(array $files, string $rel, string $base, string $csp, string $cache): never {
        $key = $rel === '' ? 'index.html' : (str_ends_with($rel, '/') ? $rel.'index.html' : $rel);
        if (!isset($files[$key]) && isset($files[$rel.'/index.html'])) { header('Location: '.$base.$rel.'/'); exit; }
        $code = 200;
        if (!isset($files[$key])) { $key = '404.html'; $code = 404; }
        http_response_code($code);
        header('Content-Type: '.(str_ends_with($key, '.css') ? 'text/css' : 'text/html').'; charset=utf-8');
        header('Content-Security-Policy: '.$csp);
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: '.$cache);
        echo $files[$key];
        exit;
    }

    // ---- revisions ----
    public function revisions(): void {
        $u = $this->jgate();
        $site = Builder::find((int)($_GET['id'] ?? 0), $u);
        if (!$site) $this->json(['ok' => false, 'error' => 'Site not found'], 404);
        $r = Builder::run($site, $u, 'revs');
        if (($r['code'] ?? 1) !== 0) $this->json(['ok' => false, 'error' => Builder::reason($r)], 500);
        $out = [];
        foreach (explode("\n", (string)$r['out']) as $n) {
            if (!preg_match('/^(\d{4})(\d\d)(\d\d)T(\d\d)(\d\d)(\d\d)-[0-9a-f]{4}-(save|publish)\.json\.gz$/', trim($n), $m)) continue;
            $out[] = ['name' => trim($n), 'when' => "$m[1]-$m[2]-$m[3] $m[4]:$m[5]:$m[6] UTC", 'kind' => $m[7]];
        }
        $this->json(['ok' => true, 'data' => $out]);
    }
    // returns the revision's project data; the editor loads it as the draft
    // (it goes live only after Publish)
    public function revision(): void {
        $u = $this->jgate();
        $site = Builder::find((int)($_GET['id'] ?? 0), $u);
        $n = (string)($_GET['name'] ?? '');
        if (!$site || !preg_match('/^[0-9]{8}T[0-9]{6}-[0-9a-f]{4}-(save|publish)\.json\.gz$/', $n)) $this->json(['ok' => false, 'error' => 'Revision not found'], 404);
        $err = null;
        $p = Builder::read($site, $u, 'rev:'.$n, $err);
        $project = is_string($p) ? json_decode($p, true) : null;
        if (!is_array($project)) $this->json(['ok' => false, 'error' => $err ?? 'Revision not found'], 404);
        $this->json(['ok' => true, 'project' => $project]);
    }
}
