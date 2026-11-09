<?php
declare(strict_types=1);
class DaemonController extends Controller {
    // scheduled restart choices (hours; 0 = never) — app-daemon.sh life_ok
    public const LIFETIMES = [0, 1, 3, 6, 12, 24, 48, 72, 168];
    // app-status.sh show lines -> [name => state]
    public static function states(string $who): array {
        $out = [];
        try {
            $r = ServiceManager::run('app-status.sh', ['show', $who]);
            if (($r['code'] ?? 1) !== 0) return [];
            foreach (explode("\n", trim((string)$r['out'])) as $l) {
                $c = explode("\t", $l);
                if (count($c) < 10) continue;
                $out[$c[0].'/'.$c[1]] = [
                    'state' => $c[2], 'sub' => $c[3], 'auto' => $c[4], 'result' => $c[5], 'restarts' => (int)$c[6],
                    'mem' => ctype_digit($c[7]) ? (int)$c[7] : null, 'cpu_s' => ctype_digit($c[8]) ? intdiv((int)$c[8], 1000000000) : null,
                    'since' => (int)$c[9],
                    // the crash-loop guard stopped it (5 starts in 5 min)
                    'looped' => $c[2] === 'failed' && $c[5] === 'start-limit-hit',
                ];
            }
        } catch (Throwable $e) {}
        return $out;
    }
    // the account's daemon caps, from the slice daemon-caps.sh wrote (null = none yet)
    private static function limits(string $user): ?array {
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- username comes from the session (validated at account creation)
        $txt = @file_get_contents("/etc/systemd/system/karnakapp-$user.slice");
        if (is_string($txt) && preg_match('/^CPUQuota=(\d+)%/m', $txt, $a) && preg_match('/^MemoryMax=(\d+)M/m', $txt, $b)) return ['cpu' => (int)$a[1] / 100, 'mem' => (int)$b[1]];
        return null;
    }
    // [used, max|null] — null max = no package (admins): not limited
    private static function quota(array $u): array {
        $used = DaemonModel::countFor((int)$u['id']);
        if (($u['role'] ?? '') === 'admin') return [$used, null];
        try {
            $max = DaemonModel::maxFor((int)$u['id']);
        } catch (Throwable $e) { $max = null; }
        return [$used, $max === null ? null : (int)$max];
    }
    public function list(): void {
        Auth::requireLogin();
        $u = Auth::user();
        $daemons = DaemonModel::listFor((int)$u['id']);
        $st = self::states($u['username']);
        foreach ($daemons as &$d) {
            $d += $st[$u['username'].'/'.$d['name']] ?? ['state' => 'unknown', 'sub' => '', 'auto' => '', 'result' => '', 'restarts' => 0, 'mem' => null, 'cpu_s' => null, 'since' => 0, 'looped' => false];
        }
        unset($d);
        [$used, $max] = self::quota($u);
        $limits = self::limits($u['username']);
        $phps = array_values(array_filter(PhpVersions::known(), fn($v) => is_executable("/usr/bin/php$v")));
        $lifetimes = self::LIFETIMES;
        $this->view('daemons', compact('daemons', 'used', 'max', 'limits', 'phps', 'lifetimes'));
    }
    private function own(array $u, int $id): ?array {
        $d = DaemonModel::findOwned($id, (int)$u['id']);
        return $d ?: null;
    }
    public function create(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/daemons'); }
        $u = Auth::user();
        $name = strtolower(trim($_POST['name'] ?? ''));
        $cmd = trim((string)($_POST['command'] ?? ''));
        $dir = trim($_POST['workdir'] ?? '', '/');
        $life = (int)($_POST['life'] ?? 0);
        if (!preg_match('/^[a-z0-9_-]{1,32}$/', $name)) { Helpers::flash('err','Bad name (a-z0-9_-)'); Helpers::redirect('/daemons'); }
        if (!in_array($life, self::LIFETIMES, true)) { Helpers::flash('err','Bad restart interval'); Helpers::redirect('/daemons'); }
        [$used, $max] = self::quota($u);
        if ($max !== null && $used >= $max) {
            Helpers::flash('err', $max === 0 ? 'Your plan has no daemons — ask your host to upgrade.' : "Your plan allows $max daemon(s) and you have $used. Delete one or ask your host to upgrade.");
            Helpers::redirect('/daemons');
        }
        // control chars (newline!) never reach the runner — app-daemon.sh refuses them too
        if ($cmd === '' || strlen($cmd) > 500 || preg_match('/[[:cntrl:]]/', $cmd) || preg_match('/[[:cntrl:]]/', $dir)) { Helpers::flash('err','Bad command'); Helpers::redirect('/daemons'); }
        $home = "/home/{$u['username']}";
        $wdir = $home.($dir !== '' ? "/$dir" : '');
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- validation gate itself; bound params below, no filename sink
        if (str_contains($dir, '..') || !is_dir($wdir)) { Helpers::flash('err','Workdir must be an existing folder in your home'); Helpers::redirect('/daemons'); }
        try {
            DaemonModel::insert((int)$u['id'], $name, $cmd, $wdir, $life);
        } catch (Throwable $e) { Helpers::flash('err','Name taken'); Helpers::redirect('/daemons'); }
        $r = ServiceManager::run('app-daemon.sh', ['create', $u['username'], $name, $cmd, $wdir, (string)$life]);
        if (($r['code'] ?? 1) !== 0) {
            DaemonModel::deleteByName((int)$u['id'], $name);
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
        if (!$d || !in_array($op, ['start','stop','restart','on','off','life'], true)) { Helpers::flash('err','Bad request'); Helpers::redirect('/daemons'); }
        $args = [$op, $u['username'], $d['name']];
        if ($op === 'life') {
            $life = (int)($_POST['life'] ?? -1);
            if (!in_array($life, self::LIFETIMES, true)) { Helpers::flash('err','Bad restart interval'); Helpers::redirect('/daemons'); }
            $args[] = (string)$life;
        }
        $r = ServiceManager::run('app-daemon.sh', $args);
        if ($op === 'life' && ($r['code'] ?? 1) === 0) DaemonModel::setMaxHours((int)$d['id'], (int)$args[3]);
        Helpers::flash(($r['code'] ?? 1) === 0 ? 'ok' : 'err', trim($r['out']));
        Helpers::redirect('/daemons');
    }
    public function delete(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/daemons'); }
        $u = Auth::user();
        $d = $this->own($u, (int)($_POST['id'] ?? 0));
        if (!$d) { Helpers::flash('err','Not found'); Helpers::redirect('/daemons'); }
        DaemonModel::delete((int)$d['id']);
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
