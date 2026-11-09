<?php
declare(strict_types=1);
// BackupOffsiteController — inherits the backup lookup/helpers from BackupController.
class BackupOffsiteController extends BackupController {
    // Offsite (admin, box-level): encrypted rclone mirror of /backup/skypanel.
    // Secrets travel POST -> Secret (stdin) -> root-only rclone.conf, never
    // argv/logs/proc and never the DB.
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
            $key = (string)($_POST['key'] ?? '');
            $secret = (string)($_POST['secret'] ?? '');
            // charset gate: these are written verbatim into root's rclone.conf
            // (a newline would inject INI stanzas), and the script re-checks.
            if (!in_array($preset, ['AWS','Backblaze','Wasabi','Hetzner','Cloudflare','Other'], true)
                || !preg_match('/^[a-z0-9.-]{3,63}$/', $bucket)
                || ($endpoint !== '' && !preg_match('#^https?://[a-zA-Z0-9.-]+(:[0-9]{1,5})?/?$#', $endpoint))
                || !preg_match('/^[a-zA-Z0-9-]{0,32}$/', $region)
                || !preg_match('/^[A-Za-z0-9+\/=_.,-]{4,512}$/', $key)
                || !preg_match('/^[A-Za-z0-9+\/=_.,-]{4,512}$/', $secret)) {
                Helpers::flash('err','Bad S3 details (preset, bucket, endpoint URL, key, secret)'); Helpers::redirect('/backups');
            }
            $args[] = 'preset='.$preset; $args[] = 'bucket='.$bucket;
            if ($endpoint !== '') $args[] = 'endpoint='.$endpoint;
            if ($region !== '') $args[] = 'region='.$region;
            // secrets go on the script's stdin (Secret), never argv/logs/proc;
            // '--' terminates the k=v option block so a value can't be misparsed
            $args[] = '--'; $args[] = new Secret($key); $args[] = new Secret($secret);
        } elseif ($prov === 'sftp') {
            $host = trim((string)($_POST['host'] ?? ''));
            $port = trim((string)($_POST['port'] ?? '22'));
            $user = trim((string)($_POST['user'] ?? ''));
            $path = trim((string)($_POST['path'] ?? '/backups'));
            $passwd = (string)($_POST['password'] ?? '');
            if (!preg_match('/^[a-zA-Z0-9.-]{1,253}$/', $host) || !preg_match('/^[0-9]{1,5}$/', $port)
                || !preg_match('/^[a-zA-Z0-9_.-]{1,64}$/', $user) || !preg_match('#^/[a-zA-Z0-9_./-]{0,120}$#', $path)
                // no CR/LF/NUL: rclone obscure reads a single line from stdin
                || !preg_match('/^[^\r\n\0]{1,512}$/', $passwd)) {
                Helpers::flash('err','Bad SFTP details (host, port, user, password, path)'); Helpers::redirect('/backups');
            }
            $args[] = 'host='.$host; $args[] = 'port='.$port; $args[] = 'user='.$user;
            $args[] = 'path='.$path;
            // password on stdin (Secret) — never argv/logs/proc ('--' ends options)
            $args[] = '--'; $args[] = new Secret($passwd);
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
        $ownerRow = User::findByName($owner);
        $uid = $ownerRow === null ? null : (int)$ownerRow['id'];
        if ($uid) {
            try { BackupModel::insertRemote($uid, $fn); } catch (Throwable $e) {}
        }
        Helpers::log("offsite fetched $fn by ".Auth::user()['username']);
        Helpers::flash('ok', trim($r['out']).($uid ? ' — registered for download/restore' : ' — owner unknown, file is on disk'));
        Helpers::redirect('/backups');
    }
    public function offsiteDisable(): void {        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/backups'); }
        $r = ServiceManager::run('offsite-disable.sh', []);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Disable failed: '.trim($r['out']));
        else { Helpers::log("offsite disabled by ".Auth::user()['username']); Helpers::flash('ok', trim($r['out'])); }
        Helpers::redirect('/backups');
    }
    public function resticOffsiteSync(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/backups'); }
        $r = ServiceManager::run('restic-offsite.sh', []);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Restic offsite failed: '.trim($r['out']));
        else Helpers::flash('ok', trim($r['out']));
        Helpers::redirect('/backups');
    }
    public function engine(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/backups'); }
        $on = (string)($_POST['engine'] ?? '') === '1' ? '1' : '0';
        try {
            Settings::put('restic_enabled', $on);
        } catch (Throwable $e) {
            try {
                Settings::ensureTable();
                Settings::put('restic_enabled', $on);
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
}
