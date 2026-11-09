<?php
declare(strict_types=1);
class BackupController extends Controller {
    public function list(): void {
        Auth::requireLogin();
        $u = Auth::user();
        if ($u['role'] === 'admin') {
            $p = Helpers::paginate(BackupModel::countAll(), max(1, (int)($_GET['page'] ?? 1)));
            $pg = $p['pg']; $pages = $p['pages']; $total = $p['total'];
            $backups = BackupModel::pageAll((int)$p['limit'], (int)$p['offset']);
        } else {
            $p = Helpers::paginate(BackupModel::countForUser((int)$u['id']), max(1, (int)($_GET['page'] ?? 1)));
            $pg = $p['pg']; $pages = $p['pages']; $total = $p['total'];
            $backups = BackupModel::pageForUser((int)$u['id'], (int)$p['limit'], (int)$p['offset']);
        }
        $emails = EmailModel::allForUser((int)$u['id']);
        $dbs = BackupModel::dbsForUser((int)$u['id']);
        $dumps = [];
        $snaps = [];
        try {
            if (($u['role'] ?? '') === 'admin') {
                $r = ServiceManager::run('backup-dumps.sh', []);
                if (($r['code'] ?? 1) === 0) $dumps = array_filter(array_map('trim', explode("\n", $r['out'] ?? '')));
            } else {
                // per-user view: only dumps containing one of YOUR databases.
                // Global date-named files would otherwise enumerate (and, via
                // restore, expose stale sections of) other tenants' backups.
                $dumps = array_keys($this->ownDumps((int)$u['id']));
                rsort($dumps);
            }
            $s = ServiceManager::run('inc-list.sh', [$u['username']]);
            if (($s['code'] ?? 1) === 0) $snaps = array_filter(array_map('trim', explode("\n", $s['out'] ?? '')));
        } catch (Throwable $e) {}
        $keyOn = false;
        try { $keyOn = BackupModel::hasPass((int)$u['id']); } catch (Throwable $e) {}
        // nightly schedule state for the admin card (status=HH:MM|disabled)
        $sched = '';
        if (($u['role'] ?? '') === 'admin') {
            try {
                $r = ServiceManager::run('backup-schedule.sh', ['status']);
                if (($r['code'] ?? 1) === 0 && preg_match('/^status=(.+)$/', trim($r['out'] ?? ''), $m)) $sched = $m[1];
            } catch (Throwable $e) {}
        }
        // offsite (box-level, admin): rclone remote behind a crypt wrapper
        $off = ['configured' => false, 'provider' => '', 'target' => '', 'last' => '', 'count' => '', 'size' => ''];
        try {
            $r = ServiceManager::run('offsite-status.sh', []);
            if (($r['code'] ?? 1) === 0) {
                $p = explode("\t", trim($r['out'] ?? ''));
                if (($p[0] ?? '') === 'yes') {
                    $off = ['configured' => true, 'provider' => $p[1] ?? '', 'target' => $p[2] ?? '',
                        'last' => $p[3] ?? '', 'count' => $p[4] ?? '', 'size' => $p[5] ?? ''];
                }
            }
        } catch (Throwable $e) {}
        // restic engine (incremental snapshots): flag + own-scope snapshot list.
        // Non-admins see only their account tag; restores re-verify below.
        $resticOn = false;
        $rsnaps = [];
        try {
            $resticOn = (string)(Settings::get('restic_enabled') ?? '') === '1';
        } catch (Throwable $e) {}
        if ($resticOn) {
            try {
                $arg = (($u['role'] ?? '') === 'admin') ? [] : [$u['username']];
                if (($u['role'] ?? '') !== 'admin' && !preg_match('/^[a-z][a-z0-9_]{2,31}$/', $u['username'])) $arg = ['__invalid__'];
                $r = ServiceManager::run('restic-ls.sh', $arg);
                if (($r['code'] ?? 1) === 0) {
                    foreach (json_decode($r['out'] ?? '[]', true) ?: [] as $s) {
                        if (!is_array($s) || !isset($s['id'], $s['time'])) continue;
                        if (($u['role'] ?? '') !== 'admin' && !$this->snapAfterAccount((string)$s['time'], (int)$u['id'])) continue;
                        $tags = is_array($s['tags'] ?? null) ? $s['tags'] : [];
                        $kind = in_array('kind=db', $tags, true) ? 'db' : 'files';
                        $acc = array_values(preg_grep('/^account=/', $tags));
                        $rsnaps[] = ['id' => substr((string)$s['id'], 0, 8), 'time' => (string)$s['time'],
                            'kind' => $kind, 'paths' => implode(',', array_slice((array)($s['paths'] ?? []), 0, 2)),
                            'account' => (string)preg_replace('/^account=/', '', $acc[0] ?? '')];
                        if (count($rsnaps) >= 200) break;
                    }
                }
            } catch (Throwable $e) {}
        }
        $running = false;
        try { $running = trim(ServiceManager::run('backup-bg.sh', ['status', $u['username']])['out'] ?? '') === 'running'; } catch (Throwable $e) {}
        $isAdmin = ($u['role'] ?? '') === 'admin';
        $usage = $this->storeUsage($isAdmin ? '' : $u['username']);
        // owners for the admin "older than" scope picker
        $owners = $isAdmin ? BackupModel::owners() : [];
        // delete preview (GET, read-only): ?del=sel&ids[]=… or ?del=older&days=N[&acct=]
        $delPreview = null;
        $mode = (string)($_GET['del'] ?? '');
        if ($mode === 'sel') {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_GET['ids'] ?? [])), fn($i) => $i > 0)));
            $delPreview = $ids ? $this->deletePlan($u, $this->rowsById($u, array_slice($ids, 0, 200))) : ['rows' => [], 'kept' => [], 'bytes' => 0, 'label' => 'selected'];
            $delPreview['label'] = 'selected';
        } elseif ($mode === 'older') {
            $days = (int)($_GET['days'] ?? 0);
            $acct = $isAdmin ? strtolower(trim((string)($_GET['acct'] ?? ''))) : '';
            if (in_array($days, self::OLDER_DAYS, true) && ($acct === '' || Helpers::validUsername($acct))) {
                $delPreview = $this->deletePlan($u, $this->rowsOlder($u, $days, $acct));
                $delPreview['label'] = "older than $days days".($acct !== '' ? " for $acct" : ($isAdmin ? ' (all accounts)' : ''));
            }
        }
        $this->view('backups', compact('backups','emails','dbs','dumps','snaps','pg','pages','total','keyOn','off','sched','resticOn','rsnaps','running','usage','owners','delPreview'));
    }
    private const OLDER_DAYS = [3, 7, 14, 30, 60, 90];
    // backup-store.sh usage → ['tarballs'=>[n,bytes], 'snapshots'=>[kb], …]
    protected function storeUsage(string $user): array {
        $out = [];
        try {
            $r = ServiceManager::run('backup-store.sh', $user === '' ? ['usage'] : ['usage', $user], 25);
            if (($r['code'] ?? 1) !== 0) return [];
            foreach (explode("\n", trim($r['out'] ?? '')) as $line) {
                $p = explode("\t", trim($line));
                if (in_array($p[0], ['tarballs','snapshots','dumps','restic','free'], true)) {
                    $out[$p[0]] = array_map('intval', array_filter(array_slice($p, 1), 'is_numeric'));
                }
            }
        } catch (Throwable $e) {}
        return $out;
    }
    // Backup rows the requester owns (admin: any), with the owner's name.
    protected function rowsById(array $u, array $ids): array {
        return BackupModel::rowsById($ids, $u['role'] === 'admin' ? null : (int)$u['id']);
    }
    protected function rowsOlder(array $u, int $days, string $acct): array {
        return BackupModel::rowsOlder($days, $u['role'] === 'admin' ? null : (int)$u['id'], $acct);
    }
    // Which of $rows may go. Guards against accidents: each account's NEWEST
    // backup is always kept (a mis-click can never leave an account with
    // none), and only standard <owner>-YYYYMMDD-HHMMSS.tar.gz names are
    // deletable (the script refuses anything else as well).
    protected function deletePlan(array $u, array $rows): array {
        $plan = ['rows' => [], 'kept' => [], 'bytes' => 0];
        $newest = [];
        $owners = array_values(array_unique(array_map(fn($r) => (int)$r['user_id'], $rows)));
        foreach ($owners as $oid) {
            $newest[$oid] = BackupModel::newestId($oid);
        }
        foreach ($rows as $r) {
            $name = (string)$r['filename']; $owner = (string)$r['owner'];
            if ((int)$r['id'] === ($newest[(int)$r['user_id']] ?? 0)) { $plan['kept'][] = [$r, 'newest backup of '.$owner.' — always kept']; continue; }
            if (!Helpers::validUsername($owner) || !preg_match('/^'.preg_quote($owner, '/').'-[0-9]{8}-[0-9]{6}\.tar\.gz$/', $name)) { $plan['kept'][] = [$r, 'non-standard file name — remove by hand']; continue; }
            $plan['rows'][] = $r; $plan['bytes'] += (int)$r['size_bytes'];
        }
        return $plan;
    }
    // Delete backups: POST + CSRF + typed "DELETE" + ticked acknowledgement,
    // after the read-only preview above. The plan is recomputed here from the
    // ids (ownership, newest-kept, name rules) — the form is never trusted.
    public function delete(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/backups'); }
        $u = Auth::user();
        if (trim((string)($_POST['confirm'] ?? '')) !== 'DELETE' || ($_POST['ack'] ?? '') !== '1') {
            Helpers::flash('err','Nothing deleted — type DELETE and tick the box to confirm'); Helpers::redirect('/backups');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), fn($i) => $i > 0)));
        $plan = $this->deletePlan($u, $this->rowsById($u, array_slice($ids, 0, 200)));
        if (!$plan['rows']) { Helpers::flash('err','Nothing to delete (the newest backup of each account is always kept)'); Helpers::redirect('/backups'); }
        $byOwner = [];
        foreach ($plan['rows'] as $r) $byOwner[$r['owner']][] = $r;
        $done = 0; $freed = 0; $errs = [];
        foreach ($byOwner as $owner => $rows) {
            // a running backup of this account adds rows/files: not mid-run
            $st = trim(ServiceManager::run('backup-bg.sh', ['status', (string)$owner])['out'] ?? '');
            if ($st === 'running') { $errs[] = "$owner: backup running, skipped"; continue; }
            $r = ServiceManager::run('backup-store.sh', array_merge(['delete', (string)$owner], array_column($rows, 'filename')), 60);
            if (($r['code'] ?? 1) !== 0) { $errs[] = "$owner: ".trim((string)($r['out'] ?? '')); continue; }
            BackupModel::deleteMany(array_map(fn($x) => (int)$x['id'], $rows));
            $done += count($rows); $freed += array_sum(array_map(fn($x) => (int)$x['size_bytes'], $rows));
            Helpers::log("backups: {$u['username']} deleted ".count($rows)." backup(s) of $owner: ".implode(' ', array_column($rows, 'filename')));
        }
        if ($done) Helpers::flash($errs ? 'err' : 'ok', "Deleted $done backup(s), freed ".Helpers::fmtMb($freed / 1048576).($errs ? ' — '.implode('; ', $errs) : ''));
        else Helpers::flash('err', 'Nothing deleted: '.implode('; ', $errs));
        Helpers::redirect('/backups');
    }
    // Backup encryption key: generated server-side, shown ONCE (it must exist
    // on the box for nightly runs; downloads decrypt with the openssl command
    // in the UI). Enable encrypts existing backups in background; disable
    // decrypts everything back. No rotation (old key unknown after change).
    public function key(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/backups'); }
        $u = Auth::user();
        $mode = ($_POST['mode'] ?? '');
        if (!in_array($mode, ['enable','disable'], true)) { Helpers::flash('err','Bad mode'); Helpers::redirect('/backups'); }
        try {
            if ($mode === 'enable') {
                $key = bin2hex(random_bytes(32));
                BackupModel::setPass((int)$u['id'], $key);
                ServiceManager::run('backup-crypt.sh', ['encrypt-existing', $u['username']]);
                Helpers::flash('ok', "Encryption ON — WRITE THIS KEY DOWN NOW (shown once, never again): $key — without it your downloaded backups can never be decrypted. Existing backups encrypt in background");
            } else {
                $r = ServiceManager::run('backup-crypt.sh', ['disable', $u['username']]);
                if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Disable failed: '.trim($r['out']));
                else Helpers::flash('ok','Decryption running in background — key clears when files are clean');
            }
        } catch (Throwable $e) { Helpers::flash('err','Key failed: '.$e->getMessage()); }
        Helpers::redirect('/backups');
    }
    public function create(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/backups'); }
        $u = Auth::user();
        // background unit (backup-bg.sh): the tar outlives any request
        // timeout on a real account; backup-account.sh adds the row when done
        $r = ServiceManager::run('backup-bg.sh', ['start', $u['username']]);
        $out = trim($r['out'] ?? '');
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err', 'Backup not started: '.$out);
        elseif ($out === 'already running') Helpers::flash('ok', 'A backup of your account is already running — it shows in the list below when done.');
        else Helpers::flash('ok', 'Backup started — it shows in the list below when done (large accounts take a few minutes).');
        Helpers::redirect('/backups');
    }
    public function download(): void {
        Auth::requireLogin();
        $u = Auth::user();
        $id = (int)($_GET['id'] ?? 0);
        $b = BackupModel::byId($id, $u['role'] === 'admin' ? null : (int)$u['id']);
        if (!$b) { http_response_code(404); echo 'Backup not found'; exit; }
        ServiceManager::streamDownload('backup-get.sh', $b['filename'], $b['filename']);
    }
        // fetch one backup row with ownership enforced (admin sees all, users their own)
    protected function ownBackup(array $u, int $id): ?array {
        $b = BackupModel::withOwner($id, $u['role'] === 'admin' ? null : (int)$u['id']);
        if (!$b || !preg_match('/^[a-zA-Z0-9_.-]+\.tar\.gz$/', $b['filename']) || str_contains($b['filename'], '/')) return null;
        return $b;
    }
    public function browse(): void {
        Auth::requireLogin();
        $u = Auth::user();
        $b = $this->ownBackup($u, (int)($_GET['id'] ?? 0));
        if (!$b) { Helpers::flash('err','Backup not found'); Helpers::redirect('/backups'); }
        $q = trim((string)($_GET['q'] ?? ''));
        if (!preg_match('/^[a-zA-Z0-9_.\/-]{0,80}$/', $q)) $q = '';
        $members = [];
        $r = ServiceManager::run('backup-members.sh', [$b['filename'], $q]);
        if (($r['code'] ?? 1) === 0) $members = array_filter(array_map('trim', explode("\n", $r['out'] ?? '')));
        $this->view('backup_browse', ['b' => $b, 'members' => array_slice($members, 0, 300), 'q' => $q]);
    }
    // Backups outlive a deleted account and are matched by NAME (account tag,
    // db name): a tenant only gets ones taken after their own account / db row
    // existed, never a previous holder's of the same name. Admins see all.
    // Epoch seconds only: PHP runs UTC, the box (and dump names) local time.
    private array $created = [];
    protected function snapAfterAccount(string $time, int $uid): bool {
        if (!array_key_exists($uid, $this->created)) {
            $this->created[$uid] = BackupModel::accountCreated($uid);
        }
        $t = strtotime($time);
        return $t !== false && $this->created[$uid] !== null && $t >= $this->created[$uid];
    }
    // dump file => true, for dumps holding one of the user's dbs taken after
    // that db row was created
    protected function ownDumps(int $uid): array {
        $mine = [];
        foreach (BackupModel::dbAges($uid) as $dd) {
            if (!preg_match('/^[a-z][a-z0-9_]{2,63}$/', $dd['db_name'] ?? '') || $dd['c'] === null) continue;
            $r = ServiceManager::run('backup-dumps.sh', [$dd['db_name'], (string)(int)$dd['c']]);
            if (($r['code'] ?? 1) !== 0) continue;
            foreach (explode("\n", $r['out'] ?? '') as $df) {
                $df = trim($df);
                if ($df !== '' && preg_match('/^db-[a-zA-Z0-9_.-]+\.sql\.gz$/', $df)) $mine[$df] = true;
            }
        }
        return $mine;
    }
    // Nightly schedule (admin): change time, disable (confirm), re-enable.
    // Disabled = commented cron line; nothing deleted, one click restores.
    public function schedule(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/backups'); }
        $action = (string)($_POST['action'] ?? '');
        $arg = '';
        if ($action === 'set') {
            $t = trim((string)($_POST['time'] ?? ''));
            if (!preg_match('/^([0-9]{1,2}):([0-9]{2})$/', $t, $m) || (int)$m[1] > 23 || (int)$m[2] > 59) {
                Helpers::flash('err','Bad time (HH:MM, 24h)'); Helpers::redirect('/backups');
            }
            $arg = sprintf('%d:%02d', (int)$m[1], (int)$m[2]);
        } elseif ($action === 'disable' || $action === 'enable') {
            $arg = $action;
        } else { Helpers::flash('err','Bad action'); Helpers::redirect('/backups'); }
        $r = ServiceManager::run('backup-schedule.sh', [$arg]);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Schedule failed: '.trim($r['out']));
        else { Helpers::log("backup schedule $arg by ".Auth::user()['username']); Helpers::flash('ok', trim($r['out'])); }
        Helpers::redirect('/backups');
    }
}
