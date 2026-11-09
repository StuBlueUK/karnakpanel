<?php
declare(strict_types=1);

class AdminPhpController extends Controller {

    public const PHPINI_KEYS = ['memory_limit', 'upload_max_filesize', 'post_max_size', 'max_execution_time', 'display_errors'];
    public function phpini(): void {
        Auth::requireRole(['admin']);
        $ver = preg_replace('/[^0-9]/', '', (string)($_GET['ver'] ?? ''));
        if (!in_array($ver, PhpVersions::knownShort(), true)) $ver = str_replace('.', '', PhpVersions::DEFAULT);
        $vals = [];
        $opcache = null;
        $f = "/etc/php/8.${ver[1]}/fpm/php.ini";
        $oc = "/etc/php/8.${ver[1]}/fpm/conf.d/10-opcache.ini";
        if (is_readable($oc)) {
            foreach (file($oc, FILE_IGNORE_NEW_LINES) as $line) {
                if (preg_match('/^opcache\.enable\s*=\s*(\d)/i', $line, $m)) { $opcache = $m[1] === '1' ? 'on' : 'off'; break; }
            }
        }
        if (is_readable($f)) {
            // active (uncommented) lines only — commented defaults don't apply
            foreach (file($f, FILE_IGNORE_NEW_LINES) as $line) {
                if (preg_match('/^([a-z_.]+)\s*=\s*(.*?)\s*$/i', $line, $m) && in_array($m[1], self::PHPINI_KEYS, true)) {
                    $vals[$m[1]] = $m[2];
                }
            }
        }
        $this->view('admin_phpini', ['ver' => $ver, 'vers' => PhpVersions::knownShort(), 'vals' => $vals, 'keys' => self::PHPINI_KEYS, 'opcache' => $opcache]);
    }

    public function opcacheSet(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/phpini'); }
        $ver = preg_replace('/[^0-9]/', '', (string)($_POST['ver'] ?? ''));
        $state = (string)($_POST['state'] ?? '');
        if (!in_array($ver, PhpVersions::knownShort(), true) || ($state !== 'on' && $state !== 'off')) {
            Helpers::flash('err','Bad version/state'); Helpers::redirect('/admin/phpini?ver='.$ver);
        }
        $r = ServiceManager::run('opcache-set.sh', [$ver, $state]);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Not applied: '.trim($r['out']));
        else { Helpers::log("opcache $state 8.${ver[1]} by ".Auth::user()['username']); Helpers::flash('ok', trim($r['out'])); }
        Helpers::redirect('/admin/phpini?ver='.$ver);
    }

    public function phpiniSet(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/phpini'); }
        $ver = preg_replace('/[^0-9]/', '', (string)($_POST['ver'] ?? ''));
        $key = (string)($_POST['key'] ?? '');
        $val = trim((string)($_POST['value'] ?? ''));
        if (!in_array($ver, PhpVersions::knownShort(), true) || !in_array($key, self::PHPINI_KEYS, true) || $val === '' || strlen($val) > 32) {
            Helpers::flash('err','Bad version/key/value'); Helpers::redirect('/admin/phpini?ver='.$ver);
        }
        $r = ServiceManager::run('phpini-set.sh', [$ver, $key, $val]);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Not applied: '.trim($r['out']));
        else { Helpers::log("php.ini 8.${ver[1]} $key=$val by ".Auth::user()['username']); Helpers::flash('ok', trim($r['out'])); }
        Helpers::redirect('/admin/phpini?ver='.$ver);
    }

    // Per-user FPM pools: version, worker cap, hardening status (shell
    // functions off, open_basedir, memory cap, niced), the account's own
    // master and its CPU/RAM caps. Re-apply regenerates
    // the pool from the hardened template — safe to run any time.
    public function phpPools(): void {
        Auth::requireRole(['admin']);
        $pools = [];
        $err = '';
        $r = ServiceManager::run('fpm-status.sh', [], 45);
        if (($r['code'] ?? 1) !== 0) $err = ($r['code'] ?? 1) === 124 ? 'The server took over 45 s to answer (systemd busy) — reload this page.' : 'Could not read the pools: '.trim((string)($r['out'] ?? ''));
        if (($r['code'] ?? 1) === 0) {
            foreach (explode("\n", trim($r['out'] ?? '')) as $line) {
                $p = explode("\t", $line);
                if (count($p) !== 6 && count($p) !== 11) continue;
                // 11 columns: + own/shared master, state, RAM used/cap MB, CPU cap % (fpm-account.sh)
                $pools[] = ['user' => $p[0], 'ver' => $p[1], 'children' => $p[2], 'hardened' => $p[3], 'memory' => $p[4], 'nice' => $p[5],
                    'master' => $p[6] ?? 'shared', 'state' => $p[7] ?? '-', 'ram' => $p[8] ?? '-', 'ramcap' => $p[9] ?? '-', 'cpucap' => $p[10] ?? '-'];
            }
        }
        // whole-box counts, then problems first + search + 25 per page (1000
        // pools in one table was unusable)
        $sum = ['all' => count($pools), 'active' => 0, 'problem' => 0];
        $bad = fn($p) => $p['hardened'] !== 'yes' || $p['master'] !== 'own' || !in_array($p['state'], ['active', 'idle'], true);
        foreach ($pools as $p) { if ($bad($p)) $sum['problem']++; elseif ($p['state'] === 'active') $sum['active']++; }
        $q = substr(trim((string)($_GET['q'] ?? '')), 0, 64);
        if ($q !== '') $pools = array_values(array_filter($pools, fn($p) => stripos($p['user'], $q) !== false));
        usort($pools, fn($a, $b) => [!$bad($a), $a['state'] !== 'active', $a['user'], $a['ver']] <=> [!$bad($b), $b['state'] !== 'active', $b['user'], $b['ver']]);
        $pg = Helpers::paginate(count($pools), max(1, (int)($_GET['page'] ?? 1)));
        $total = $pg['total']; $pages = $pg['pages'];
        $pools = array_slice($pools, $pg['offset'], $pg['limit']);
        $pg = $pg['pg'];
        $this->view('admin_pools', compact('pools', 'err', 'sum', 'q', 'pg', 'pages', 'total'));
    }

    public function phpPoolsApply(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/php-pools'); }
        $target = (string)($_POST['target'] ?? '--all');
        if ($target !== '--all' && !preg_match('/^[a-z][a-z0-9_]{2,31}$/', $target)) {
            Helpers::flash('err','Bad user'); Helpers::redirect('/admin/php-pools');
        }
        $r = ServiceManager::run('fpm-harden.sh', [$target]);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Not applied: '.trim($r['out']));
        else { Helpers::log("fpm-harden $target by ".Auth::user()['username']); Helpers::flash('ok', trim($r['out'])); }
        Helpers::redirect('/admin/php-pools');
    }

    // Curated PHP extensions, installed server-wide on every FPM version at
    // once (php-ext.sh). Install/remove run detached; the page polls.
    public function phpExt(): void {
        Auth::requireRole(['admin']);
        $grid = []; $vers = []; $running = false; $list = [];
        $r = ServiceManager::run('php-ext.sh', ['status'], 60);
        if (($r['code'] ?? 1) === 0) {
            foreach (explode("\n", trim($r['out'] ?? '')) as $line) {
                $p = explode("\t", $line, 2);
                if (count($p) !== 2) continue;
                if ($p[0] === 'job') { $running = trim($p[1]) === 'running'; continue; }
                if ($p[0] === 'list') { $list = array_values(array_filter(explode(' ', trim($p[1])))); continue; }
                if (!PhpExtensions::valid($p[0])) continue;
                foreach (explode(' ', trim($p[1])) as $vs) {
                    if (preg_match('/^(8\.\d)=(on|off|missing|na)$/', $vs, $m)) { $grid[$p[0]][$m[1]] = $m[2]; $vers[$m[1]] = true; }
                }
            }
        }
        $vers = array_keys($vers); sort($vers);
        $log = [];
        $lr = ServiceManager::run('log-read.sh', ['phpext', '50']);
        if (($lr['code'] ?? 1) === 0) $log = array_slice(array_filter(explode("\n", $lr['out'] ?? ''), 'strlen'), -14);
        $err = ($r['code'] ?? 1) === 0 ? '' : trim($r['out'] ?? '');
        $this->view('admin_phpext', compact('grid', 'vers', 'running', 'list', 'log', 'err'));
    }

    public function phpExtApply(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/php-ext'); }
        $act = (string)($_POST['act'] ?? '');
        $ext = (string)($_POST['ext'] ?? '');
        if (!in_array($act, ['install', 'remove'], true) || !PhpExtensions::valid($ext)) {
            Helpers::flash('err','Bad extension/action'); Helpers::redirect('/admin/php-ext');
        }
        $r = ServiceManager::run('php-ext.sh', [$act, $ext], 60);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err', trim($r['out'] ?? 'failed'));
        else { Helpers::log("php-ext $act $ext by ".Auth::user()['username']); Helpers::flash('ok', trim($r['out'])); }
        Helpers::redirect('/admin/php-ext');
    }
}
