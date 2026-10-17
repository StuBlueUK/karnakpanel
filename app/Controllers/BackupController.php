<?php
declare(strict_types=1);
class BackupController extends Controller {
    public function list(): void {
        Auth::requireLogin();
        $u = Auth::user();
        if ($u['role'] === 'admin') {
            $p = Helpers::paginate((int)Database::query("SELECT COUNT(*) c FROM backups")->fetch()['c'], max(1, (int)($_GET['page'] ?? 1)));
            $pg = $p['pg']; $pages = $p['pages']; $total = $p['total'];
            $backups = Database::query("SELECT b.*, u.username AS owner FROM backups b JOIN users u ON u.id=b.user_id ORDER BY b.id DESC LIMIT ? OFFSET ?", [(int)$p['limit'], (int)$p['offset']])->fetchAll();
        } else {
            $p = Helpers::paginate((int)Database::query("SELECT COUNT(*) c FROM backups WHERE user_id=?", [$u['id']])->fetch()['c'], max(1, (int)($_GET['page'] ?? 1)));
            $pg = $p['pg']; $pages = $p['pages']; $total = $p['total'];
            $backups = Database::query("SELECT * FROM backups WHERE user_id=? ORDER BY id DESC LIMIT ? OFFSET ?", [$u['id'], (int)$p['limit'], (int)$p['offset']])->fetchAll();
        }
        $emails = Database::query("SELECT e.*, d.domain FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE e.user_id=?", [$u['id']])->fetchAll();
        $dbs = Database::query("SELECT * FROM mysql_dbs WHERE user_id=?", [$u['id']])->fetchAll();
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
                $seen = [];
                foreach ($dbs as $dd) {
                    if (!preg_match('/^[a-z][a-z0-9_]{2,63}$/', $dd['db_name'] ?? '')) continue;
                    $r = ServiceManager::run('backup-dumps.sh', [$dd['db_name']]);
                    if (($r['code'] ?? 1) !== 0) continue;
                    foreach (explode("\n", $r['out'] ?? '') as $df) {
                        $df = trim($df);
                        if ($df !== '' && preg_match('/^db-[a-zA-Z0-9_.-]+\.sql\.gz$/', $df)) $seen[$df] = true;
                    }
                }
                $dumps = array_keys($seen);
                rsort($dumps);
            }
            $s = ServiceManager::run('inc-list.sh', [$u['username']]);
            if (($s['code'] ?? 1) === 0) $snaps = array_filter(array_map('trim', explode("\n", $s['out'] ?? '')));
        } catch (Throwable $e) {}
        $keyOn = false;
        try { $keyOn = (bool)Database::query("SELECT backup_pass FROM users WHERE id=?", [$u['id']])->fetch()['backup_pass']; } catch (Throwable $e) {}
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
            $resticOn = Database::query("SELECT value FROM settings WHERE name='restic_enabled'")->fetch()['value'] === '1';
        } catch (Throwable $e) {}
        if ($resticOn) {
            try {
                $arg = (($u['role'] ?? '') === 'admin') ? [] : [$u['username']];
                if (($u['role'] ?? '') !== 'admin' && !preg_match('/^[a-z][a-z0-9_]{2,31}$/', $u['username'])) $arg = ['__invalid__'];
                $r = ServiceManager::run('restic-ls.sh', $arg);
                if (($r['code'] ?? 1) === 0) {
                    foreach (json_decode($r['out'] ?? '[]', true) ?: [] as $s) {
                        if (!is_array($s) || !isset($s['id'], $s['time'])) continue;
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
        $this->view('backups', compact('backups','emails','dbs','dumps','snaps','pg','pages','total','keyOn','off','sched','resticOn','rsnaps'));
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
                Database::query("UPDATE users SET backup_pass=? WHERE id=?", [$key, $u['id']]);
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
        $r = ServiceManager::run('backup-account.sh', [$u['username']]);
        // filename comes from the script's own output (never guessed — clock skew breaks guesses)
        $fn = basename(trim($r['out'] ?? ''));
        if (!preg_match('/^[a-zA-Z0-9_.-]+\.tar\.gz$/', $fn)) $fn = $u['username'].'-'.date('Ymd-His').'.tar.gz';
        // the script registers its own row; insert here only if it didn't
        // (belt-and-braces for older script versions still on the box)
        try { Database::query("INSERT INTO backups(user_id,filename,size_bytes) SELECT ?,?,? FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM backups WHERE user_id=? AND filename=?)", [$u['id'],$fn,0,$u['id'],$fn]); } catch (Throwable $e) {}
        Helpers::flash('ok','Backup queued: '.$r['out']);
        Helpers::redirect('/backups');
    }
    public function download(): void {
        Auth::requireLogin();
        $u = Auth::user();
        $id = (int)($_GET['id'] ?? 0);
        $sql = ($u['role'] === 'admin')
            ? "SELECT * FROM backups WHERE id=?"
            : "SELECT * FROM backups WHERE id=? AND user_id=?";
        $args = ($u['role'] === 'admin') ? [$id] : [$id, $u['id']];
        $b = Database::query($sql, $args)->fetch();
        if (!$b) { http_response_code(404); echo 'Backup not found'; exit; }
        ServiceManager::streamDownload('backup-get.sh', $b['filename'], $b['filename']);
    }
        // fetch one backup row with ownership enforced (admin sees all, users their own)
    private function ownBackup(array $u, int $id): ?array {
        $sql = ($u['role'] === 'admin')
            ? "SELECT b.*, u.username AS owner FROM backups b JOIN users u ON u.id=b.user_id WHERE b.id=?"
            : "SELECT b.*, u.username AS owner FROM backups b JOIN users u ON u.id=b.user_id WHERE b.id=? AND b.user_id=?";
        $args = ($u['role'] === 'admin') ? [$id] : [$id, $u['id']];
        $b = Database::query($sql, $args)->fetch();
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
    // restic engine: toggle, snapshot-scoped restores. Snapshot ids are
    // always re-resolved to their account tag here (never trust the form).
    private function snapAccount(string $id): ?string {
        if (!preg_match('/^[0-9a-f]{7,64}$/', $id)) return null;
        try {
            $r = ServiceManager::run('restic-ls.sh', []);
            if (($r['code'] ?? 1) !== 0) return null;
            foreach (json_decode($r['out'] ?? '[]', true) ?: [] as $s) {
                if (!is_array($s) || substr((string)($s['id'] ?? ''), 0, strlen($id)) !== $id) continue;
                foreach ((array)($s['tags'] ?? []) as $t) {
                    if (str_starts_with((string)$t, 'account=')) return substr((string)$t, 8);
                }
            }
        } catch (Throwable $e) {}
        return null;
    }
    public function engine(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/backups'); }
        $on = (string)($_POST['engine'] ?? '') === '1' ? '1' : '0';
        try {
            Database::query("INSERT INTO settings(name,value) VALUES('restic_enabled',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [$on]);
        } catch (Throwable $e) {
            try {
                Database::query("CREATE TABLE IF NOT EXISTS settings (name VARCHAR(190) PRIMARY KEY, value TEXT NOT NULL)");
                Database::query("INSERT INTO settings(name,value) VALUES('restic_enabled',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [$on]);
            } catch (Throwable $e2) { Helpers::flash('err','Save failed'); Helpers::redirect('/backups'); }
        }
        if ($on === '1') {
            // converge binary + repo + cron now (idempotent; may need a
            // second click on slow links — the flash says what happened).
            $r = ServiceManager::run('restic-install.sh', []);
            Helpers::log("restic engine enabled by ".Auth::user()['username']);
            Helpers::flash(($r['code'] ?? 1) === 0 ? 'ok' : 'err', $on === '1' ? trim($r['out'] ?? '') : 'Engine off');
        } else {
            Helpers::log("restic engine disabled by ".Auth::user()['username']);
            Helpers::flash('ok','Restic engine off — nightly tarballs resume. Snapshots stay put.');
        }
        Helpers::redirect('/backups');
    }
    public function rdb(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/backups'); }
        $u = Auth::user();
        $db = Database::query("SELECT * FROM mysql_dbs WHERE id=? AND user_id=?", [(int)($_POST['db_id'] ?? 0), $u['id']])->fetch();
        $snap = strtolower(trim((string)($_POST['snapshot'] ?? '')));
        if (!$db || ($acc = $this->snapAccount($snap)) === null) { Helpers::flash('err','Bad database/snapshot'); Helpers::redirect('/backups'); }
        if (($u['role'] ?? '') !== 'admin' && $acc !== $u['username']) { Helpers::flash('err','Not your snapshot'); Helpers::redirect('/backups'); }
        if (!preg_match('/^[a-z][a-z0-9_]{2,63}$/', $db['db_name'] ?? '')) { Helpers::flash('err','Bad database'); Helpers::redirect('/backups'); }
        $r = ServiceManager::run('restic-db.sh', [$acc, $snap, $db['db_name']]);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','DB restore failed: '.trim($r['out']));
        else Helpers::flash('ok', trim($r['out'])." — data replaced, users/passwords untouched");
        Helpers::redirect('/backups');
    }
    public function rfile(): void {
        Auth::requireLogin();
        $u = Auth::user();
        $snap = strtolower(trim((string)($_GET['snapshot'] ?? '')));
        $path = '/'.ltrim(trim((string)($_GET['path'] ?? '')), '/');
        if (($acc = $this->snapAccount($snap)) === null) { Helpers::flash('err','Bad snapshot'); Helpers::redirect('/backups'); }
        if (($u['role'] ?? '') !== 'admin' && $acc !== $u['username']) { Helpers::flash('err','Not your snapshot'); Helpers::redirect('/backups'); }
        // path must live under the snapshot owner's home or their domains —
        // restic-file.sh re-verifies ownership itself (defense in depth).
        if (!preg_match('#^/(home/[a-z][a-z0-9_]{2,31}/|var/mail/virtual/[a-z0-9.-]+\.[a-z]{2}/)[a-zA-Z0-9_./-]{1,220}$#', $path) || str_contains($path, '..')) {
            Helpers::flash('err','Bad path'); Helpers::redirect('/backups');
        }
        $r = ServiceManager::run('restic-file.sh', [$acc, $snap, $path]);
        if (($r['code'] ?? 1) !== 0) { Helpers::flash('err','Fetch failed: '.trim($r['out'])); Helpers::redirect('/backups'); }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.str_replace('"', '', basename($path)).'"');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: '.strlen($r['out'] ?? ''));
        // nosemgrep: php.lang.security.injection.echoed-request.echoed-request, php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag -- attachment download with nosniff (bytes from owned snapshot, never HTML)
        echo $r['out'];
        exit;
    }
    public function resticOffsiteSync(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/backups'); }
        $r = ServiceManager::run('restic-offsite.sh', []);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Restic offsite failed: '.trim($r['out']));
        else Helpers::flash('ok', trim($r['out']));
        Helpers::redirect('/backups');
    }
    public function restoreFile(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/backups'); }
        $u = Auth::user();
        $b = $this->ownBackup($u, (int)($_POST['id'] ?? 0));
        $member = trim((string)($_POST['member'] ?? ''));
        if (!$b || !preg_match('#^home/[a-z][a-z0-9_]{2,31}/[a-zA-Z0-9_./-]{1,220}$#', $member) || str_contains($member, '..')) {
            Helpers::flash('err','Bad backup/file'); Helpers::redirect('/backups');
        }
        // restore into the OWNER's tree (admin restoring for someone else)
        $r = ServiceManager::run('file-restore.sh', [$b['owner'], $b['filename'], $member]);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','File restore failed: '.trim($r['out']));
        else Helpers::flash('ok', trim($r['out']));
        Helpers::redirect('/backups/browse?id='.$b['id']);
    }
    public function restoreMailbox(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/backups'); }
        $u = Auth::user();
        $b = $this->ownBackup($u, (int)($_POST['backup_id'] ?? 0));
        $mid = (int)($_POST['mailbox_id'] ?? 0);
        $m = Database::query("SELECT e.*, d.domain FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE e.id=? AND e.user_id=?", [$mid, $u['id']])->fetch();
        if (!$b || !$m) { Helpers::flash('err','Bad backup/mailbox'); Helpers::redirect('/backups'); }
        $r = ServiceManager::run('mailbox-restore.sh', [$m['domain'], $m['localpart'], $b['filename']]);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Mailbox restore failed: '.trim($r['out']));
        else Helpers::flash('ok', trim($r['out']));
        Helpers::redirect('/backups');
    }
    public function restoreDb(): void {        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/backups'); }
        $u = Auth::user();
        $db = Database::query("SELECT * FROM mysql_dbs WHERE id=? AND user_id=?", [(int)($_POST['db_id'] ?? 0), $u['id']])->fetch();
        $dump = (string)($_POST['dump'] ?? '');
        if (!$db || !preg_match('/^db-[a-zA-Z0-9_.-]+\.sql\.gz$/', $dump)) { Helpers::flash('err','Bad database/dump'); Helpers::redirect('/backups'); }
        // IDOR gate: dumps live in one global store and the dropdown is only
        // display-filtered — re-derive the user's own set and refuse anything
        // outside it (guessing another tenant's filename must not restore).
        // (Exploitation is additionally blocked by username-namespaced db
        // names + db-restore.sh's section check, but the request itself
        // should never name a foreign object.)
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- dump membership-checked against user's own set below, then namespaced section restore
        if (($u['role'] ?? '') !== 'admin') {
            $mine = [];
            foreach (Database::query("SELECT db_name FROM mysql_dbs WHERE user_id=?", [$u['id']])->fetchAll() as $dd) {
                if (!preg_match('/^[a-z][a-z0-9_]{2,63}$/', $dd['db_name'] ?? '')) continue;
                $rr = ServiceManager::run('backup-dumps.sh', [$dd['db_name']]);
                if (($rr['code'] ?? 1) !== 0) continue;
                foreach (explode("\n", $rr['out'] ?? '') as $df) {
                    $df = trim($df);
                    if ($df !== '' && preg_match('/^db-[a-zA-Z0-9_.-]+\.sql\.gz$/', $df)) $mine[$df] = true;
                }
            }
            if (!isset($mine[$dump])) { Helpers::flash('err','Not your dump'); Helpers::redirect('/backups'); }
        }
        $r = ServiceManager::run('db-restore.sh', [$db['db_name'], $dump]);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','DB restore failed: '.trim($r['out']));
        else Helpers::flash('ok', trim($r['out'])." — data replaced, users/passwords untouched");
        Helpers::redirect('/backups');
    }
    public function restoreInc(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/backups'); }
        $u = Auth::user();
        $date = (string)($_POST['date'] ?? '');
        $rel = trim((string)($_POST['relpath'] ?? ''));
        if (!preg_match('/^[0-9]{8}$/', $date) || !preg_match('#^(home/[a-z][a-z0-9_]{2,31}/|var/mail/virtual/[a-z0-9.-]+\.[a-z]{2,}/)[a-zA-Z0-9_./-]{1,220}$#', $rel) || str_contains($rel, '..')) {
            Helpers::flash('err','Bad snapshot/path'); Helpers::redirect('/backups');
        }
        if (str_starts_with($rel, 'home/') && !str_starts_with($rel, 'home/'.$u['username'].'/')) {
            Helpers::flash('err','Not your file'); Helpers::redirect('/backups');
        }
        if (str_starts_with($rel, 'var/mail/virtual/')) {
            $dom = explode('/', $rel)[2] ?? '';
            $mine = Database::query("SELECT 1 FROM domains WHERE domain=? AND user_id=?", [$dom, $u['id']])->fetch();
            if (!$mine && $u['role'] !== 'admin') { Helpers::flash('err','Not your mail'); Helpers::redirect('/backups'); }
        }
        $r = ServiceManager::run('inc-restore.sh', [$u['username'], $date, $rel]);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Restore failed: '.trim($r['out']));
        else Helpers::flash('ok', trim($r['out']));
        Helpers::redirect('/backups');
    }
    public function restore(): void {        Auth::requireLogin();        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/backups'); }
        $u = Auth::user();
        $id = (int)($_POST['id'] ?? 0);
        $sql = ($u['role'] === 'admin')
            ? "SELECT * FROM backups WHERE id=?"
            : "SELECT * FROM backups WHERE id=? AND user_id=?";
        $args = ($u['role'] === 'admin') ? [$id] : [$id, $u['id']];
        $b = Database::query($sql, $args)->fetch();
        if (!$b) { Helpers::flash('err','Backup not found'); Helpers::redirect('/backups'); }
        if (!preg_match('/^[a-zA-Z0-9_.-]+\.tar\.gz$/', $b['filename']) || str_contains($b['filename'], '/')) {
            Helpers::flash('err','Bad backup filename'); Helpers::redirect('/backups');
        }
        // restore into the OWNER's home, never the requester's (admin restoring for a user)
        $owner = Database::query("SELECT username FROM users WHERE id=?", [$b['user_id']])->fetch()['username'] ?? '';
        if (!Helpers::validUsername($owner)) { Helpers::flash('err','Owner account invalid'); Helpers::redirect('/backups'); }
        $r = ServiceManager::run('restore-account.sh', [$owner, $b['filename']]);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err', 'Restore failed: '.trim($r['out']));
        else Helpers::flash('ok', "Restored {$b['filename']} (sites + mail). ".trim($r['out']));
        Helpers::redirect('/backups');
    }
    // Offsite (admin, box-level): encrypted rclone mirror of /backup/skypanel.
    // Secrets travel POST -> script args -> root-only rclone.conf, never the DB.
    public function offsite(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/backups'); }
        $prov = (string)($_POST['provider'] ?? '');
        $args = ['provider='.$prov];
        if ($prov === 's3') {
            $preset = (string)($_POST['preset'] ?? '');
            $bucket = strtolower(trim((string)($_POST['bucket'] ?? '')));
            $endpoint = trim((string)($_POST['endpoint'] ?? ''));
            $region = trim((string)($_POST['region'] ?? ''));
            if (!in_array($preset, ['AWS','Backblaze','Wasabi','Hetzner','Cloudflare','Other'], true)
                || !preg_match('/^[a-z0-9.-]{3,63}$/', $bucket)
                || ($endpoint !== '' && !preg_match('#^https?://[a-zA-Z0-9.-]+(:[0-9]{1,5})?/?$#', $endpoint))
                || !preg_match('/^[a-zA-Z0-9-]{0,32}$/', $region)
                || strlen((string)($_POST['key'] ?? '')) < 4 || strlen((string)($_POST['secret'] ?? '')) < 4) {
                Helpers::flash('err','Bad S3 details (preset, bucket, endpoint URL, key, secret)'); Helpers::redirect('/backups');
            }
            $args[] = 'preset='.$preset; $args[] = 'bucket='.$bucket;
            if ($endpoint !== '') $args[] = 'endpoint='.$endpoint;
            if ($region !== '') $args[] = 'region='.$region;
            $args[] = 'key='.(string)$_POST['key']; $args[] = 'secret='.(string)$_POST['secret'];
        } elseif ($prov === 'sftp') {
            $host = trim((string)($_POST['host'] ?? ''));
            $port = trim((string)($_POST['port'] ?? '22'));
            $user = trim((string)($_POST['user'] ?? ''));
            $path = trim((string)($_POST['path'] ?? '/backups'));
            if (!preg_match('/^[a-zA-Z0-9.-]{1,253}$/', $host) || !preg_match('/^[0-9]{1,5}$/', $port)
                || !preg_match('/^[a-zA-Z0-9_.-]{1,64}$/', $user) || !preg_match('#^/[a-zA-Z0-9_./-]{0,120}$#', $path)
                || strlen((string)($_POST['password'] ?? '')) < 1) {
                Helpers::flash('err','Bad SFTP details (host, port, user, password, path)'); Helpers::redirect('/backups');
            }
            $args[] = 'host='.$host; $args[] = 'port='.$port; $args[] = 'user='.$user;
            $args[] = 'password='.(string)$_POST['password']; $args[] = 'path='.$path;
        } elseif ($prov === 'local') {
            $path = trim((string)($_POST['path'] ?? ''));
            if (!preg_match('#^/[a-zA-Z0-9_./-]{1,120}$#', $path)) { Helpers::flash('err','Bad path (absolute, please)'); Helpers::redirect('/backups'); }
            $args[] = 'path='.$path;
        } else { Helpers::flash('err','Pick a provider'); Helpers::redirect('/backups'); }
        $r = ServiceManager::run('offsite-setup.sh', $args);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Offsite setup failed: '.trim($r['out']));
        else { Helpers::log("offsite configured ($prov) by ".Auth::user()['username']); Helpers::flash('ok', trim($r['out'])); }
        Helpers::redirect('/backups');
    }
    public function offsiteSync(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/backups'); }
        $r = ServiceManager::run('offsite-sync.sh', []);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Sync failed: '.trim($r['out']));
        else Helpers::flash('ok', trim($r['out']).' — see Logs → offsite');
        Helpers::redirect('/backups');
    }
    // Fetch one file back from offsite into /backup/skypanel/ and register it,
    // so download/browse/restore work unchanged. Owner parsed from the
    // panel filename scheme (usernames can't contain dashes).
    public function offsiteFetch(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/backups'); }
        $fn = (string)($_POST['filename'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_.-]+\.tar\.gz$/', $fn) || str_contains($fn, '/')) {
            Helpers::flash('err','Bad filename'); Helpers::redirect('/backups');
        }
        $r = ServiceManager::run('offsite-fetch.sh', [$fn]);
        if (($r['code'] ?? 1) !== 0) { Helpers::flash('err','Fetch failed: '.trim($r['out'])); Helpers::redirect('/backups'); }
        $owner = explode('-', $fn)[0];
        $uid = Database::query("SELECT id FROM users WHERE username=?", [$owner])->fetch()['id'] ?? null;
        if ($uid) {
            try { Database::query("INSERT INTO backups(user_id,filename,size_bytes) VALUES(?,?,?)", [$uid, $fn, 0]); } catch (Throwable $e) {}
        }
        Helpers::log("offsite fetched $fn by ".Auth::user()['username']);
        Helpers::flash('ok', trim($r['out']).($uid ? ' — registered for download/restore' : ' — owner unknown, file is on disk'));
        Helpers::redirect('/backups');
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
    public function offsiteDisable(): void {        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/backups'); }
        $r = ServiceManager::run('offsite-disable.sh', []);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Disable failed: '.trim($r['out']));
        else { Helpers::log("offsite disabled by ".Auth::user()['username']); Helpers::flash('ok', trim($r['out'])); }
        Helpers::redirect('/backups');
    }
}
