<?php
declare(strict_types=1);

class AdminUpdateController extends Controller {
    public function updatePage(): void {        Auth::requireRole(['admin']);
        $reboot = file_exists('/var/run/reboot-required');
        // current release = symlink target; releases = version dirs on disk
        $cur = is_link('/usr/local/skypanel/panel') ? basename(dirname((string)readlink('/usr/local/skypanel/panel'))) : '(pre-release layout)';
        $rels = [];        foreach ((glob('/usr/local/skypanel/releases/*') ?: []) as $d) {
            if (is_dir($d) && preg_match('/^[a-zA-Z0-9._-]+$/', basename($d))) $rels[] = basename($d);
        }
        rsort($rels);
        // available-update banner (same daily probe state as the dashboard)
        $update = null;
        $st = @file('/var/lib/karnakpanel/update-check', FILE_IGNORE_NEW_LINES);
        if ($st && preg_match('/^(\S+) (\S+) (ok|urgent|info|unreachable)$/', trim(implode('', $st)), $m) && $m[3] !== 'ok') {
            $update = ['local' => $m[1], 'remote' => $m[2], 'sev' => $m[3]];
        }
        // file integrity: tripwire-lite over panel code, scripts and control
        // configs. Checked daily by cron; this is the live status + the
        // re-baseline button for after intentional hand edits.
        $integ = 'unknown';
        try {
            $ir = ServiceManager::run('integrity-check.sh', []);
            $integ = (($ir['code'] ?? 1) === 0) ? 'ok' : trim($ir['out'] ?? 'changed');
        } catch (Throwable $e) {}
        // staged release available for one-click install (fetched, verified)
        $staged = null;
        $sdir = realpath('/usr/local/skypanel/panel/storage/tmp-uploads');
        if ($sdir) {
            $cands = glob("$sdir/karnakpanel-panel-*.tar.gz") ?: [];
            if ($cands) {
                usort($cands, fn($a, $b) => filemtime($b) <=> filemtime($a));
                $staged = basename($cands[0]);
            }
        }
        $sys = $this->sysPending();
        $auto = $this->autoUpdates();
        $this->view('admin_update', ['reboot' => $reboot, 'cur' => $cur, 'rels' => $rels, 'integ' => $integ, 'update' => $update, 'staged' => $staged, 'sys' => $sys, 'auto' => $auto]);
    }

    // automatic OS updates (auto-updates.sh get): the distro's unattended-upgrades
    private function autoUpdates(): array {
        $a = ['ok' => false, 'installed' => false, 'security' => true, 'reboot' => false, 'all' => false, 'hour' => 'os', 'next' => 0, 'last' => null];
        try {
            $r = ServiceManager::run('auto-updates.sh', ['get']);
            if (($r['code'] ?? 1) !== 0) return $a;
            $a['ok'] = true;
            foreach (explode("\n", (string)($r['out'] ?? '')) as $line) {
                $p = explode("\t", rtrim($line, "\r"));
                $v = $p[1] ?? '';
                switch ($p[0]) {
                    case 'installed': case 'security': case 'reboot': case 'all': $a[$p[0]] = $v === '1'; break;
                    case 'hour': $a['hour'] = preg_match('/^[0-9]{1,2}$/', $v) ? (int)$v : 'os'; break;
                    case 'next': $a['next'] = (int)$v; break;
                    case 'last': $a['last'] = ['at' => (int)$v, 'result' => in_array($p[2] ?? '', ['installed','failed','nothing'], true) ? $p[2] : 'nothing', 'count' => (int)($p[3] ?? 0)]; break;
                }
            }
        } catch (Throwable $e) {}
        return $a;
    }

    public function updateAuto(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/update'); }
        $sec = !empty($_POST['security']) ? '1' : '0';
        $rb = !empty($_POST['reboot']) ? '1' : '0';
        $all = ($sec === '1' && !empty($_POST['all'])) ? '1' : '0';
        $h = (string)($_POST['hour'] ?? 'os');
        if ($h !== 'os' && !(preg_match('/^[0-9]{1,2}$/', $h) && (int)$h <= 23)) $h = 'os';
        $r = ServiceManager::run('auto-updates.sh', ['set', $sec, $rb, $all, $h]);
        $out = trim((string)($r['out'] ?? ''));
        Helpers::log("automatic updates set security=$sec reboot=$rb all=$all hour=$h by ".Auth::user()['username'].": $out");
        if (($r['code'] ?? 1) === 0) Helpers::flash('ok', 'Automatic update settings saved');
        else Helpers::flash('err', 'Not saved: '.$out);
        Helpers::redirect('/admin/update#auto');
    }

    // OS packages waiting (system-update.sh status: current lists, no network)
    private function sysPending(): array {
        $sys = ['ok' => false, 'pkgs' => [], 'security' => 0, 'kept' => [], 'lists' => 0, 'reboot' => false, 'rebootPkgs' => '', 'auto' => false, 'running' => 'no'];
        try {
            $r = ServiceManager::run('system-update.sh', ['status']);
            if (($r['code'] ?? 1) !== 0) return $sys;
            $sys['ok'] = true;
            foreach (explode("\n", (string)($r['out'] ?? '')) as $line) {
                $p = explode("\t", rtrim($line, "\r"));
                switch ($p[0]) {
                    case 'pkg':
                        if (count($p) < 5) break;
                        $sys['pkgs'][] = ['name' => $p[1], 'from' => $p[2], 'to' => $p[3], 'kind' => $p[4] === 'security' ? 'security' : 'update', 'reboot' => ($p[5] ?? '0') === '1'];
                        if ($p[4] === 'security') $sys['security']++;
                        break;
                    case 'kept': if (isset($p[1])) $sys['kept'][] = $p[1]; break;
                    case 'lists': $sys['lists'] = (int)($p[1] ?? 0); break;
                    case 'reboot': $sys['reboot'] = ($p[1] ?? '') === '1'; $sys['rebootPkgs'] = trim($p[2] ?? ''); break;
                    case 'auto': $sys['auto'] = ($p[1] ?? '') === '1'; break;
                    case 'running': $sys['running'] = in_array($p[1] ?? '', ['panel','auto'], true) ? $p[1] : 'no'; break;
                }
            }
            // security first, then by name
            usort($sys['pkgs'], fn($a, $b) => [$a['kind'] !== 'security', $a['name']] <=> [$b['kind'] !== 'security', $b['name']]);
        } catch (Throwable $e) {}
        return $sys;
    }

    // Live console for a panel-started apt run: new log bytes since ?off=
    // ("last" = the latest run's start). Admin GET, read-only. The text is
    // package output: the page renders it with textContent, never as HTML.
    public function updateLog(): void {
        Auth::requireRole(['admin']);
        $this->tailJson('system-update.sh');
    }

    // Same live console for the panel's own update/rollback (update.log).
    public function panelUpdateLog(): void {
        Auth::requireRole(['admin']);
        $this->tailJson('panel-update.sh');
    }

    private function tailJson(string $script): void {
        $off = (string)($_GET['off'] ?? 'last');
        if ($off !== 'last' && !preg_match('/^[0-9]{1,12}$/', $off)) $off = 'last';
        $r = ServiceManager::run($script, ['tail', $off]);
        $out = (string)($r['out'] ?? '');
        $nl = strpos($out, "\n");
        $head = explode("\t", $nl === false ? $out : substr($out, 0, $nl));
        if (($r['code'] ?? 1) !== 0 || ($head[0] ?? '') !== 'state') $this->json(['error' => 'log unavailable'], 500);
        $text = $nl === false ? '' : substr($out, $nl + 1);
        // a read can end mid-character: hold back a partial UTF-8 tail
        $next = (int)($head[2] ?? 0);
        if ($text !== '' && !mb_check_encoding($text, 'UTF-8')) {
            for ($cut = 1; $cut <= 3 && $cut < strlen($text); $cut++) {
                if (mb_check_encoding(substr($text, 0, -$cut), 'UTF-8')) { $text = substr($text, 0, -$cut); $next -= $cut; break; }
            }
        }
        header('Cache-Control: no-store');
        $this->json(['state' => ($head[1] ?? '') === 'running' ? 'running' : 'idle', 'off' => $next,
            'text' => mb_scrub($text, 'UTF-8')]);
    }

    // Reboot is scheduled +1 min (not instant) so this response + flash land
    // before the box goes down. Admin-only + CSRF like every other POST here.
    public function reboot(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/update'); }
        $r = ServiceManager::run('system-reboot.sh', ['go']);
        Helpers::log("reboot scheduled by ".Auth::user()['username']);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Reboot refused: '.trim($r['out']));
        else Helpers::flash('ok', trim($r['out'])." — panel, sites and mail drop for ~1-2 min");
        Helpers::redirect('/admin/update');
    }

    public function updateStart(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/update'); }
        $mode = ($_POST['mode'] ?? '') === 'go' ? 'go' : 'check';
        $r = ServiceManager::run('system-update.sh', [$mode]);
        $out = trim((string)($r['out'] ?? ''));
        Helpers::log("system update started mode=".($mode === 'go' ? 'upgrade' : 'check')." by ".Auth::user()['username'].": $out");
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err', 'Not started: '.$out);
        elseif ($out !== 'started') Helpers::flash('err', $out);
        else Helpers::flash('ok', $mode === 'go' ? 'Upgrade started — follow it live below' : 'Checking for updates — follow it live below');
        Helpers::redirect('/admin/update?live=1');
    }

    // One-click import: fetch the latest SIGNED release from the feed into
    // staging (checksum-verified; signature verified at install time by
    // panel-update.sh). Install stays a separate deliberate click below.
    public function panelFetch(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/update'); }
        $r = ServiceManager::run('panel-fetch-update.sh', []);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Fetch failed: '.trim($r['out']));
        else Helpers::flash('ok',trim($r['out']).' — install it with the button below');
        Helpers::redirect('/admin/update');
    }

    // Install a fetched (staged) release without re-uploading: newest
    // karnakpanel-panel-*.tar.gz in staging. Same panel-update.sh path (and
    // signature gates) as the upload form — this only skips the upload.
    public function panelInstallStaged(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/update'); }
        $stage = realpath('/usr/local/skypanel/panel/storage/tmp-uploads');
        $cands = $stage ? glob("$stage/karnakpanel-panel-*.tar.gz") : [];
        if (!$cands) { Helpers::flash('err','Nothing staged — fetch first'); Helpers::redirect('/admin/update'); }
        usort($cands, fn($a, $b) => filemtime($b) <=> filemtime($a));
        $r = ServiceManager::run('panel-update.sh', [$cands[0]]);
        Helpers::log("panel self-update (staged) started by ".Auth::user()['username']);
        self::panelRunFlash($r, 'Update');
    }

    public function panelUpdate(): void {        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/update'); }
        $f = $_FILES['pkg'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== 0 || !str_ends_with(strtolower($f['name'] ?? ''), '.tar.gz')) {
            Helpers::flash('err','Upload a .tar.gz release tarball'); Helpers::redirect('/admin/update');
        }
        $stage = '/usr/local/skypanel/panel/storage/tmp-uploads';
        // panel dir may be a symlink after first update — resolve for staging
        $real = realpath('/usr/local/skypanel/panel/storage/tmp-uploads') ?: $stage;
        @mkdir($real, 0700, true);
        $dest = $real.'/release-'.bin2hex(random_bytes(8)).'.tar.gz';
        if (!@move_uploaded_file($f['tmp_name'], $dest)) { Helpers::flash('err','Staging failed'); Helpers::redirect('/admin/update'); }
        $r = ServiceManager::run('panel-update.sh', [$dest]);
        Helpers::log("panel self-update started by ".Auth::user()['username']);
        self::panelRunFlash($r, 'Update');
    }

    // update/rollback run detached: back to the Update page, where the live
    // card (top of the page) follows update.log until it finishes
    private static function panelRunFlash(array $r, string $what): void {
        $out = trim((string)($r['out'] ?? ''));
        if (($r['code'] ?? 1) !== 0) { Helpers::flash('err', "$what not started: $out"); Helpers::redirect('/admin/update'); }
        Helpers::flash('ok', "$what started — progress below");
        Helpers::redirect('/admin/update?plive=1');
    }

    // Re-baseline file integrity after intentional hand edits (config
    // tuning etc.). Updates and SSL/exim scripts re-baseline themselves.
    public function integrityBaseline(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/update'); }
        $r = ServiceManager::run('integrity-check.sh', ['--baseline']);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Baseline failed: '.trim($r['out']));
        else { Helpers::log("integrity re-baselined by ".Auth::user()['username']); Helpers::flash('ok', trim($r['out'])); }
        Helpers::redirect('/admin/update');
    }

    public function panelRollback(): void {        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/update'); }
        $v = (string)($_POST['release'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $v)) { Helpers::flash('err','Bad release'); Helpers::redirect('/admin/update'); }
        $r = ServiceManager::run('panel-rollback.sh', [$v]);
        Helpers::log("panel rollback to $v started by ".Auth::user()['username']);
        self::panelRunFlash($r, 'Rollback');
    }
}
