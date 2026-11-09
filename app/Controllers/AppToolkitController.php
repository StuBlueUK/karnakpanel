<?php
declare(strict_types=1);
// App Toolkit: staging clones of panel-installed apps (clone / refresh /
// push / destroy). v1: WordPress only — other engines get a v1.1 flash.
// Clone/push run DETACHED (script backgrounds past the sudo timeout); the
// page polls state via staging_clones.status + Logs → apptoolkit.
class AppToolkitController extends Controller {
    public function index(): void {
        Auth::requireLogin();
        $u = Auth::user();
        $installs = [];
        $clones = [];
        try {
            $installs = AppToolkitModel::installsFor((int)$u['id']);
            $clones = AppToolkitModel::clonesFor((int)$u['id']);
        } catch (Throwable $e) {}
        $byInstall = [];
        foreach ($clones as $c) $byInstall[(int)$c['live_install_id']] = $c;
        $snaps = [];
        try {
            $snaps = AppToolkitModel::snapshotsByClone((int)$u['id']);
        } catch (Throwable $e) {}
        $this->view('app_toolkit', ['installs' => $installs, 'byInstall' => $byInstall, 'snaps' => $snaps, 'confirm' => null]);
    }
    private function wpConfig(string $docroot): ?array {
        $f = rtrim($docroot, '/').'/wp-config.php';
        if (!is_readable($f)) return null;
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- wp-config under owned install docroot
        $c = (string)file_get_contents($f);
        $g = function (string $re) use ($c): ?string {
            if (!preg_match($re, $c, $m)) return null;
            return $m[1];
        };
        $db = $g("/define\\(\\s*'DB_NAME'\\s*,\\s*'([^']+)'/");
        $user = $g("/define\\(\\s*'DB_USER'\\s*,\\s*'([^']+)'/");
        $pass = $g("/define\\(\\s*'DB_PASSWORD'\\s*,\\s*'([^']*)'/");
        $prefix = $g("/\\\$table_prefix\\s*=\\s*'([^']+)'/");
        if ($db === null || $user === null || $pass === null) return null;
        return ['db' => $db, 'user' => $user, 'pass' => $pass, 'prefix' => ($prefix !== null && preg_match('/^[A-Za-z0-9_]{1,20}$/', $prefix)) ? $prefix : 'wp_'];
    }
    // live content newer than the clone (posts/comments) — shown on the full
    // push confirm screen so data loss is a visible number, not a surprise.
    private function wpDivergence(string $docroot, string $since): ?array {
        try {
            $cfg = $this->wpConfig($docroot);
            if (!$cfg) return null;
            $m = new mysqli('localhost', $cfg['user'], $cfg['pass'], $cfg['db']);
            if ($m->connect_errno) return null;
            $p = $cfg['prefix'];
            if (!preg_match('/^[A-Za-z0-9_]{1,20}$/', $p)) return null;
            // prepared statements: $since comes from a transfer-imported column
            // (second-order), not just a local format. Fresh installs have no
            // tables until the web installer runs — a false result is "no
            // divergence", never a fatal.
            $since = (string)$since;
            $posts = 0;
            $st = $m->prepare("SELECT COUNT(*) FROM `{$p}posts` WHERE post_date > ?");
            if ($st) { $st->bind_param('s', $since); if ($st->execute()) { $st->bind_result($c); $st->fetch(); $posts = (int)$c; } $st->close(); }
            $comms = 0;
            $st = $m->prepare("SELECT COUNT(*) FROM `{$p}comments` WHERE comment_date > ?");
            if ($st) { $st->bind_param('s', $since); if ($st->execute()) { $st->bind_result($c); $st->fetch(); $comms = (int)$c; } $st->close(); }
            $m->close();
            return ['posts' => $posts, 'comments' => $comms];
        } catch (Throwable $e) { return null; }
    }
    public function clone(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err', 'Bad CSRF'); Helpers::redirect('/toolkit'); }
        $u = Auth::user();
        $id = (int)($_POST['install_id'] ?? 0);
        $s = AppToolkitModel::install($id, (int)$u['id']);
        if (!$s) { Helpers::flash('err', 'Install not found'); Helpers::redirect('/toolkit'); }
        if (($s['app'] ?? '') !== 'wordpress') { Helpers::flash('err', 'Staging for '.$s['app'].' is coming soon'); Helpers::redirect('/toolkit'); }
        $exists = AppToolkitModel::cloneForInstall($id, (int)$u['id']);
        if ($exists) {
            if (($exists['status'] ?? '') !== 'ready') { Helpers::flash('err', 'A clone is already building — watch Logs → apptoolkit, or dismiss it first'); Helpers::redirect('/toolkit'); }
            // refresh: same staging domain/db, re-run the (idempotent) build
            $this->runClone($u, $s, (int)$exists['id'], true);
            return;
        }
        if ($err = Quota::limitError($u, 'domains')) { Helpers::flash('err', $err.' (staging needs its own subdomain)'); Helpers::redirect('/toolkit'); }
        if ($err = Quota::limitError($u, 'dbs')) { Helpers::flash('err', $err.' (staging needs its own database)'); Helpers::redirect('/toolkit'); }
        if ($err = Quota::blocked($u)) { Helpers::flash('err', $err); Helpers::redirect('/toolkit'); }
        // live must actually be WordPress (panel nobody reads homes via ACL)
        $sub = ltrim($s['path'] ?? '', '/');
        $ldoc = "/home/{$u['username']}/{$s['domain']}/public_html".($sub !== '' ? "/$sub" : '');
        $cfg = $this->wpConfig($ldoc);
        if (!$cfg) { Helpers::flash('err', 'Live files are not a WordPress site (no readable wp-config.php)'); Helpers::redirect('/toolkit'); }
        if (!preg_match('/^[a-z][a-z0-9_]{2,63}$/', $cfg['db'])) { Helpers::flash('err', 'Cannot read live database name'); Helpers::redirect('/toolkit'); }
        $email = (string)($u['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { Helpers::flash('err', 'Set your account email first (staging mail gates to it)'); Helpers::redirect('/toolkit'); }
        // staging name: staging.<parent>, then staging2… (domains-table free)
        $stg = null;
        for ($i = 1; $i <= 9; $i++) {
            $cand = ($i === 1 ? 'staging' : 'staging'.$i).'.'.$s['domain'];
            $hit = DomainModel::existsByName($cand);
            if (!$hit) { $stg = $cand; break; }
        }
        if (!$stg) { Helpers::flash('err', 'No free staging subdomain (staging…staging9 taken)'); Helpers::redirect('/toolkit'); }
        // staging database (same scheme as the Software installer)
        $sdb = null; $sdbuser = null;
        for ($i = 0; $i < 20; $i++) {
            $try = substr(preg_replace('/[^a-z0-9_]/', '', strtolower($u['username'].'_stg'.($i > 0 ? $i : ''))), 0, 60);
            if (strlen($try) < 3) continue;
            if (MysqlDb::nameFree($try, substr($try, 0, 32))) { $sdb = $try; $sdbuser = substr($try, 0, 32); break; }
        }
        if (!$sdb) { Helpers::flash('err', 'Could not pick a free staging database name'); Helpers::redirect('/toolkit'); }
        $sdbpass = bin2hex(random_bytes(12));
        $php = PhpVersions::orDefault($s['php_version'] ?? null);
        $scheme = !empty($s['ssl_enabled']) ? 'https://' : 'http://';
        $lurl = $scheme.$s['domain'].($sub !== '' ? '/'.$sub : '');
        // nosemgrep: php.lang.security.injection.tainted-url-host.tainted-url-host -- host is the just-created owned staging domain, not arbitrary input
        $surl = 'https://'.$stg;
        try {
            // all-or-nothing: a failed zone render must not orphan a domains
            // row (bare staging domains with no vhost behind them).
            Database::conn()->beginTransaction();
            $sdid = DomainModel::insert((int)$u['id'], $stg, "/home/{$u['username']}/$stg/public_html", $php);
            $sdid = (int)Database::conn()->lastInsertId();
            // parent zone row for the staging A record, then re-render
            $parent = DomainModel::findByNameForUser($s['domain'], (int)$u['id']);
            $ip = trim(ServiceManager::run('server-ip.sh', [])['out'] ?? '');
            if (!$parent || !filter_var($ip, FILTER_VALIDATE_IP)) throw new RuntimeException('parent zone/IP unavailable');
            DnsModel::insert((int)$parent['id'], 'A', explode('.', $stg)[0], $ip, 10, 3600);
            $rrid = (int)Database::conn()->lastInsertId();
            // template-built zones carry NS glue in the FILE only — mirror it
            // into dns_records first or the re-render below drops it and the
            // validation refuses (dns-glue is idempotent).
            ServiceManager::run('dns-glue.sh', [$s['domain']]);
            $zr = (new DnsController())->apply((int)$parent['id'], $s['domain']);
            if (($zr['code'] ?? 1) !== 0) throw new RuntimeException('zone render failed: '.trim($zr['out'] ?? ''));
            AppToolkitModel::insertClone((int)$u['id'], $id, $sdid, $sdb, $sdbuser);
            $clid = (int)Database::conn()->lastInsertId();
            Database::conn()->commit();
        } catch (Throwable $e) {
            try { Database::conn()->rollBack(); } catch (Throwable $ignored) {}
            Helpers::flash('err', 'Staging setup failed: '.$e->getMessage());
            Helpers::redirect('/toolkit');
        }
        // nosemgrep: php.lang.security.injection.tainted-url-host.tainted-url-host -- URL args go to local clone script (escaped), panel never requests them
        $r = ServiceManager::run('app-clone.sh', ['wordpress', $s['domain'], $stg, $u['username'], $php, $cfg['db'], $sdb, $sdbuser, new Secret($sdbpass), $lurl, $surl, (string)$u['id'], (string)$id, (string)$sdid, $email, (string)$clid]);
        Helpers::log("toolkit clone $stg by {$u['username']}: ".trim($r['out'] ?? ''));
        if (($r['code'] ?? 1) !== 0) {
            // refused before the build started: undo the rows made above so no
            // bare staging domain or DNS record is left behind
            AppToolkitModel::deleteClone($clid, (int)$u['id']);
            DnsModel::deleteOwned($rrid, (int)$parent['id']);
            DomainModel::deleteForUser($sdid, (int)$u['id']);
            (new DnsController())->apply((int)$parent['id'], $s['domain']);
            Helpers::flash('err', 'Clone not started: '.(trim(preg_replace('/^REFUSED:\s*/', '', trim($r['out'] ?? ''))) ?: 'see Logs → apptoolkit'));
            Helpers::redirect('/toolkit');
        }
        Helpers::flash('ok', "Clone of {$s['domain']} started — watch Logs → apptoolkit, the staging URL + lock password land here when ready");
        Helpers::redirect('/toolkit');
    }
    // refresh an existing ready clone: same rows, re-run the idempotent build
    private function runClone(array $u, array $s, int $clid, bool $refresh): void {
        $c = AppToolkitModel::clone($clid, (int)$u['id']);
        if (!$c) { Helpers::flash('err', 'Clone not found'); Helpers::redirect('/toolkit'); }
        $sub = ltrim($s['path'] ?? '', '/');
        $ldoc = "/home/{$u['username']}/{$s['domain']}/public_html".($sub !== '' ? "/$sub" : '');
        $cfg = $this->wpConfig($ldoc);
        if (!$cfg) { Helpers::flash('err', 'Live files are not readable right now'); Helpers::redirect('/toolkit'); }
        $email = (string)($u['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { Helpers::flash('err', 'Set your account email first'); Helpers::redirect('/toolkit'); }
        $php = PhpVersions::orDefault($s['php_version'] ?? null);
        $scheme = !empty($s['ssl_enabled']) ? 'https://' : 'http://';
        $lurl = $scheme.$s['domain'].($sub !== '' ? '/'.$sub : '');
        // refresh keeps the SAME staging db (script drops + recreates) but
        // needs a password the script can converge: rotate to a fresh one
        $sdbpass = bin2hex(random_bytes(12));
        AppToolkitModel::setStatus($clid, 'cloning');
        // nosemgrep: php.lang.security.injection.tainted-url-host.tainted-url-host -- host from owned-install DB row, passed as script argv (no HTTP fetch by PHP)
        $r = ServiceManager::run('app-clone.sh', ['wordpress', $s['domain'], $c['staging_domain'], $u['username'], $php, $cfg['db'], $c['staging_db'], $c['staging_db_user'], new Secret($sdbpass), $lurl, 'https://'.$c['staging_domain'], (string)$u['id'], (string)$s['id'], (string)$c['staging_domain_id'], $email, (string)$clid]);
        Helpers::log("toolkit refresh {$c['staging_domain']} by {$u['username']}: ".trim($r['out'] ?? ''));
        if (($r['code'] ?? 1) !== 0) {
            AppToolkitModel::setStatus($clid, (string)$c['status']);
            Helpers::flash('err', 'Refresh not started: '.(trim(preg_replace('/^REFUSED:\s*/', '', trim($r['out'] ?? ''))) ?: 'see Logs → apptoolkit'));
            Helpers::redirect('/toolkit');
        }
        Helpers::flash('ok', 'Staging refresh started — watch Logs → apptoolkit');
        Helpers::redirect('/toolkit');
    }
    public function push(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err', 'Bad CSRF'); Helpers::redirect('/toolkit'); }
        $u = Auth::user();
        $clid = (int)($_POST['clone_id'] ?? 0);
        $mode = (string)($_POST['mode'] ?? '');
        if (!in_array($mode, ['files', 'full'], true)) { Helpers::flash('err', 'Bad push mode'); Helpers::redirect('/toolkit'); }
        $c = AppToolkitModel::pushable($clid, (int)$u['id']);
        if (!$c) { Helpers::flash('err', 'Clone not found or not ready'); Helpers::redirect('/toolkit'); }
        if (empty($_POST['confirm'])) {
            $div = null;
            if ($mode === 'full') {
                $sub = ltrim($c['live_path'] ?? '', '/');
                $div = $this->wpDivergence("/home/{$u['username']}/{$c['live_domain']}/public_html".($sub !== '' ? "/$sub" : ''), (string)$c['cloned_at']);
            }
            $snaps = AppToolkitModel::snapshots($clid);
            $this->view('app_toolkit', ['installs' => [], 'byInstall' => [], 'snaps' => [], 'confirm' => ['clone' => $c, 'mode' => $mode, 'div' => $div, 'snaps' => $snaps]]);
            return;
        }
        if ($mode === 'full' && ($_POST['confirm'] ?? '') !== 'PUSH') { Helpers::flash('err', 'Type PUSH to confirm a full push'); Helpers::redirect('/toolkit'); }
        $r = ServiceManager::run('app-push.sh', [(string)$clid, $mode]);
        Helpers::log("toolkit push $mode clone $clid by {$u['username']}: ".trim($r['out'] ?? ''));
        Helpers::flash('ok', trim($r['out'] ?? 'push started').' — watch Logs → apptoolkit');
        Helpers::redirect('/toolkit');
    }
    public function restore(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err', 'Bad CSRF'); Helpers::redirect('/toolkit'); }
        $u = Auth::user();
        $clid = (int)($_POST['clone_id'] ?? 0);
        $snap = (int)($_POST['snapshot_id'] ?? 0);
        $c = AppToolkitModel::restorable($clid, (int)$u['id']);
        $s = AppToolkitModel::snapshot($snap, $clid);
        if (!$c || !$s) { Helpers::flash('err', 'Clone or snapshot not found'); Helpers::redirect('/toolkit'); }
        if (($_POST['confirm'] ?? '') !== 'RESTORE') { Helpers::flash('err', 'Type RESTORE to confirm'); Helpers::redirect('/toolkit'); }
        $r = ServiceManager::run('app-push.sh', [(string)$clid, 'restore', (string)$snap]);
        Helpers::log("toolkit restore snapshot $snap by {$u['username']}: ".trim($r['out'] ?? ''));
        Helpers::flash('ok', trim($r['out'] ?? 'restore started').' — watch Logs → apptoolkit');
        Helpers::redirect('/toolkit');
    }
    public function destroy(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err', 'Bad CSRF'); Helpers::redirect('/toolkit'); }
        $u = Auth::user();
        $clid = (int)($_POST['clone_id'] ?? 0);
        $c = AppToolkitModel::clone($clid, (int)$u['id']);
        if (!$c) { Helpers::flash('err', 'Clone not found'); Helpers::redirect('/toolkit'); }
        $r = ServiceManager::run('app-destroy.sh', [(string)$clid]);
        if (($r['code'] ?? 1) !== 0) { Helpers::flash('err', 'Destroy failed: '.trim($r['out'] ?? '')); Helpers::redirect('/toolkit'); }
        try {
            // parent zone loses the staging A row, then re-render
            $parent = preg_replace('/^[a-z0-9-]+\./i', '', $c['staging_domain']);
            $prow = DomainModel::findByNameForUser($parent, (int)$u['id']);
            if ($prow) {
                DnsModel::deleteHost((int)$prow['id'], explode('.', $c['staging_domain'])[0]);
                (new DnsController())->apply((int)$prow['id'], $parent);
            }
            AppToolkitModel::deleteSnapshots($clid);
            AppToolkitModel::deleteCloneAny($clid);
            MysqlDb::deleteRowByName((int)$u['id'], (string)$c['staging_db']);
            MailAutopilot::purgeDomain((int)$c['staging_domain_id']);
            DomainModel::deleteForUser((int)$c['staging_domain_id'], (int)$u['id']);
        } catch (Throwable $e) {}
        Helpers::log("toolkit destroy {$c['staging_domain']} by {$u['username']}");
        Helpers::flash('ok', trim($r['out'] ?? 'Staging destroyed'));
        Helpers::redirect('/toolkit');
    }
    public function dismiss(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err', 'Bad CSRF'); Helpers::redirect('/toolkit'); }
        $u = Auth::user();
        $clid = (int)($_POST['clone_id'] ?? 0);
        $c = AppToolkitModel::cloning($clid, (int)$u['id']);
        if (!$c) { Helpers::flash('err', 'Nothing to dismiss'); Helpers::redirect('/toolkit'); }
        AppToolkitModel::deleteCloneAny($clid);
        Helpers::log("toolkit dismiss clone $clid by {$u['username']} (bare staging domain kept under Domains)");
        Helpers::flash('ok', 'Stuck clone record removed — the bare staging domain stays under Domains (delete or retry there)');
        Helpers::redirect('/toolkit');
    }
}
