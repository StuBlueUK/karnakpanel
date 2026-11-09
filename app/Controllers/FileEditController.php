<?php
declare(strict_types=1);
// FileEditController — inherits the path helpers from FileManagerController.
class FileEditController extends FileManagerController {
    public function editForm(): void {
        Auth::requireLogin();
        $u = Auth::user();
        $rel = self::relOf($_GET['p'] ?? '');
        $name = (string)($_GET['name'] ?? '');
        if (!FileManagerPath::name($name) || FileManagerPath::panelPath($rel, $name)) self::fail($rel, 'Bad name');
        $content = null;
        if (!$this->isSystem()) {
            $t = FileManagerPath::resolve($this->base(), $rel, $name);
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
        if (!FileManagerPath::name($name) || strlen($content) > 1048576) self::fail($rel, 'Bad name/content');
        if ($err = Quota::blocked($u)) self::fail($rel, $err);
        if (!$this->isSystem()) {
            $t = FileManagerPath::resolve($this->base(), $rel, $name);
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
        if (!FileManagerPath::name($name) || FileManagerPath::panelPath($rel, $name)) self::fail($rel, 'Bad name');
        if (!$isDir && ($err = Quota::blocked($u))) self::fail($rel, $err);
        if (!$this->isSystem()) {
            $t = FileManagerPath::resolve($this->base(), $rel, $name);
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
    public function chmod(): void {
        Auth::requireLogin();
        $rel = self::relOf($_POST['p'] ?? '');
        if (!Helpers::checkCsrf()) self::fail($rel, 'Bad CSRF');
        $u = Auth::user();
        $name = (string)($_POST['name'] ?? '');
        $mode = (string)($_POST['mode'] ?? '');
        if (!FileManagerPath::name($name) || !preg_match('/^[0-7]{3}$/', $mode)) self::fail($rel, 'Bad name/mode (e.g. 644)');
        if (!$this->isSystem()) {
            $t = FileManagerPath::resolve($this->base(), $rel, $name);
            // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- fmResolve() output: null-or-contained (lstat walk, symlink refusal, trailing-slash boundary)
            $ok = $t !== null && $t !== $this->base() && @chmod($t, octdec($mode));
            Helpers::flash($ok ? 'ok' : 'err', $ok ? "Set $name to $mode" : 'chmod failed');
            self::back($rel);
        }
        $r = ServiceManager::run('file-chmod.sh', [$u['username'], $rel, $name, $mode]);
        Helpers::flash(($r['code'] ?? 1) === 0 ? 'ok' : 'err', ($r['code'] ?? 1) === 0 ? "Set $name to $mode" : 'chmod failed: '.self::out($r));
        self::back($rel);
    }
}
