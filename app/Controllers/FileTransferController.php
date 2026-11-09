<?php
declare(strict_types=1);
// FileTransferController — inherits the path helpers from FileManagerController.
class FileTransferController extends FileManagerController {
    public function unzip(): void {
        Auth::requireLogin();
        $rel = self::relOf($_POST['p'] ?? '');
        if (!Helpers::checkCsrf()) self::fail($rel, 'Bad CSRF');
        $u = Auth::user();
        $name = (string)($_POST['name'] ?? '');
        if (!FileManagerPath::name($name) || !preg_match(self::ARCH, $name)) self::fail($rel, 'Bad archive');
        if ($err = Quota::blocked($u)) self::fail($rel, $err);
        if (!$this->isSystem()) self::fail($rel, 'Extract needs a system account');
        $r = ServiceManager::run('file-unzip.sh', [$u['username'], $rel, $name, (string)Quota::leftBytes($u)], 300);
        Helpers::flash(($r['code'] ?? 1) === 0 ? 'ok' : 'err', ($r['code'] ?? 1) === 0 ? self::out($r) : 'Extract failed: '.self::out($r));
        self::back($rel);
    }
    // uploaded names: off-charset characters become '_' (spaces kept)
    private static function cleanName(string $fn): ?string {
        $fn = basename(str_replace('\\', '/', $fn));
        $fn = preg_replace('/[^a-zA-Z0-9_. ()+@,~-]/', '_', $fn) ?? '';
        $fn = rtrim(substr($fn, 0, 128));
        if ($fn !== '' && $fn[0] === '-') $fn = '_'.substr($fn, 1);
        return FileManagerPath::name($fn) ? $fn : null;
    }
    // place one staged file; returns [ok, message]
    private function place(string $rel, string $tmp, string $fn): array {
        $u = Auth::user();
        if (!$this->isSystem()) {
            $dest = FileManagerPath::resolve($this->base(), $rel, $fn);
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
        $dest = FileManagerPath::resolve($this->base(), $rel, '');
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- fmResolve() output: null-or-contained (lstat walk, symlink refusal, trailing-slash boundary)
        if ($dest === null || !is_dir($dest) || FileManagerPath::panelPath($rel)) self::fail('', 'Bad path');
        if ($err = Quota::blocked(Auth::user())) self::fail($rel, $err);
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
        if (!FileManagerPath::rel($rel) || FileManagerPath::panelPath($rel) || !preg_match('/^[a-f0-9]{16,32}$/', $id) || $fn === null) $j(false, 'Bad request');
        $dest = FileManagerPath::resolve($this->base(), $rel, '');
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- fmResolve() output: null-or-contained (lstat walk, symlink refusal, trailing-slash boundary)
        if ($dest === null || !is_dir($dest)) $j(false, 'Folder is gone');
        $left = Quota::leftBytes($u);
        if ($size < 0 || $size > 2147483648 || ($left > 0 && $size > $left)) $j(false, "$fn is bigger than your remaining disk space");
        if ($err = Quota::blocked($u)) $j(false, $err);
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
    public function zipSel(): void {
        Auth::requireLogin();
        $rel = self::relOf($_POST['p'] ?? '');
        if (!Helpers::checkCsrf()) self::fail($rel, 'Bad CSRF');
        $u = Auth::user();
        $names = $this->selNames();
        $arc = trim((string)($_POST['archive'] ?? ''));
        if ($names === [] || !FileManagerPath::name($arc) || !preg_match('/\.(zip|tar\.gz|tgz)$/i', $arc)) self::fail($rel, 'Select files + an archive name ending .zip or .tar.gz');
        if ($err = Quota::blocked($u)) self::fail($rel, $err);
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
            if (FileManagerPath::panelPath($rel, $name)) continue;
            $t = FileManagerPath::resolve($base, $rel, $name);
            // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- fmResolve() output: null-or-contained (lstat walk, symlink refusal, trailing-slash boundary)
            if ($t === null || !is_file($t)) continue;
            // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- fmResolve() output: null-or-contained (lstat walk, symlink refusal, trailing-slash boundary)
            $total += filesize($t);
            if ($total > 209715200) self::fail($rel, 'Selection over 200MB — download in parts');
            $files[$name] = $t;
        }
        if ($files === []) self::fail($rel, 'Nothing readable selected (folders: pack them first)');
        if (!class_exists('ZipArchive')) self::fail($rel, 'Zip support missing on this box');
        // panel worker reads via its ACL entries — stream straight out, nothing
        // kept; the zip is built in the panel's own storage, not the shared /tmp
        $zip = new ZipArchive();
        $tmp = tempnam(__DIR__.'/../../storage/tmp-uploads', 'fmz');
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) self::fail($rel, 'Zip failed');
        foreach ($files as $name => $t) $zip->addFile($t, $name);
        $zip->close();
        Helpers::log("filemanager {$u['username']} zipped ".count($files)." file(s) in $rel");
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="selected.zip"');
        header('Content-Length: '.filesize($tmp));
        readfile($tmp);
        // nosemgrep: php.lang.security.unlink-use.unlink-use -- server-generated temp file
        @unlink($tmp);
        exit;
    }
    // contained, readable regular file for download/preview, or bounce
    private function readable(string $rel, string $name): string {
        if (!FileManagerPath::name($name) || FileManagerPath::panelPath($rel, $name)) self::fail($rel, 'Bad name');
        $t = FileManagerPath::resolve($this->base(), $rel, $name);
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
