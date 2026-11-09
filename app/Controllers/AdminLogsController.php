<?php
declare(strict_types=1);

class AdminLogsController extends Controller {
    public function logs(): void {
        Auth::requireRole(['admin']);
        $valid = ['panel','exim','exim_reject','mail','vsftpd','fail2ban','litespeed','named','upgrade','phpext','update','software','apptoolkit','malware','offsite','certbot','transfer'];
        $svc = (string)($_GET['log'] ?? 'panel');
        if (!in_array($svc, $valid, true)) $svc = 'panel';
        $lines = [];
        $n = (int)($_GET['n'] ?? 200);
        if (!in_array($n, [50, 200, 500], true)) $n = 200;
        try {
            $r = ServiceManager::run('log-read.sh', [$svc, (string)$n]);
            if (($r['code'] ?? 1) === 0) {
                $all = array_filter(array_map('trim', explode("\n", $r['out'] ?? '')));
                $lines = array_values($all);
            } else $lines = ['(reader failed: '.trim($r['out']).')'];
        } catch (Throwable $e) { $lines = ['(reader error)']; }
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q !== '') $lines = array_values(array_filter($lines, fn($l) => stripos($l, $q) !== false));
        $ord = (string)($_GET['ord'] ?? 'new');
        if ($ord !== 'old') { $ord = 'new'; $lines = array_reverse($lines); }
        // readability: split leading timestamp, classify severity for coloring
        $rows = [];
        foreach ($lines as $l) {
            $ts = '';
            if (preg_match('/^(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}|\w{3}\s+\d{1,2} \d{2}:\d{2}:\d{2})\s*/', $l, $m)) {
                $ts = $m[1]; $l = substr($l, strlen($m[0]));
            }
            $lvl = Helpers::logLevel($l);
            $rows[] = ['ts' => $ts, 'msg' => $l, 'lvl' => $lvl];
        }
        $this->view('admin_logs', ['rows' => $rows, 'q' => $q, 'svc' => $svc, 'svcs' => $valid, 'n' => $n, 'ord' => $ord]);
    }
    public function sessions(): void {
        Auth::requireRole(['admin']);
        $list = []; $anon = 0;
        try {
            $r = ServiceManager::run('session-list.sh', []);
            if (($r['code'] ?? 1) === 0) {
                foreach (explode("\n", trim($r['out'] ?? '')) as $line) {
                    $p = explode("\t", $line);
                    if (count($p) < 4 || !preg_match('/^[a-z0-9,-]{22,128}$/i', $p[0])) continue;
                    // not logged in (login-page visit / health check): count only
                    if (($p[4] ?? '1') === '0') { $anon++; continue; }
                    $list[] = ['sid' => $p[0], 'user' => $p[1], 'role' => $p[2],
                        'active' => date('Y-m-d H:i:s', (int)$p[3]),
                        'current' => ($p[0] === session_id())];
                }
            }
        } catch (Throwable $e) {}
        usort($list, fn($a, $b) => strcmp($b['active'], $a['active']));
        $this->view('admin_sessions', compact('list', 'anon'));
    }
    public function sessionKill(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/sessions'); }
        $sid = (string)($_POST['sid'] ?? '');
        if (!preg_match('/^[a-z0-9,-]{22,128}$/i', $sid)) { Helpers::flash('err','Bad session'); Helpers::redirect('/admin/sessions'); }
        if ($sid === session_id()) { Helpers::flash('err','That is your own session'); Helpers::redirect('/admin/sessions'); }
        $r = ServiceManager::run('session-kill.sh', [$sid]);
        if (($r['code'] ?? 1) === 0) { Helpers::log("session killed for sid=$sid by ".Auth::user()['username']); Helpers::flash('ok','Session killed (next click logs them out)'); }
        else Helpers::flash('err','Kill failed: '.trim($r['out']));
        Helpers::redirect('/admin/sessions');
    }
}
