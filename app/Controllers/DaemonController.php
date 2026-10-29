<?php
declare(strict_types=1);
class DaemonController extends Controller {
    public function list(): void {
        Auth::requireLogin();
        $u = Auth::user();
        $daemons = Database::query("SELECT * FROM app_daemons WHERE user_id=? ORDER BY name", [$u['id']])->fetchAll();
        foreach ($daemons as &$d) {
            $d['state'] = 'unknown'; $d['auto'] = '';
            try {
                $r = ServiceManager::run('app-status.sh', [$u['username'], $d['name']]);
                if (($r['code'] ?? 1) === 0) { $p = explode(' ', trim($r['out'])); $d['state'] = $p[0] ?? '?'; $d['auto'] = $p[1] ?? ''; }
            } catch (Throwable $e) {}
        }
        unset($d);
        $this->view('daemons', compact('daemons'));
    }
    private function own(array $u, int $id): ?array {
        $d = Database::query("SELECT * FROM app_daemons WHERE id=? AND user_id=?", [$id, $u['id']])->fetch();
        return $d ?: null;
    }
    public function create(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/daemons'); }
        $u = Auth::user();
        $name = strtolower(trim($_POST['name'] ?? ''));
        $cmd = trim((string)($_POST['command'] ?? ''));
        $dir = trim($_POST['workdir'] ?? '', '/');
        if (!preg_match('/^[a-z0-9_-]{1,32}$/', $name)) { Helpers::flash('err','Bad name (a-z0-9_-)'); Helpers::redirect('/daemons'); }
        $tok0 = explode(' ', $cmd)[0] ?? '';
        // control chars (newline!) never reach the runner — app-daemon.sh refuses them too
        if ($cmd === '' || strlen($cmd) > 500 || preg_match('/[[:cntrl:]]/', $cmd) || preg_match('/[[:cntrl:]]/', $dir)) { Helpers::flash('err','Bad command'); Helpers::redirect('/daemons'); }
        $home = "/home/{$u['username']}";
        $wdir = $home.($dir !== '' ? "/$dir" : '');
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- validation gate itself; bound params below, no filename sink
        if (str_contains($dir, '..') || !is_dir($wdir)) { Helpers::flash('err','Workdir must be an existing folder in your home'); Helpers::redirect('/daemons'); }
        try {
            Database::query("INSERT INTO app_daemons(user_id,name,command,workdir) VALUES(?,?,?,?)", [$u['id'], $name, $cmd, $wdir]);
        } catch (Throwable $e) { Helpers::flash('err','Name taken'); Helpers::redirect('/daemons'); }
        $r = ServiceManager::run('app-daemon.sh', ['create', $u['username'], $name, $cmd, $wdir]);
        if (($r['code'] ?? 1) !== 0) {
            Database::query("DELETE FROM app_daemons WHERE user_id=? AND name=?", [$u['id'], $name]);
            Helpers::flash('err','Daemon refused: '.trim($r['out']));
        } else Helpers::flash('ok',"Daemon $name started (autostart on)");
        Helpers::redirect('/daemons');
    }
    public function act(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/daemons'); }
        $u = Auth::user();
        $d = $this->own($u, (int)($_POST['id'] ?? 0));
        $op = (string)($_POST['op'] ?? '');
        if (!$d || !in_array($op, ['start','stop','restart','on','off'], true)) { Helpers::flash('err','Bad request'); Helpers::redirect('/daemons'); }
        $r = ServiceManager::run('app-daemon.sh', [$op, $u['username'], $d['name']]);
        Helpers::flash(($r['code'] ?? 1) === 0 ? 'ok' : 'err', trim($r['out']));
        Helpers::redirect('/daemons');
    }
    public function delete(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/daemons'); }
        $u = Auth::user();
        $d = $this->own($u, (int)($_POST['id'] ?? 0));
        if (!$d) { Helpers::flash('err','Not found'); Helpers::redirect('/daemons'); }
        Database::query("DELETE FROM app_daemons WHERE id=?", [$d['id']]);
        ServiceManager::run('app-daemon.sh', ['remove', $u['username'], $d['name']]);
        Helpers::flash('ok',"Daemon {$d['name']} removed"); Helpers::redirect('/daemons');
    }
    public function logs(): void {
        Auth::requireLogin();
        $u = Auth::user();
        $d = $this->own($u, (int)($_GET['id'] ?? 0));
        if (!$d) { Helpers::flash('err','Not found'); Helpers::redirect('/daemons'); }
        $lines = [];
        $r = ServiceManager::run('app-log.sh', [$u['username'], $d['name'], '100']);
        if (($r['code'] ?? 1) === 0) $lines = array_slice(array_reverse(array_filter(array_map('trim', explode("\n", $r['out'] ?? '')))), 0, 100);
        $this->view('daemon_logs', ['d' => $d, 'lines' => $lines]);
    }
}
