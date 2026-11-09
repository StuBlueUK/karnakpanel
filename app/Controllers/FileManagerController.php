<?php
declare(strict_types=1);
// File manager. Names/paths are gated here (FileManagerPath::name/rel) AND in
// the root scripts (scripts/fm-common.sh); containment is
// FileManagerPath::resolve / fm_resolve (component walk, no symlinks). Writes on real system users go
// through ServiceManager::run(); recursive work there runs AS THE USER.
// Dev boxes without the system user fall back to direct PHP for the basics.
class FileManagerController extends Controller {
    protected const IMG = ['png','jpg','jpeg','gif','webp'];
    protected const ARCH = '/\.(zip|tar|tgz|tbz2|txz|tar\.gz|tar\.bz2|tar\.xz)$/i';

    protected function base(): string {
        $u = Auth::user();
        return Helpers::homedir($u['username']);
    }
    protected function isSystem(): bool {
        $u = Auth::user();
        return function_exists('posix_getpwnam') ? (bool)posix_getpwnam($u['username']) : is_dir("/home/{$u['username']}");
    }
    // relative dir from a request field; anything off-charset = home root
    protected static function relOf(?string $v): string {
        $rel = trim((string)$v, '/');
        return FileManagerPath::rel($rel) ? $rel : '';
    }
    protected static function back(string $rel): never {
        Helpers::redirect('/files'.($rel !== '' ? '?p='.urlencode($rel) : ''));
    }
    protected static function fail(string $rel, string $msg): never {
        Helpers::flash('err', $msg); self::back($rel);
    }
    protected static function out(array $r): string { return trim((string)($r['out'] ?? '')); }

    public function list(): void {
        Auth::requireLogin();
        $base = $this->base();
        $rel = self::relOf($_GET['p'] ?? '');
        // containment: fmResolve walks components and refuses symlinks, so a
        // symlinked dir can never redirect the listing into another home.
        $path = FileManagerPath::resolve($base, $rel, '');
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- fmResolve() output: null-or-contained (lstat walk, symlink refusal, trailing-slash boundary)
        if ($path === null || !is_dir($path) || FileManagerPath::panelPath($rel)) { $path = $base; $rel = ''; }
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
                if (!FileManagerPath::name($f[5]) || !FileManagerPath::rel($f[4])) continue;
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
    protected static function entry(string $full, string $n, string $rel): array {
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
            'ok' => FileManagerPath::name($n) && !FileManagerPath::panelPath($rel, $n),
            'img' => !$dir && !$link && in_array($ext, self::IMG, true),
            'arch' => !$dir && !$link && (bool)preg_match(self::ARCH, $n),
        ];
    }
    // recursive name search under the current folder: no symlink descent
    // (RecursiveDirectoryIterator default), panel dot paths skipped, capped
    // at 200 hits / 20k entries / depth 10 / 3 seconds.
    protected static function search(string $path, string $rel, string $q): array {
        $hits = []; $seen = 0; $t0 = microtime(true);
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                    function ($cur, $key, $iter) use ($path, $rel) {
                        $sub = ltrim(substr($cur->getPathname(), strlen($path)), '/');
                        return !FileManagerPath::panelPath($rel, $sub);
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
    protected static function uploadMax(): int {
        $b = function (string $k): int {
            $v = trim((string)ini_get($k));
            if ($v === '' || $v === '0' || $v === '-1') return PHP_INT_MAX;
            $n = (int)$v;
            return match (strtolower(substr($v, -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
        };
        return max(65536, min($b('upload_max_filesize'), $b('post_max_size') - 65536));
    }

    // Delete = move to trash; perm=1 (or the dev fallback) deletes for good

    protected function selNames(): array {
        $names = $_POST['sel'] ?? [];
        if (!is_array($names)) return [];
        $out = [];
        foreach ($names as $n) {
            $n = (string)$n;
            if (FileManagerPath::name($n)) $out[] = $n;
        }
        return array_values(array_unique(array_slice($out, 0, 100)));
    }


    protected static function stageDir(): string {
        $stage = __DIR__.'/../../storage/tmp-uploads';
        @mkdir($stage, 0700, true);
        return realpath($stage) ?: $stage;
    }

}
