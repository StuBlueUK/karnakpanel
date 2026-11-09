<?php
declare(strict_types=1);
// BackupRestoreController — inherits the backup lookup/helpers from BackupController.
class BackupRestoreController extends BackupController {
    public function restore(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/backups'); }
        $u = Auth::user();
        $id = (int)($_POST['id'] ?? 0);
        $b = BackupModel::byId($id, $u['role'] === 'admin' ? null : (int)$u['id']);
        if (!$b) { Helpers::flash('err','Backup not found'); Helpers::redirect('/backups'); }
        if (!preg_match('/^[a-zA-Z0-9_.-]+\.tar\.gz$/', $b['filename']) || str_contains($b['filename'], '/')) {
            Helpers::flash('err','Bad backup filename'); Helpers::redirect('/backups');
        }
        // restore into the OWNER's home, never the requester's (admin restoring for a user)
        $owner = User::username((int)$b['user_id']);
        if (!Helpers::validUsername($owner)) { Helpers::flash('err','Owner account invalid'); Helpers::redirect('/backups'); }
        $r = ServiceManager::run('restore-account.sh', [$owner, $b['filename']]);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err', 'Restore failed: '.trim($r['out']));
        else Helpers::flash('ok', "Restored {$b['filename']} (sites + mail). ".trim($r['out']));
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
        $m = EmailModel::mailboxForOwner((int)$u['id'], $mid);
        if (!$b || !$m) { Helpers::flash('err','Bad backup/mailbox'); Helpers::redirect('/backups'); }
        $r = ServiceManager::run('mailbox-restore.sh', [$m['domain'], $m['localpart'], $b['filename']]);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Mailbox restore failed: '.trim($r['out']));
        else Helpers::flash('ok', trim($r['out']));
        Helpers::redirect('/backups');
    }
    public function restoreDb(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/backups'); }
        $u = Auth::user();
        $db = ApiModel::dbOwned((int)($_POST['db_id'] ?? 0), (int)$u['id']);
        $dump = (string)($_POST['dump'] ?? '');
        if (!$db || !preg_match('/^db-[a-zA-Z0-9_.-]+\.sql\.gz$/', $dump)) { Helpers::flash('err','Bad database/dump'); Helpers::redirect('/backups'); }
        // IDOR gate: dumps live in one global store and the dropdown is only
        // display-filtered — re-derive the user's own set and refuse anything
        // outside it (guessing another tenant's filename must not restore).
        // (Exploitation is additionally blocked by username-namespaced db
        // names + db-restore.sh's section check, but the request itself
        // should never name a foreign object.)
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- dump membership-checked against user's own set below, then namespaced section restore
        if (($u['role'] ?? '') !== 'admin' && !isset($this->ownDumps((int)$u['id'])[$dump])) {
            Helpers::flash('err','Not your dump'); Helpers::redirect('/backups');
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
            $mine = DomainModel::ownedByName($dom, (int)$u['id']);
            if (!$mine && $u['role'] !== 'admin') { Helpers::flash('err','Not your mail'); Helpers::redirect('/backups'); }
        }
        $r = ServiceManager::run('inc-restore.sh', [$u['username'], $date, $rel]);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Restore failed: '.trim($r['out']));
        else Helpers::flash('ok', trim($r['out']));
        Helpers::redirect('/backups');
    }
    public function rdb(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/backups'); }
        $u = Auth::user();
        $db = ApiModel::dbOwned((int)($_POST['db_id'] ?? 0), (int)$u['id']);
        $snap = strtolower(trim((string)($_POST['snapshot'] ?? '')));
        if (!$db || ($sa = $this->snapAccount($snap)) === null) { Helpers::flash('err','Bad database/snapshot'); Helpers::redirect('/backups'); }
        [$acc, $stime] = $sa;
        if (($u['role'] ?? '') !== 'admin' && ($acc !== $u['username'] || !$this->snapAfterAccount($stime, (int)$u['id']))) { Helpers::flash('err','Not your snapshot'); Helpers::redirect('/backups'); }
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
        if (($sa = $this->snapAccount($snap)) === null) { Helpers::flash('err','Bad snapshot'); Helpers::redirect('/backups'); }
        [$acc, $stime] = $sa;
        if (($u['role'] ?? '') !== 'admin' && ($acc !== $u['username'] || !$this->snapAfterAccount($stime, (int)$u['id']))) { Helpers::flash('err','Not your snapshot'); Helpers::redirect('/backups'); }
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
    // restic engine: toggle, snapshot-scoped restores. Snapshot ids are
    // always re-resolved to their account tag here (never trust the form).
    // [account tag, snapshot time] or null
    private function snapAccount(string $id): ?array {
        if (!preg_match('/^[0-9a-f]{7,64}$/', $id)) return null;
        try {
            $r = ServiceManager::run('restic-ls.sh', []);
            if (($r['code'] ?? 1) !== 0) return null;
            foreach (json_decode($r['out'] ?? '[]', true) ?: [] as $s) {
                if (!is_array($s) || substr((string)($s['id'] ?? ''), 0, strlen($id)) !== $id) continue;
                foreach ((array)($s['tags'] ?? []) as $t) {
                    if (str_starts_with((string)$t, 'account=')) return [substr((string)$t, 8), (string)($s['time'] ?? '')];
                }
            }
        } catch (Throwable $e) {}
        return null;
    }
}
