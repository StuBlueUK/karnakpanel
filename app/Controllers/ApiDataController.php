<?php
declare(strict_types=1);
// ApiDataController — inherits the API auth/scoping helpers from ApiController.
class ApiDataController extends ApiController {
    public function dbList(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $t = $this->scopedUser($me);
        // names + users only: passwords are shown once at create/reset, never listed
        $rows = ApiModel::dbsFor((int)$t['id']);
        $this->json(['ok' => true, 'username' => $t['username'], 'databases' => $rows]);
    }
    public function dbCreate(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $in = $this->apiInput();
        $t = $this->resolveTarget($me, (string)($in['username'] ?? $me['username']));
        $name = preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string)($in['name'] ?? ''))));
        $pass = MysqlDb::password(isset($in['password']) ? (string)$in['password'] : null);
        if (strlen($name) < 3) $this->json(['error' => 'bad db name (3+ chars a-z0-9_)'], 422);
        if ($pass === null) $this->json(['error' => 'db password 8+ chars (omit for random)'], 422);
        if ($err = Quota::limitError($t, 'dbs')) $this->json(['error' => $err], 422);
        if ($err = Quota::blocked($t)) $this->json(['error' => $err], 422);
        try {
            [$dbname, $dbuser] = MysqlDb::create($t, $name, $pass);
            Helpers::log("API database $dbname created for {$t['username']} by {$me['username']}");
            $this->json(['ok' => true, 'db_name' => $dbname, 'db_user' => $dbuser, 'password' => $pass]);
        } catch (Throwable $e) { $this->json(['error' => 'create failed: '.$e->getMessage()], 500); }
    }
    public function dbDelete(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $in = $this->apiInput();
        $t = $this->resolveTarget($me, (string)($in['username'] ?? $me['username']));
        $row = ApiModel::dbOwned((int)($in['id'] ?? 0), (int)$t['id']);
        if (!$row) $this->json(['error' => 'database not found'], 404);
        if (!MysqlDb::validRow($row)) $this->json(['error' => 'bad stored name or system database refused'], 422);
        try {
            MysqlDb::drop($row);
            Helpers::log("API database {$row['db_name']} deleted by {$me['username']}");
            $this->json(['ok' => true, 'deleted' => $row['db_name']]);
        } catch (Throwable $e) { $this->json(['error' => 'delete failed: '.$e->getMessage()], 500); }
    }
    public function dbReset(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $in = $this->apiInput();
        $t = $this->resolveTarget($me, (string)($in['username'] ?? $me['username']));
        $row = ApiModel::dbOwned((int)($in['id'] ?? 0), (int)$t['id']);
        if (!$row) $this->json(['error' => 'database not found'], 404);
        try {
            $pass = MysqlDb::resetPassword($row);
            Helpers::log("API db password reset for {$row['db_user']} by {$me['username']}");
            $this->json(['ok' => true, 'db_user' => $row['db_user'], 'password' => $pass]);
        } catch (Throwable $e) { $this->json(['error' => 'reset failed: '.$e->getMessage()], 500); }
    }
    // ---- backups ----
    public function backupList(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $t = $this->scopedUser($me);
        $rows = ApiModel::backupsFor((int)$t['id']);
        $this->json(['ok' => true, 'username' => $t['username'], 'backups' => $rows]);
    }
    public function backupCreate(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $t = $this->scopedUser($me);
        // runs in the background (backup-bg.sh); poll backupList until the
        // new row appears — backup-account.sh registers it when done
        $r = ServiceManager::run('backup-bg.sh', ['start', $t['username']]);
        $out = trim($r['out'] ?? '');
        if (($r['code'] ?? 1) !== 0) $this->json(['error' => 'backup not started: '.$out], 500);
        Helpers::log("API backup started for {$t['username']} by {$me['username']}");
        $this->json(['ok' => true, 'running' => true, 'already_running' => $out === 'already running', 'filename' => null]);
    }
    // ---- SSL ----
}
