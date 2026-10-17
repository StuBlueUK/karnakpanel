<?php
declare(strict_types=1);
class CronController extends Controller {
    public function list(): void {
        Auth::requireLogin();
        $u = Auth::user();
        $p = Helpers::paginate((int)Database::query("SELECT COUNT(*) c FROM cron_jobs WHERE user_id=?", [$u['id']])->fetch()['c'], max(1, (int)($_GET['page'] ?? 1)));
        $pg = $p['pg']; $pages = $p['pages']; $total = $p['total'];
        $crons = Database::query("SELECT * FROM cron_jobs WHERE user_id=? ORDER BY id DESC LIMIT ? OFFSET ?", [$u['id'], (int)$p['limit'], (int)$p['offset']])->fetchAll();
        // last-run state from wrapper logs in the user's home (panel-readable via ACL)
        foreach ($crons as &$c) {
            $c['last'] = null;
            $log = "/home/{$u['username']}/.skypanel-cron/{$c['id']}.log";
            if (preg_match('/^[a-z][a-z0-9_]{2,31}$/', $u['username']) && is_readable($log)) {
                $tail = file_get_contents($log, false, null, max(0, (filesize($log) ?: 0) - 4096));
                $lines = array_filter(array_map('trim', explode("\n", (string)$tail)));
                $last = end($lines);
                $start = '';
                foreach (array_reverse($lines) as $l) {
                    if (str_starts_with($l, '=== START ')) { $start = $l; break; }
                }
                $c['last'] = ['start' => $start, 'end' => $last, 'out' => implode("\n", array_slice($lines, -8, 7))];
            }
            // dead-man status for the Watch column (monitor mails on overdue)
            $c['watch'] = null;
            if (!empty($c['expect_min'])) {
                $c['watch'] = 'never';
                if (!empty($c['last']['end']) && preg_match('/(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})/', $c['last']['end'], $m) && ($t = strtotime($m[1]))) {
                    $c['watch'] = (time() - $t > ((int)$c['expect_min'] + (int)($c['grace_min'] ?? 15)) * 60) ? 'overdue' : 'ok';
                }
            }
        }
        unset($c);
        // admins also see the box-level schedule (read-only — installer-owned,
        // so users can tell "the system runs X" apart from their own jobs)
        $syscrons = [];
        if (($u['role'] ?? '') === 'admin' && is_readable('/etc/cron.d/skypanel')) {
            foreach (file('/etc/cron.d/skypanel', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) continue;
                if (preg_match('/^(\S+\s+\S+\s+\S+\s+\S+\s+\S+)\s+root\s+(.+)$/', $line, $m)) {
                    $syscrons[] = ['schedule' => $m[1], 'command' => $m[2]];
                }
            }
        }
        $this->view('cron', compact('crons','pg','pages','total','syscrons'));
    }
    // Dead-man expectation: job must finish at least every N min (+grace).
    // Empty expectation = unwatched. Checked by cron-monitor.sh every 5 min.
    public function expect(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/cron'); }
        $u = Auth::user();
        $id = (int)($_POST['id'] ?? 0);
        $row = Database::query("SELECT * FROM cron_jobs WHERE id=? AND user_id=?", [$id, $u['id']])->fetch();
        if (!$row) { Helpers::flash('err','Job not found'); Helpers::redirect('/cron'); }
        $raw = trim((string)($_POST['expect_min'] ?? ''));
        if ($raw === '') {
            Database::query("UPDATE cron_jobs SET expect_min=NULL WHERE id=?", [$id]);
            Helpers::flash('ok','Watch removed'); Helpers::redirect('/cron');
        }
        if (!preg_match('/^[0-9]{1,6}$/', $raw) || (int)$raw < 5 || (int)$raw > 525600) { Helpers::flash('err','Expect every 5..525600 min (or blank to unwatch)'); Helpers::redirect('/cron'); }
        $grace = (int)($_POST['grace_min'] ?? 15);
        if ($grace < 0 || $grace > 1440) { Helpers::flash('err','Grace 0..1440 min'); Helpers::redirect('/cron'); }
        Database::query("UPDATE cron_jobs SET expect_min=?, grace_min=? WHERE id=?", [(int)$raw, $grace, $id]);
        Helpers::flash('ok',"Job watched: must finish every $raw min (+$grace grace)");
        Helpers::redirect('/cron');
    }
    public function create(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/cron'); }
        $u = Auth::user();
        $schedule = trim($_POST['schedule']??''); $cmd = trim($_POST['command']??'');
        if (!preg_match('/^([\*\d,\-\/]+)\s+([\*\d,\-\/]+)\s+([\*\d,\-\/]+)\s+([\*\d,\-\/]+)\s+([\*\d,\-\/]+)$/',$schedule) || $cmd==='') {
            Helpers::flash('err','Bad cron expression or empty command'); Helpers::redirect('/cron');
        }
        Database::query("INSERT INTO cron_jobs(user_id,schedule,command) VALUES(?,?,?)", [$u['id'],$schedule,$cmd]);
        ServiceManager::run('cron-sync.sh', [$u['username']]);
        // bare `php` is the box's CLI default (pinned to PhpVersions::DEFAULT
        // by host-converge, but an admin may move it) — nudge toward a pinned
        // version, but don't block (some scripts genuinely don't care).
        if (preg_match('/(^|[;\s&|]+)php(\s|$)/', $cmd) && !preg_match('/php8\.[0-9]/', $cmd)) {
            Helpers::flash('ok','Cron added & synced — note: bare `php` follows the server\'s default CLI version. Pin one (php'.PhpVersions::DEFAULT.', php'.implode(', php', array_slice(PhpVersions::selectable(), 1)).') if the app cares.');
        } else Helpers::flash('ok','Cron added & synced');
        Helpers::redirect('/cron');
    }
    public function delete(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/cron'); }
        $u = Auth::user();
        Database::query("DELETE FROM cron_jobs WHERE id=? AND user_id=?", [(int)($_POST['id']??0), $u['id']]);
        ServiceManager::run('cron-sync.sh', [$u['username']]);
        Helpers::flash('ok','Cron deleted'); Helpers::redirect('/cron');
    }
}
