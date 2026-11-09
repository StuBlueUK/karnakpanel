<?php
declare(strict_types=1);

class AdminMailController extends Controller {
    public function mail(): void {
        Auth::requireRole(['admin']);
        $q = ServiceManager::run('mail-queue.sh', ['summary']);
        $frozen = ServiceManager::run('mail-queue.sh', ['frozen']);
        // queue browser: script emits "id|age|size|sender|recipients|frozen"
        // (caps 500); filter + paginate here.
        $msgs = [];
        $truncated = false;
        $lr = ServiceManager::run('mail-queue.sh', ['list']);
        foreach (explode("\n", $lr['out'] ?? '') as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if ($line === 'truncated=1') { $truncated = true; continue; }
            $p = explode('|', $line);
            if (count($p) !== 6) continue;
            if (!preg_match('/^[A-Za-z0-9]{6}-[A-Za-z0-9]{2,}-[A-Za-z0-9]{2,}$/', $p[0])) continue;
            $msgs[] = ['id'=>$p[0],'age'=>$p[1],'size'=>$p[2],'from'=>$p[3],'to'=>$p[4],'frozen'=>$p[5]==='1'];
        }
        $fq = trim((string)($_GET['q'] ?? ''));
        if ($fq !== '') $msgs = array_values(array_filter($msgs, fn($m) => stripos($m['id'].' '.$m['from'].' '.$m['to'], $fq) !== false));
        $per = 25;
        $pg = max(1, (int)($_GET['pg'] ?? 1));
        $pages = max(1, (int)ceil(count($msgs)/$per));
        $pg = min($pg, $pages);
        $pageMsgs = array_slice($msgs, ($pg-1)*$per, $per);
        // last stuck reason per message on this page (one read-only call)
        $errs = [];
        $ids = array_values(array_unique(array_column($pageMsgs, 'id')));
        if (!empty($ids)) {
            try {
                $er = ServiceManager::run('mail-queue.sh', array_merge(['errors'], $ids));
                foreach (explode("\n", $er['out'] ?? '') as $line) {
                    $line = trim($line);
                    if ($line === '') continue;
                    $p = explode('|', $line, 2);
                    if (count($p) === 2 && $p[1] !== '') $errs[$p[0]] = $p[1];
                }
            } catch (Throwable $e) {}
        }
        // spool totals over the whole (filtered) queue: messages, bytes, oldest
        $tbytes = 0; $told = 0;
        foreach ($msgs as $m) {
            if (preg_match('/^([\d.]+)\s*([KM]?)$/', $m['size'], $sm)) {
                $b = (float)$sm[1];
                if ($sm[2] === 'K') $b *= 1024; elseif ($sm[2] === 'M') $b *= 1048576;
                $tbytes += (int)$b;
            }
            $mins = 0;
            if (preg_match_all('/(\d+)([smhd])/', $m['age'], $am, PREG_SET_ORDER)) {
                foreach ($am as $a) {
                    $v = (int)$a[1];
                    $mins += $a[2] === 'd' ? $v*1440 : ($a[2] === 'h' ? $v*60 : ($a[2] === 'm' ? $v : 0));
                }
            }
            if ($mins > $told) $told = $mins;
        }
        $totals = ['n' => count($msgs), 'bytes' => $tbytes, 'oldest' => $told];
        // sender-domain deliverability: one mail-score per unique domain on
        // this page (cap 5 — each call is bounded, but slow DNS adds up).
        $auth = [];
        $doms = [];
        foreach ($pageMsgs as $m) {
            if (str_contains($m['from'], '@')) { $d = strtolower(substr(strrchr($m['from'], '@'), 1)); if ($d !== '' && !in_array($d, $doms, true)) $doms[] = $d; }
        }
        foreach (array_slice($doms, 0, 5) as $d) {
            try {
                $sr = ServiceManager::run('mail-score.sh', [$d]);
                $score = null; $det = [];
                foreach (explode("\n", $sr['out'] ?? '') as $sl) {
                    $sl = trim($sl);
                    if (preg_match('/^score=(\d{1,3})$/', $sl, $mm)) $score = min(100, (int)$mm[1]);
                    elseif ($sl !== '') $det[] = $sl;
                }
                if ($score !== null) $auth[$d] = ['score'=>$score, 'details'=>implode("\n", $det)];
            } catch (Throwable $e) {}
        }
        // recent outcomes from the Exim log (newest first, cap 200)
        $outs = [];
        $or = ServiceManager::run('mail-outcomes.sh', ['200']);
        foreach (explode("\n", $or['out'] ?? '') as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $p = explode('|', $line);
            if (count($p) !== 5) continue;
            if (!in_array($p[2], ['delivered','bounced','deferred'], true)) continue;
            $outs[] = ['time'=>$p[0],'id'=>$p[1],'result'=>$p[2],'addr'=>$p[3],'detail'=>$p[4]];
        }
        $res = (string)($_GET['res'] ?? '');
        if (in_array($res, ['delivered','bounced','deferred'], true)) $outs = array_values(array_filter($outs, fn($o) => $o['result'] === $res)); else $res = '';
        $opg = max(1, (int)($_GET['opg'] ?? 1));
        $opages = max(1, (int)ceil(count($outs)/$per));
        $opg = min($opg, $opages);
        $pageOuts = array_slice($outs, ($opg-1)*$per, $per);
        // failing recipient domains (whole outcomes window, not just the page):
        // separates "one dead destination" from "our reputation problem"
        $topDoms = [];
        foreach ($outs as $o) {
            if ($o['result'] === 'delivered') continue;
            $at = strrpos($o['addr'], '@');
            if ($at === false) continue;
            $d = strtolower(substr($o['addr'], $at + 1));
            if ($d === '') continue;
            if (!isset($topDoms[$d])) $topDoms[$d] = ['bounced' => 0, 'deferred' => 0];
            $topDoms[$d][$o['result']]++;
        }
        uasort($topDoms, fn($a, $b) => ($b['bounced'] + $b['deferred']) <=> ($a['bounced'] + $a['deferred']));
        $topDoms = array_slice($topDoms, 0, 8, true);
        $nq = 0; $nf = 0;
        if (preg_match('/queued:\s*(\d+)/', $q['out'] ?? '', $m)) $nq = (int)$m[1];
        if (preg_match('/frozen:\s*(\d+)/', $q['out'] ?? '', $m)) $nf = (int)$m[1];
        // single-message inspection (?show=<id>, headers + recent log lines)
        $show = null; $showId = (string)($_GET['show'] ?? '');
        $showRetry = '';
        if ($showId !== '' && preg_match('/^[A-Za-z0-9]{6}-[A-Za-z0-9]{2,}-[A-Za-z0-9]{2,}$/', $showId)) {
            $vr = ServiceManager::run('mail-queue.sh', ['show-one', $showId]);
            $show = ['id'=>$showId, 'out'=>$vr['out'] ?? '(no output)'];
            // next attempt for the first recipient (one bounded call)
            foreach ($msgs as $m) {
                if ($m['id'] !== $showId) continue;
                $first = trim(explode(',', $m['to'])[0] ?? '');
                if (preg_match('/^[^@\s]+@[^@\s]+\.[a-zA-Z]{2,}$/', $first)) {
                    try {
                        $rr = ServiceManager::run('mail-queue.sh', ['retry-when', $first]);
                        $showRetry = trim($rr['out'] ?? '');
                    } catch (Throwable $e) {}
                }
                break;
            }
        }
        // blacklist monitor: cached rows (blacklist-check.sh), never live DNS here
        $bl = AdminMailModel::blacklistRows();
        $blAt = (string)(Settings::get('blacklist_checked_at') ?? '');
        $blRelay = (string)(Settings::get('blacklist_relay') ?? '') === '1';
        $blRun = trim((string)(ServiceManager::run('blacklist-check.sh', ['running'])['out'] ?? '')) === 'running';
        $this->view('admin_mail', ['bl'=>$bl, 'blAt'=>$blAt, 'blRelay'=>$blRelay, 'blRun'=>$blRun, 'queue'=>$q['out'] ?? '', 'frozen'=>$frozen['out'] ?? '', 'msgs'=>$pageMsgs, 'totalMsgs'=>count($msgs), 'pg'=>$pg, 'pages'=>$pages, 'fq'=>$fq, 'auth'=>$auth, 'truncated'=>$truncated, 'outs'=>$pageOuts, 'totalOuts'=>count($outs), 'opg'=>$opg, 'opages'=>$opages, 'res'=>$res, 'nq'=>$nq, 'nf'=>$nf, 'show'=>$show, 'errs'=>$errs, 'totals'=>$totals, 'topDoms'=>$topDoms, 'showRetry'=>$showRetry]);
    }

    public function blacklistCheck(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/mail'); }
        $r = ServiceManager::run('blacklist-check.sh', ['now']);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err', trim($r['out'] ?? 'failed'));
        else { Helpers::log('blacklist check started by '.Auth::user()['username']); Helpers::flash('ok', trim($r['out'])); }
        Helpers::redirect('/admin/mail#blacklists');
    }

    public function mailFlush(): void {        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/mail'); }
        $r = ServiceManager::run('mail-queue.sh', ['flush']);
        Helpers::flash('ok', 'Queue run: '.trim($r['out'] ?? ''));
        Helpers::redirect('/admin/mail');
    }

    // Thaw frozen (retry), drop frozen (dead bounces), drop all (spam floods,
    // 500/batch — press again if the summary still shows queued). All logged.
    public function mailThaw(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/mail'); }
        $r = ServiceManager::run('mail-queue.sh', ['thaw-frozen']);
        Helpers::log("mail thaw-frozen by ".Auth::user()['username'].": ".trim($r['out'] ?? ''));
        Helpers::flash('ok', trim($r['out'] ?? ''));
        Helpers::redirect('/admin/mail');
    }

    public function mailDropFrozen(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/mail'); }
        $r = ServiceManager::run('mail-queue.sh', ['drop-frozen']);
        Helpers::log("mail drop-frozen by ".Auth::user()['username'].": ".trim($r['out'] ?? ''));
        Helpers::flash('ok', trim($r['out'] ?? ''));
        Helpers::redirect('/admin/mail');
    }

    public function mailDropAll(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/mail'); }
        if (($_POST['confirm'] ?? '') !== 'DELETE') { Helpers::flash('err','Type DELETE to confirm'); Helpers::redirect('/admin/mail'); }
        $r = ServiceManager::run('mail-queue.sh', ['drop-all']);
        Helpers::log("mail DROP-ALL by ".Auth::user()['username'].": ".trim($r['out'] ?? ''));
        Helpers::flash('ok', trim($r['out'] ?? ''));
        Helpers::redirect('/admin/mail');
    }

    // Single-message queue ops (id shape enforced here AND in the script).
    public function mailRetryOne(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/mail'); }
        $id = (string)($_POST['id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9]{6}-[A-Za-z0-9]{2,}-[A-Za-z0-9]{2,}$/', $id)) { Helpers::flash('err','Bad message id'); Helpers::redirect('/admin/mail'); }
        $r = ServiceManager::run('mail-queue.sh', ['retry-one', $id]);
        Helpers::log("mail retry-one $id by ".Auth::user()['username']);
        Helpers::flash('ok', trim($r['out'] ?? ''));
        Helpers::redirect('/admin/mail');
    }

    public function mailDropOne(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/mail'); }
        $id = (string)($_POST['id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9]{6}-[A-Za-z0-9]{2,}-[A-Za-z0-9]{2,}$/', $id)) { Helpers::flash('err','Bad message id'); Helpers::redirect('/admin/mail'); }
        $r = ServiceManager::run('mail-queue.sh', ['drop-one', $id]);
        // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string -- not SQL: audit log text; id format-validated
        Helpers::log("mail DROP-ONE $id by ".Auth::user()['username']);
        Helpers::flash('ok', trim($r['out'] ?? ''));
        Helpers::redirect('/admin/mail');
    }
}
