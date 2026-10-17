<?php
declare(strict_types=1);
// File manager. Names/paths are gated here (Helpers::fmName/fmRel) AND in the
// root scripts (scripts/fm-common.sh); containment is Helpers::fmResolve /
// fm_resolve (component walk, no symlinks). Writes on real system users go
// through ServiceManager::run(); recursive work there runs AS THE USER.
// Dev boxes without the system user fall back to direct PHP for the basics.
class FileManagerController extends Controller {
    private const IMG = ['png','jpg','jpeg','gif','webp'];
    private const ARCH = '/\.(zip|tar|tgz|tbz2|txz|tar\.gz|tar\.bz2|tar\.xz)$/i';

    private function base(): string {
        $u = Auth::user();
        return Helpers::homedir($u['username']);
    }
    private function isSystem(): bool {
        $u = Auth::user();
        return function_exists('posix_getpwnam') ? (bool)posix_getpwnam($u['username']) : is_dir("/home/{$u['username']}");
    }
    // relative dir from a request field; anything off-charset = home root
    private static function relOf(?string $v): string {
        $rel = trim((string)$v, '/');
        return Helpers::fmRel($rel) ? $rel : '';
    }
    private static function back(string $rel): never {
        Helpers::redirect('/files'.($rel !== '' ? '?p='.urlencode($rel) : ''));
    }
    private static function fail(string $rel, string $msg): never {
        Helpers::flash('err', $msg); self::back($rel);
    }
    private static function out(array $r): string { return trim((string)($r['out'] ?? '')); }

    public function list(): void {
        Auth::requireLogin();
        $base = $this->base();
        $rel = self::relOf($_GET['p'] ?? '');
        // containment: fmResolve walks components and refuses symlinks, so a
        // symlinked dir can never redirect the listing into another home.
        $path = Helpers::fmResolve($base, $rel, '');
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- fmResolve() output: null-or-contained (lstat walk, symlink refusal, trailing-slash boundary)
        if ($path === null || !is_dir($path) || Helpers::fmPanelPath($rel)) { $path = $base; $rel = ''; }
        $sort = in_array($_GET['s'] ?? '', ['name','size','date'], true) ? $_GET['s'] : 'name';
        $order = ($_GET['o'] ?? '') === 'desc' ? 'desc' : 'asc';
        $q = trim((string)($_GET['q'] ?? ''));
        if (strlen($q) > 64 || preg_match('/[\x00-\x1f\x7f]/', $q)) $q = '';

        $items = [];
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $n) {
            $n = (string)$n;
            if ($rel === '' && $n === '.trash') continue; // shown as the Trash view
            $items[] = self::entry($path.'/'.$n, $n, $rel);
        }
        usort($items, function ($a, $b) use ($sort, $order) {
            if ($a['dir'] !== $b['dir']) return $a['dir'] ? -1 : 1; // folders first
            $c = match ($sort) {
                'size' => $a['size'] <=> $b['size'],
                'date' => $a['mtime'] <=> $b['mtime'],
                default => strnatcasecmp($a['name'], $b['name']),
            };
            return $order === 'desc' ? -$c : $c;
        });
        $found = $q !== '' ? self::search($path, $rel, $q) : null;

        $crumbs = [];
        $acc = '';
        foreach ($rel === '' ? [] : explode('/', $rel) as $part) {
            $acc = ltrim($acc.'/'.$part, '/');
            $crumbs[] = ['name' => $part, 'rel' => $acc];
        }
        $trash = null;
        if (!empty($_GET['trash']) && $this->isSystem()) {
            $trash = [];
            $r = ServiceManager::run('file-trashctl.sh', [Auth::user()['username'], 'list'], 60);
            foreach (explode("\n", self::out($r)) as $line) {
                $f = explode("\t", $line);
                if (count($f) !== 6 || !preg_match('/^[0-9]{9,11}-[0-9a-f]{8}$/', $f[0])) continue;
                if (!Helpers::fmName($f[5]) || !Helpers::fmRel($f[4])) continue;
                $trash[] = ['id' => $f[0], 'at' => (int)$f[1], 'size' => (int)$f[2], 'dir' => $f[3] === 'd', 'rel' => $f[4], 'name' => $f[5]];
            }
        }
        $this->view('files', [
            'items' => $items, 'path' => $path, 'rel' => $rel, 'crumbs' => $crumbs,
            'sort' => $sort, 'order' => $order, 'q' => $q, 'found' => $found, 'trash' => $trash,
            'upMax' => self::uploadMax(), 'sys' => $this->isSystem(),
        ]);
    }
    // one listing row (lstat: symlinks show as links, never followed)
    private static function entry(string $full, string $n, string $rel): array {
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- path = fmResolve()-contained dir + scandir() entry
        $st = @lstat($full) ?: ['mode' => 0, 'size' => 0, 'mtime' => 0, 'uid' => -1];
        $link = (($st['mode'] & 0170000) === 0120000);
        $dir = !$link && (($st['mode'] & 0170000) === 0040000);
        $owner = (string)$st['uid'];
        if (function_exists('posix_getpwuid') && $st['uid'] >= 0) {
            $pw = @posix_getpwuid((int)$st['uid']);
            if ($pw) $owner = $pw['name'];
        }
        $ext = strtolower(pathinfo($n, PATHINFO_EXTENSION));
        return [
            'name' => $n, 'dir' => $dir, 'link' => $link,
            'size' => $dir ? 0 : (int)$st['size'], 'mtime' => (int)$st['mtime'],
            'perms' => substr(sprintf('%o', $st['mode']), -3), 'owner' => $owner,
            'ok' => Helpers::fmName($n) && !Helpers::fmPanelPath($rel, $n),
            'img' => !$dir && !$link && in_array($ext, self::IMG, true),
            'arch' => !$dir && !$link && (bool)preg_match(self::ARCH, $n),
        ];
    }
    // recursive name search under the current folder: no symlink descent
    // (RecursiveDirectoryIterator default), panel dot paths skipped, capped
    // at 200 hits / 20k entries / depth 10 / 3 seconds.
    private static function search(string $path, string $rel, string $q): array {
        $hits = []; $seen = 0; $t0 = microtime(true);
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                    function ($cur, $key, $iter) use ($path, $rel) {
                        $sub = ltrim(substr($cur->getPathname(), strlen($path)), '/');
                        return !Helpers::fmPanelPath($rel, $sub);
                    }
                ),
                RecursiveIteratorIterator::SELF_FIRST,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            $it->setMaxDepth(10);
            foreach ($it as $f) {
                if (++$seen > 20000 || count($hits) >= 200 || microtime(true) - $t0 > 3) break;
                $n = $f->getFilename();
                if (stripos($n, $q) === false) continue;
                $sub = ltrim(substr($f->getPath(), strlen($path)), '/');
                $hits[] = ['name' => $n, 'rel' => ltrim($rel.'/'.$sub, '/'), 'dir' => $f->isDir() && !$f->isLink()];
            }
        } catch (Throwable $e) {}
        return $hits;
    }
    // bytes one request may carry (the smaller of upload/post limits) — the
    // browser chunks anything bigger
    private static function uploadMax(): int {
        $b = function (string $k): int {
            $v = trim((string)ini_get($k));
            if ($v === '' || $v === '0' || $v === '-1') return PHP_INT_MAX;
            $n = (int)$v;
            return match (strtolower(substr($v, -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
        };
        return max(65536, min($b('upload_max_filesize'), $b('post_max_size') - 65536));
    }

    // Delete = move to trash; perm=1 (or the dev fallback) deletes for good
    public function delete(): void {
        Auth::requireLogin();
        $rel = self::relOf($_POST['p'] ?? '');
        if (!Helpers::checkCsrf()) self::fail($rel, 'Bad CSRF');
        $name = (string)($_POST['name'] ?? '');
        if (!Helpers::fmName($name)) self::fail($rel, 'Bad name');
        $this->remove($rel, [$name], !empty($_POST['perm']));
    }
    public function bulkDelete(): void {
        Auth::requireLogin();
        $rel = self::relOf($_POST['p'] ?? '');
        if (!Helpers::checkCsrf()) self::fail($rel, 'Bad CSRF');
        $names = $this->selNames();
        if ($names === []) self::fail($rel, 'Nothing selected');
        $this->remove($rel, $names, !empty($_POST['perm']));
    }
    private function remove(string $rel, array $names, bool $perm): never {
        $u = Auth::user();
        $base = $this->base();
        if (!$this->isSystem()) {
            $n = 0;
            foreach ($names as $name) {
                $t = Helpers::fmResolve($base, $rel, $name);
                if ($t === null || $t === $base) continue;
                // nosemgrep: php.lang.security.unlink-use.unlink-use, php.lang.security.injection.tainted-filename.tainted-filename -- target from Helpers::fmResolve (lstat walk, no symlinks, trailing-slash boundary)
                if (is_dir($t) ? @rmdir($t) : @unlink($t)) $n++;
            }
            Helpers::flash($n === count($names) ? 'ok' : 'err', "Deleted $n of ".count($names));
            self::back($rel);
        }
        if (!$perm) {
            $r = ServiceManager::run('file-trash.sh', array_merge([$u['username'], $rel], $names), 120);
            Helpers::flash(($r['code'] ?? 1) === 0 ? 'ok' : 'err', self::out($r) ?: 'Move to trash failed');
            self::back($rel);
        }
        $n = 0; $err = '';
        foreach ($names as $name) {
            $r = ServiceManager::run('file-del.sh', [$u['username'], $rel, $name], 120);
            if (($r['code'] ?? 1) === 0) $n++; else $err = self::out($r);
        }
        Helpers::flash($err === '' ? 'ok' : 'err', "Deleted $n item(s) permanently".($err !== '' ? " — $err" : ''));
        self::back($rel);
    }
    // POST /files/trash: act = restore|purge (id) or empty
    public function trashAction(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err', 'Bad CSRF'); Helpers::redirect('/files?trash=1'); }
        $act = (string)($_POST['act'] ?? '');
        $id = (string)($_POST['id'] ?? '');
        if (!in_array($act, ['restore', 'purge', 'empty'], true)
            || ($act !== 'empty' && !preg_match('/^[0-9]{9,11}-[0-9a-f]{8}$/', $id))) {
            Helpers::flash('err', 'Bad request'); Helpers::redirect('/files?trash=1');
        }
        $args = [Auth::user()['username'], $act];
        if ($act !== 'empty') $args[] = $id;
        $r = ServiceManager::run('file-trashctl.sh', $args, 120);
        Helpers::flash(($r['code'] ?? 1) === 0 ? 'ok' : 'err', self::out($r) ?: 'Trash action failed');
        Helpers::redirect('/files?trash=1');
    }
    // POST /files/paste: mode copy|move, src (source dir), p (destination), sel[]
    public function paste(): void {
        Auth::requireLogin();
        $rel = self::relOf($_POST['p'] ?? '');
        if (!Helpers::checkCsrf()) self::fail($rel, 'Bad CSRF');
        $u = Auth::user();
        $mode = ($_POST['mode'] ?? '') === 'move' ? 'move' : 'copy';
        $src = trim((string)($_POST['src'] ?? ''), '/');
        $names = $this->selNames();
        if (!Helpers::fmRel($src) || $names === []) self::fail($rel, 'Nothing to paste');
        if (!$this->isSystem()) self::fail($rel, 'Copy/move needs a system account');
        if ($mode === 'copy' && ($err = Helpers::diskBlocked($u))) self::fail($rel, $err);
        $r = ServiceManager::run('file-paste.sh', array_merge(
            [$u['username'], $mode, $src, $rel, (string)Helpers::diskLeftBytes($u)], $names), 300);
        Helpers::flash(($r['code'] ?? 1) === 0 ? 'ok' : 'err', self::out($r) ?: 'Paste failed');
        self::back($rel);
    }
    public function editForm(): void {
        Auth::requireLogin();
        $u = Auth::user();
        $rel = self::relOf($_GET['p'] ?? '');
        $name = (string)($_GET['name'] ?? '');
        if (!Helpers::fmName($name) || Helpers::fmPanelPath($rel, $name)) self::fail($rel, 'Bad name');
        $content = null;
        if (!$this->isSystem()) {
            $t = Helpers::fmResolve($this->base(), $rel, $name);
            // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- fmResolve() output: null-or-contained (lstat walk, symlink refusal, trailing-slash boundary)
            if ($t === null || !is_file($t) || filesize($t) > 524288) self::fail($rel, 'Cannot open (missing/too big)');
            // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- fmResolve() output: null-or-contained (lstat walk, symlink refusal, trailing-slash boundary)
            $content = file_get_contents($t);
        } else {
            $r = ServiceManager::run('file-get.sh', [$u['username'], $rel, $name]);
            if (($r['code'] ?? 1) !== 0) self::fail($rel, 'Cannot open: '.self::out($r));
            $content = base64_decode($r['out'], true); // file-get.sh: byte-exact base64
            if ($content === false) self::fail($rel, 'Cannot open (bad read)');
        }
        // EOL round-trip: form submission turns every textarea newline into
        // CRLF, so remember the file's own style and re-apply it on save (an
        // LF shell script saved as CRLF breaks under cron: "bash\r")
        $content = (string)$content;
        $eol = substr_count($content, "\r\n") * 2 > substr_count($content, "\n") ? 'crlf' : 'lf'; // majority
        // visual (WYSIWYG) mode: plain .html/.htm only — templated/server-side
        // markup would be mangled by a DOM round-trip
        $visual = (bool)preg_match('/\.html?$/i', $name)
            && !preg_match('/<\?|<!--#|\{\{|\{%/', $content);
        // relative URLs in visual mode resolve against the live site (preview
        // only — the injected <base> is stripped before the source is rebuilt)
        $siteBase = '';
        if (preg_match('#^([a-z0-9.-]+\.[a-z]{2,})/public_html(?:/(.+))?$#', $rel, $m)) {
            $siteBase = 'https://'.$m[1].'/'.(isset($m[2])
                ? implode('/', array_map('rawurlencode', explode('/', $m[2]))).'/' : '');
        }
        $this->view('file_edit', ['rel' => $rel, 'name' => $name, 'src' => $content,
            'eol' => $eol, 'visual' => $visual, 'siteBase' => $siteBase]);
    }
    public function editSave(): void {
        Auth::requireLogin();
        $rel = self::relOf($_POST['p'] ?? '');
        if (!Helpers::checkCsrf()) self::fail($rel, 'Bad CSRF');
        $u = Auth::user();
        $name = (string)($_POST['name'] ?? '');
        $content = (string)($_POST['content'] ?? '');
        $content = str_replace("\r\n", "\n", $content);
        if (($_POST['eol'] ?? '') === 'crlf') $content = str_replace("\n", "\r\n", $content);
        if (!Helpers::fmName($name) || strlen($content) > 1048576) self::fail($rel, 'Bad name/content');
        if ($err = Helpers::diskBlocked($u)) self::fail($rel, $err);
        if (!$this->isSystem()) {
            $t = Helpers::fmResolve($this->base(), $rel, $name);
            // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- fmResolve() output: null-or-contained (lstat walk, symlink refusal, trailing-slash boundary)
            if ($t === null || !is_file($t)) Helpers::flash('err', 'Bad path');
            // nosemgrep: php.lang.security.tainted-url-to-connection.tainted-url-to-connection, php.lang.security.injection.tainted-filename.tainted-filename -- local write to fmResolve-contained path (lstat walk, no symlinks, boundary), not an HTTP request
            elseif (file_put_contents($t, $content) === false) Helpers::flash('err', 'Save failed');
            else Helpers::flash('ok', "Saved $name");
            self::back($rel);
        }
        $tmp = self::stageDir().'/'.bin2hex(random_bytes(8)).'.up';
        if (@file_put_contents($tmp, $content) === false) self::fail($rel, 'Staging failed');
        $r = ServiceManager::run('file-put.sh', [$u['username'], $rel, $tmp, $name], 120);
        // nosemgrep: php.lang.security.unlink-use.unlink-use -- random-hex stage path, not user input
        @unlink($tmp);
        Helpers::flash(($r['code'] ?? 1) === 0 ? 'ok' : 'err', ($r['code'] ?? 1) === 0 ? "Saved $name" : 'Save failed: '.self::out($r));
        self::back($rel);
    }
    public function mkDir(): void { $this->create('file-mkdir.sh'); }
    public function newFile(): void { $this->create('file-new.sh'); }
    private function create(string $script): never {
        Auth::requireLogin();
        $rel = self::relOf($_POST['p'] ?? '');
        if (!Helpers::checkCsrf()) self::fail($rel, 'Bad CSRF');
        $u = Auth::user();
        $name = trim((string)($_POST['name'] ?? ''));
        $isDir = $script === 'file-mkdir.sh';
        if (!Helpers::fmName($name) || Helpers::fmPanelPath($rel, $name)) self::fail($rel, 'Bad name');
        if (!$isDir && ($err = Helpers::diskBlocked($u))) self::fail($rel, $err);
        if (!$this->isSystem()) {
            $t = Helpers::fmResolve($this->base(), $rel, $name);
            // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename, php.lang.security.tainted-url-to-connection.tainted-url-to-connection -- fmResolve() output: null-or-contained (lstat walk, symlink refusal, trailing-slash boundary)
            $ok = $t !== null && !file_exists($t) && ($isDir ? @mkdir($t, 0755) : @file_put_contents($t, '') !== false);
            Helpers::flash($ok ? 'ok' : 'err', $ok ? "Created $name" : 'Create failed (exists?)');
            self::back($rel);
        }
        $r = ServiceManager::run($script, [$u['username'], $rel, $name]);
        // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string -- not SQL: shell-result flash text
        Helpers::flash(($r['code'] ?? 1) === 0 ? 'ok' : 'err', ($r['code'] ?? 1) === 0 ? "Created $name" : 'Create failed: '.self::out($r));
        self::back($rel);
    }
    // POST /files/unzip — extract .zip / .tar(.gz/.bz2/.xz) in place
    public function unzip(): void {
        Auth::requireLogin();
        $rel = self::relOf($_POST['p'] ?? '');
        if (!Helpers::checkCsrf()) self::fail($rel, 'Bad CSRF');
        $u = Auth::user();
        $name = (string)($_POST['name'] ?? '');
        if (!Helpers::fmName($name) || !preg_match(self::ARCH, $name)) self::fail($rel, 'Bad archive');
        if ($err = Helpers::diskBlocked($u)) self::fail($rel, $err);
        if (!$this->isSystem()) self::fail($rel, 'Extract needs a system account');
        $r = ServiceManager::run('file-unzip.sh', [$u['username'], $rel, $name, (string)Helpers::diskLeftBytes($u)], 300);
        Helpers::flash(($r['code'] ?? 1) === 0 ? 'ok' : 'err', ($r['code'] ?? 1) === 0 ? self::out($r) : 'Extract failed: '.self::out($r));
        self::back($rel);
    }
    public function chmod(): void {
        Auth::requireLogin();
        $rel = self::relOf($_POST['p'] ?? '');
        if (!Helpers::checkCsrf()) self::fail($rel, 'Bad CSRF');
        $u = Auth::user();
        $name = (string)($_POST['name'] ?? '');
        $mode = (string)($_POST['mode'] ?? '');
        if (!Helpers::fmName($name) || !preg_match('/^[0-7]{3}$/', $mode)) self::fail($rel, 'Bad name/mode (e.g. 644)');
        if (!$this->isSystem()) {
            $t = Helpers::fmResolve($this->base(), $rel, $name);
            // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- fmResolve() output: null-or-contained (lstat walk, symlink refusal, trailing-slash boundary)
            $ok = $t !== null && $t !== $this->base() && @chmod($t, octdec($mode));
            Helpers::flash($ok ? 'ok' : 'err', $ok ? "Set $name to $mode" : 'chmod failed');
            self::back($rel);
        }
        $r = ServiceManager::run('file-chmod.sh', [$u['username'], $rel, $name, $mode]);
        Helpers::flash(($r['code'] ?? 1) === 0 ? 'ok' : 'err', ($r['code'] ?? 1) === 0 ? "Set $name to $mode" : 'chmod failed: '.self::out($r));
        self::back($rel);
    }
    private static function stageDir(): string {
        $stage = __DIR__.'/../../storage/tmp-uploads';
        @mkdir($stage, 0700, true);
        return realpath($stage) ?: $stage;
    }
    // uploaded names: off-charset characters become '_' (spaces kept)
    private static function cleanName(string $fn): ?string {
        $fn = basename(str_replace('\\', '/', $fn));
        $fn = preg_replace('/[^a-zA-Z0-9_. ()+@,~-]/', '_', $fn) ?? '';
        $fn = rtrim(substr($fn, 0, 128));
        if ($fn !== '' && $fn[0] === '-') $fn = '_'.substr($fn, 1);
        return Helpers::fmName($fn) ? $fn : null;
    }
    // place one staged file; returns [ok, message]
    private function place(string $rel, string $tmp, string $fn): array {
        $u = Auth::user();
        if (!$this->isSystem()) {
            $dest = Helpers::fmResolve($this->base(), $rel, $fn);
            // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- fmResolve()-contained destination, charset-gated filename
            $ok = $dest !== null && @rename($tmp, $dest);
            // nosemgrep: php.lang.security.unlink-use.unlink-use -- random-hex stage path, not user input
            if (!$ok) @unlink($tmp);
            return [$ok, $ok ? "Uploaded $fn" : 'Upload failed'];
        }
        $r = ServiceManager::run('file-put.sh', [$u['username'], $rel, $tmp, $fn], 300);
        // nosemgrep: php.lang.security.unlink-use.unlink-use -- random-hex stage path, not user input
        @unlink($tmp); // script moved it on success; drop stage remnant on failure
        return (($r['code'] ?? 1) === 0) ? [true, "Uploaded $fn"] : [false, "$fn: ".self::out($r)];
    }
    // POST /files/upload — classic form (no-JS fallback), one or many files
    public function upload(): void {
        Auth::requireLogin();
        $rel = self::relOf($_POST['p'] ?? '');
        if (!Helpers::checkCsrf()) self::fail($rel, 'Bad CSRF');
        $dest = Helpers::fmResolve($this->base(), $rel, '');
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- fmResolve() output: null-or-contained (lstat walk, symlink refusal, trailing-slash boundary)
        if ($dest === null || !is_dir($dest) || Helpers::fmPanelPath($rel)) self::fail('', 'Bad path');
        if ($err = Helpers::diskBlocked(Auth::user())) self::fail($rel, $err);
        $f = $_FILES['f'] ?? null;
        if (!is_array($f) || empty($f['tmp_name'])) self::fail($rel, 'No file (too big? the limit is '.Helpers::fmtMb(self::uploadMax() / 1048576).' per request)');
        $tmpNames = (array)$f['tmp_name']; $names = (array)$f['name'];
        $ok = 0; $errs = [];
        foreach (array_slice($tmpNames, 0, 20, true) as $i => $tn) {
            $fn = self::cleanName((string)($names[$i] ?? ''));
            if ($fn === null || $tn === '') { $errs[] = 'bad file name'; continue; }
            $tmp = self::stageDir().'/'.bin2hex(random_bytes(8)).'.up';
            if (!@move_uploaded_file((string)$tn, $tmp)) { $errs[] = "$fn: staging failed"; continue; }
            [$good, $msg] = $this->place($rel, $tmp, $fn);
            $good ? $ok++ : $errs[] = $msg;
        }
        Helpers::flash($errs ? 'err' : 'ok', "Uploaded $ok file(s)".($errs ? ' — '.implode('; ', $errs) : ''));
        self::back($rel);
    }
    // POST /files/upload-chunk (XHR, JSON): id, name, size, offset, chunk c.
    // Parts live in the panel stage dir, keyed by sha256(user id|client id)
    // so no other account can append to them; offset must equal the part's
    // current size (no gaps/overwrites). The last chunk hands the whole file
    // to file-put.sh (antivirus, containment) like a normal upload.
    public function uploadChunk(): void {
        Auth::requireLogin();
        header('Content-Type: application/json');
        $j = function (bool $ok, string $msg, bool $done = false): never {
            echo json_encode(['ok' => $ok, 'msg' => $msg, 'done' => $done]); exit;
        };
        if (!Helpers::checkCsrf()) $j(false, 'Session expired — reload the page');
        $u = Auth::user();
        $rel = trim((string)($_POST['p'] ?? ''), '/');
        $id = (string)($_POST['id'] ?? '');
        $size = (int)($_POST['size'] ?? -1);
        $off = (int)($_POST['offset'] ?? -1);
        $fn = self::cleanName((string)($_POST['name'] ?? ''));
        if (!Helpers::fmRel($rel) || Helpers::fmPanelPath($rel) || !preg_match('/^[a-f0-9]{16,32}$/', $id) || $fn === null) $j(false, 'Bad request');
        $dest = Helpers::fmResolve($this->base(), $rel, '');
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- fmResolve() output: null-or-contained (lstat walk, symlink refusal, trailing-slash boundary)
        if ($dest === null || !is_dir($dest)) $j(false, 'Folder is gone');
        $left = Helpers::diskLeftBytes($u);
        if ($size < 0 || $size > 2147483648 || ($left > 0 && $size > $left)) $j(false, "$fn is bigger than your remaining disk space");
        if ($err = Helpers::diskBlocked($u)) $j(false, $err);
        $stage = self::stageDir();
        foreach (glob($stage.'/ch-*.part') ?: [] as $old) {
            // nosemgrep: php.lang.security.unlink-use.unlink-use -- glob of the panel stage dir, fixed prefix
            if (@filemtime($old) < time() - 3600) @unlink($old);
        }
        $part = $stage.'/ch-'.hash('sha256', $u['id'].'|'.$id).'.part';
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename, php.lang.security.unlink-use.unlink-use -- stage part path: fixed panel dir + sha256 hex, not user input
        clearstatcache(true, $part);
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename, php.lang.security.unlink-use.unlink-use -- stage part path: fixed panel dir + sha256 hex, not user input
        $have = is_file($part) ? (int)filesize($part) : 0;
        if ($off !== $have) $j(false, 'Upload out of sync — try again');
        $c = $_FILES['c'] ?? null;
        if (!is_array($c) || !is_string($c['tmp_name'] ?? null) || !is_uploaded_file($c['tmp_name'])) $j(false, 'Chunk missing (limit '.self::uploadMax().' bytes)');
        $len = (int)filesize($c['tmp_name']);
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename, php.lang.security.unlink-use.unlink-use -- stage part path: fixed panel dir + sha256 hex, not user input
        if ($have + $len > $size) { @unlink($part); $j(false, 'Too much data'); }
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename, php.lang.security.unlink-use.unlink-use -- stage part path: fixed panel dir + sha256 hex, not user input
        $in = fopen($c['tmp_name'], 'rb'); $outF = fopen($part, 'ab');
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename, php.lang.security.unlink-use.unlink-use -- stage part path: fixed panel dir + sha256 hex, not user input
        if (!$in || !$outF || stream_copy_to_stream($in, $outF) !== $len) { @unlink($part); $j(false, 'Staging failed'); }
        fclose($in); fclose($outF);
        if ($have + $len < $size) $j(true, 'chunk');
        $tmp = $stage.'/'.bin2hex(random_bytes(8)).'.up';
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename, php.lang.security.unlink-use.unlink-use -- stage part path: fixed panel dir + sha256 hex, not user input
        if (!@rename($part, $tmp)) { @unlink($part); $j(false, 'Staging failed'); }
        [$good, $msg] = $this->place($rel, $tmp, $fn);
        $j($good, $msg, true);
    }
    public function rename(): void {
        Auth::requireLogin();
        $rel = self::relOf($_POST['p'] ?? '');
        if (!Helpers::checkCsrf()) self::fail($rel, 'Bad CSRF');
        $u = Auth::user();
        $old = (string)($_POST['name'] ?? '');
        $new = trim((string)($_POST['newname'] ?? ''));
        if (!Helpers::fmName($old) || !Helpers::fmName($new) || $old === $new || Helpers::fmPanelPath($rel, $new)) self::fail($rel, 'Bad name');
        if (!$this->isSystem()) {
            $s = Helpers::fmResolve($this->base(), $rel, $old);
            $d = Helpers::fmResolve($this->base(), $rel, $new);
            // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- fmResolve() output: null-or-contained (lstat walk, symlink refusal, trailing-slash boundary)
            $ok = $s !== null && $d !== null && file_exists($s) && !file_exists($d) && @rename($s, $d);
            Helpers::flash($ok ? 'ok' : 'err', $ok ? "Renamed $old to $new" : 'Rename failed');
            self::back($rel);
        }
        $r = ServiceManager::run('file-rename.sh', [$u['username'], $rel, $old, $new]);
        Helpers::flash(($r['code'] ?? 1) === 0 ? 'ok' : 'err', ($r['code'] ?? 1) === 0 ? "Renamed $old to $new" : 'Rename failed: '.self::out($r));
        self::back($rel);
    }
    private function selNames(): array {
        $names = $_POST['sel'] ?? [];
        if (!is_array($names)) return [];
        $out = [];
        foreach ($names as $n) {
            $n = (string)$n;
            if (Helpers::fmName($n)) $out[] = $n;
        }
        return array_values(array_unique(array_slice($out, 0, 100)));
    }
    // POST /files/zip — pack the selection: archive name picks .zip or .tar.gz
    public function zipSel(): void {
        Auth::requireLogin();
        $rel = self::relOf($_POST['p'] ?? '');
        if (!Helpers::checkCsrf()) self::fail($rel, 'Bad CSRF');
        $u = Auth::user();
        $names = $this->selNames();
        $arc = trim((string)($_POST['archive'] ?? ''));
        if ($names === [] || !Helpers::fmName($arc) || !preg_match('/\.(zip|tar\.gz|tgz)$/i', $arc)) self::fail($rel, 'Select files + an archive name ending .zip or .tar.gz');
        if ($err = Helpers::diskBlocked($u)) self::fail($rel, $err);
        if (!$this->isSystem()) self::fail($rel, 'Packing needs a system account');
        $r = ServiceManager::run('file-zip.sh', array_merge([$u['username'], $rel, $arc], $names), 300);
        Helpers::flash(($r['code'] ?? 1) === 0 ? 'ok' : 'err', ($r['code'] ?? 1) === 0 ? self::out($r) : 'Pack failed: '.self::out($r));
        self::back($rel);
    }
    public function downloadZip(): void {
        Auth::requireLogin();
        $rel = self::relOf($_POST['p'] ?? '');
        if (!Helpers::checkCsrf()) self::fail($rel, 'Bad CSRF');
        $u = Auth::user();
        $names = $this->selNames();
        if ($names === []) self::fail($rel, 'Nothing selected');
        $base = $this->base();
        $files = [];
        $total = 0;
        foreach ($names as $name) {
            if (Helpers::fmPanelPath($rel, $name)) continue;
            $t = Helpers::fmResolve($base, $rel, $name);
            // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- fmResolve() output: null-or-contained (lstat walk, symlink refusal, trailing-slash boundary)
            if ($t === null || !is_file($t)) continue;
            // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- fmResolve() output: null-or-contained (lstat walk, symlink refusal, trailing-slash boundary)
            $total += filesize($t);
            if ($total > 209715200) self::fail($rel, 'Selection over 200MB — download in parts');
            $files[$name] = $t;
        }
        if ($files === []) self::fail($rel, 'Nothing readable selected (folders: pack them first)');
        if (!class_exists('ZipArchive')) self::fail($rel, 'Zip support missing on this box');
        // panel worker reads via the u:nobody:rX ACLs — stream straight out, nothing stored
        $zip = new ZipArchive();
        $tmp = tempnam(sys_get_temp_dir(), 'fmz');
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) self::fail($rel, 'Zip failed');
        foreach ($files as $name => $t) $zip->addFile($t, $name);
        $zip->close();
        Helpers::log("filemanager {$u['username']} zipped ".count($files)." file(s) in $rel");
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="selected.zip"');
        header('Content-Length: '.filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    }
    // contained, readable regular file for download/preview, or bounce
    private function readable(string $rel, string $name): string {
        if (!Helpers::fmName($name) || Helpers::fmPanelPath($rel, $name)) self::fail($rel, 'Bad name');
        $t = Helpers::fmResolve($this->base(), $rel, $name);
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- fmResolve() output: null-or-contained (lstat walk, symlink refusal, trailing-slash boundary)
        if ($t === null || !is_file($t) || !is_readable($t)) self::fail($rel, 'Cannot fetch (not readable by the panel)');
        return $t;
    }
    public function download(): void {
        Auth::requireLogin();
        $rel = self::relOf($_GET['p'] ?? '');
        $name = (string)($_GET['name'] ?? '');
        $t = $this->readable($rel, $name);
        // panel worker reads via the u:nobody:rX ACLs — no sudo needed
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.str_replace(['"', '\\'], '', $name).'"');
        header('X-Content-Type-Options: nosniff');
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- fmResolve-contained download source (attachment + nosniff)
        header('Content-Length: '.filesize($t));
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- fmResolve-contained download source (attachment + nosniff)
        readfile($t);
        exit;
    }
    // GET /files/preview — raster images inline (png/jpg/gif/webp, verified by
    // getimagesize). Never SVG/HTML: those would run script on the panel
    // origin (admins open user files via login-as). Sandbox CSP + nosniff.
    public function preview(): void {
        Auth::requireLogin();
        $rel = self::relOf($_GET['p'] ?? '');
        $name = (string)($_GET['name'] ?? '');
        if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::IMG, true)) self::fail($rel, 'Not a previewable image');
        $t = $this->readable($rel, $name);
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- fmResolve-contained preview source
        if (filesize($t) > 20971520) self::fail($rel, 'Image over 20MB — download it instead');
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- fmResolve-contained preview source
        $info = @getimagesize($t);
        $mime = is_array($info) ? (string)($info['mime'] ?? '') : '';
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) self::fail($rel, 'Not a valid image');
        header('Content-Type: '.$mime);
        header('Content-Disposition: inline; filename="'.str_replace(['"', '\\'], '', $name).'"');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self'");
        header('Cache-Control: private, no-store');
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- fmResolve-contained preview source (verified raster type, nosniff, sandbox CSP)
        header('Content-Length: '.filesize($t));
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- fmResolve-contained preview source (verified raster type, nosniff, sandbox CSP)
        readfile($t);
        exit;
    }
}
