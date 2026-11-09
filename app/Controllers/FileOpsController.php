<?php
declare(strict_types=1);
// FileOpsController — inherits the path helpers from FileManagerController.
class FileOpsController extends FileManagerController {
    public function delete(): void {
        Auth::requireLogin();
        $rel = self::relOf($_POST['p'] ?? '');
        if (!Helpers::checkCsrf()) self::fail($rel, 'Bad CSRF');
        $name = (string)($_POST['name'] ?? '');
        if (!FileManagerPath::name($name)) self::fail($rel, 'Bad name');
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
                $t = FileManagerPath::resolve($base, $rel, $name);
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
        if (!FileManagerPath::rel($src) || $names === []) self::fail($rel, 'Nothing to paste');
        if (!$this->isSystem()) self::fail($rel, 'Copy/move needs a system account');
        if ($mode === 'copy' && ($err = Quota::blocked($u))) self::fail($rel, $err);
        $r = ServiceManager::run('file-paste.sh', array_merge(
            [$u['username'], $mode, $src, $rel, (string)Quota::leftBytes($u)], $names), 300);
        Helpers::flash(($r['code'] ?? 1) === 0 ? 'ok' : 'err', self::out($r) ?: 'Paste failed');
        self::back($rel);
    }
    public function rename(): void {
        Auth::requireLogin();
        $rel = self::relOf($_POST['p'] ?? '');
        if (!Helpers::checkCsrf()) self::fail($rel, 'Bad CSRF');
        $u = Auth::user();
        $old = (string)($_POST['name'] ?? '');
        $new = trim((string)($_POST['newname'] ?? ''));
        if (!FileManagerPath::name($old) || !FileManagerPath::name($new) || $old === $new || FileManagerPath::panelPath($rel, $new)) self::fail($rel, 'Bad name');
        if (!$this->isSystem()) {
            $s = FileManagerPath::resolve($this->base(), $rel, $old);
            $d = FileManagerPath::resolve($this->base(), $rel, $new);
            // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- fmResolve() output: null-or-contained (lstat walk, symlink refusal, trailing-slash boundary)
            $ok = $s !== null && $d !== null && file_exists($s) && !file_exists($d) && @rename($s, $d);
            Helpers::flash($ok ? 'ok' : 'err', $ok ? "Renamed $old to $new" : 'Rename failed');
            self::back($rel);
        }
        $r = ServiceManager::run('file-rename.sh', [$u['username'], $rel, $old, $new]);
        Helpers::flash(($r['code'] ?? 1) === 0 ? 'ok' : 'err', ($r['code'] ?? 1) === 0 ? "Renamed $old to $new" : 'Rename failed: '.self::out($r));
        self::back($rel);
    }
    // POST /files/zip — pack the selection: archive name picks .zip or .tar.gz
}
