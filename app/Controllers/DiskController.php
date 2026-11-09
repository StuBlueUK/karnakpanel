<?php
declare(strict_types=1);
// Disk usage viewer: what counts toward the quota (website folders, mail,
// other home files — the same home + /var/mail/virtual sum quota-enforce.sh
// enforces), what doesn't (databases, the account's backups), and a
// per-folder drilldown. Numbers come from disk-tree.sh (root, scoped to the
// session user's own home — the script arg is never a POST field), stored in
// disk_tree_cache; Refresh is rate-limited to one run per 5min. DB sizes use
// the provision credential. Admins may inspect any account via ?user=
// (role-checked); everyone else sees only themselves.
class DiskController extends Controller {
    private const REFRESH_SECS = 300;
    // payload format; older caches (whole-MB "domain" rows, no mail or
    // backups) are re-measured on the next view
    private const TREE_V = 2;
    public function index(): void {
        Auth::requireLogin();
        $u = Auth::user();
        $target = $this->targetUser($u);
        if (!$target) { Helpers::flash('err','Account not found'); Helpers::redirect('/disk'); }
        $domains = DomainModel::forUser((int)$target['id']);
        // drilldown path: free-form but fenced — safe chars, no .., and the
        // script re-validates + realpaths it inside the home. A crafted ?path=
        // can at most list the user's own folders (never another account's:
        // the script arg is the session user's name, not a POST field).
        $path = trim((string)($_GET['path'] ?? ''));
        if ($path !== '' && !$this->validPath($path)) $path = '';
        // NOTE: the header/sidebar quota comes from Controller::view(), which
        // re-reads the user row fresh (the session snapshot goes stale —
        // it once showed 256MB an hour after 716MB was seeded)
        $quota = Quota::usage($target);
        // elapsed math runs in MySQL (TIMESTAMPDIFF): PHP and MySQL can
        // disagree on timezone (2h skew seen live), which turned the wait
        // into "118m" when computed via strtotime().
        $cache = DiskModel::cache((int)$target['id']);
        $tree = null; $measuredAgo = null;
        if ($cache && ($tree = json_decode((string)$cache['payload'], true)) && is_array($tree) && ($tree['v'] ?? 0) === self::TREE_V) {
            $measuredAgo = $this->fmtAgo(max(0, (int)$cache['elapsed']));
        } else {
            // never measured / old format: one measurement now (same cost as
            // a drilldown click), so the page never opens on a blank table
            $tree = $this->measure($target);
            if ($tree !== null) $measuredAgo = 'just now';
        }
        // drilldown measures live per click (one capped depth-1 du — bounded
        // cost, no cache to go stale). Overview reads the 5min cache.
        $drill = null;
        if ($path !== '') {
            $r = ServiceManager::run('disk-tree.sh', [$target['username'], $path]);
            if (($r['code'] ?? 1) === 0) $drill = $this->parse($r['out'] ?? '');
            if ($drill === null) { Helpers::flash('err','Folder not found'); Helpers::redirect($this->diskBack($u, $target)); }
        }
        // DB sizes are cheap enough to read live (information_schema)
        $dbSizes = $this->dbSizes((int)$target['id']);
        $isAdminView = ($u['role'] === 'admin' && (int)$target['id'] !== (int)$u['id']);
        $targetName = $target['username'];
        $siteNames = array_column($domains, 'domain');
        $this->view('disk', compact('quota','tree','measuredAgo','siteNames','path','drill','dbSizes','isAdminView','targetName'));
    }
    // Refresh re-runs the measurement (root du, capped). POST + CSRF: it
    // spends server I/O, so it must not be a GET. Rate-limited to one run
    // per 5min per account (otherwise serve the cache with its age).
    public function refresh(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/disk'); }
        $u = Auth::user();
        $target = $this->targetUser($u);
        if (!$target) { Helpers::flash('err','Account not found'); Helpers::redirect('/disk'); }
        $back = $this->diskBack($u, $target);
        $ppath = trim((string)($_POST['path'] ?? ''));
        if ($ppath !== '' && $this->validPath($ppath)) $back .= (str_contains($back, '?') ? '&' : '?').'path='.urlencode($ppath);
        $elapsed = DiskModel::cacheElapsed((int)$target['id']);
        if ($elapsed !== null && $elapsed < self::REFRESH_SECS) {
            // fmtAgo() already ends in "ago" — don't append another (was "0s ago ago")
            $wait = max(1, self::REFRESH_SECS - $elapsed);
            $waitTxt = $wait >= 60 ? ((int)ceil($wait / 60)).'m' : $wait.'s';
            Helpers::flash('err','Measured '.$this->fmtAgo(max(0, $elapsed)).' — next refresh in '.$waitTxt);
            Helpers::redirect($back);
        }
        if ($this->measure($target) === null) { Helpers::flash('err','Measure failed — try again in a minute'); Helpers::redirect($back); }
        Helpers::flash('ok','Disk measured just now');
        Helpers::redirect($back);
    }
    // One overview measurement → cache + the quota display figure. Quota =
    // home + mail, exactly quota-enforce.sh's sum (this used to store only
    // the visible folders: hidden dirs and mail dropped out, so the quota
    // bar shrank after every Refresh until the next enforcer pass).
    private function measure(array $target): ?array {
        $r = ServiceManager::run('disk-tree.sh', [$target['username']], 45);
        if (($r['code'] ?? 1) !== 0) return null;
        $tree = $this->parse($r['out'] ?? '');
        if ($tree === null || $tree['total'] === null) return null;
        $tree['v'] = self::TREE_V;
        DiskModel::putCache((int)$target['id'], json_encode($tree));
        $used = $tree['total']['mb'] + array_sum(array_column(array_filter($tree['rows'], fn($x) => $x['kind'] === 'mail'), 'mb'));
        DiskModel::setUsedMb((int)$target['id'], (int)round($used));
        return $tree;
    }
    // Shared back-link builder (admin inspect target preserved).
    private function diskBack(array $u, array $target): string {
        return '/disk' . ($u['role'] === 'admin' && (int)$target['id'] !== (int)$u['id'] ? '?user='.urlencode($target['username']) : '');
    }
    // Relpath fence, mirrored in disk-tree.sh (defence in depth — the script
    // re-validates and realpaths before touching anything).
    private function validPath(string $p): bool {
        if ($p === '' || strlen($p) > 200) return false;
        if (!preg_match('#^[a-zA-Z0-9_.-]+(/[a-zA-Z0-9_.-]+)*$#', $p)) return false;
        foreach (explode('/', $p) as $seg) { if ($seg === '..') return false; }
        return true;
    }
    // Drilldown (?path=) measures live per click — one capped depth-1 du,
    // fenced by validPath() here and realpath-inside-home in the script.
    private function targetUser(array $u): ?array {
        if ($u['role'] === 'admin' && isset($_GET['user']) && $_GET['user'] !== '') {
            $name = strtolower(trim((string)$_GET['user']));
            if (!Helpers::validUsername($name)) return null;
            $row = User::findByName($name);
            return $row ?: null;
        }
        if ($u['role'] === 'admin' && isset($_POST['user']) && $_POST['user'] !== '') {
            $name = strtolower(trim((string)$_POST['user']));
            if (!Helpers::validUsername($name)) return null;
            $row = User::findByName($name);
            return $row ?: null;
        }
        return $u;
    }
    // overview: "top|loose|mail|backups|snapshots\tlabel\tMB" (backups'
    // label = file count); drilldown: "dir|file\trelpath\tMB"; both end
    // with "total\tlabel\tMB\tfiles" (see disk-tree.sh)
    private function parse(string $out): ?array {
        $rows = []; $total = null;
        foreach (explode("\n", trim($out)) as $line) {
            $p = explode("\t", trim($line));
            if (count($p) < 3) continue;
            [$kind, $label, $mb] = $p;
            if (!in_array($kind, ['top','loose','mail','backups','snapshots','dir','file','total'], true)) continue;
            if (!is_numeric($mb) || (float)$mb < 0) continue;
            // labels are path fragments from the user's own tree — still
            // escape on output; here just bound the length
            $label = mb_substr($label, 0, 200);
            if ($kind === 'total') { $total = ['mb' => (float)$mb, 'files' => isset($p[3]) && is_numeric($p[3]) ? (int)$p[3] : 0]; continue; }
            if (count($rows) >= 400) continue;
            $rows[] = ['kind' => $kind, 'label' => $label, 'mb' => (float)$mb];
        }
        if ($total === null && $rows === []) return null;
        usort($rows, fn($a, $b) => $b['mb'] <=> $a['mb']);
        return ['rows' => $rows, 'total' => $total];
    }
    // Per-database sizes via the provisioning credential (never the app one).
    private function dbSizes(int $uid): array {
        $out = [];
        try {
            $dbs = MysqlDb::namesFor($uid);
            if (!$dbs) return [];
            $names = array_column($dbs, 'db_name');
            $pdo = Database::provision();
            $place = implode(',', array_fill(0, count($names), '?'));
            // nosemgrep: php.lang.security.injection.tainted-callable.tainted-callable -- PDO::prepare with bound placeholders (rule misfire)
            // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string -- $place holds only ? placeholders + commas (built from count), zero user bytes
            $st = $pdo->prepare("SELECT TABLE_SCHEMA s, SUM(DATA_LENGTH+INDEX_LENGTH)/1048576 mb FROM information_schema.TABLES WHERE TABLE_SCHEMA IN ($place) GROUP BY TABLE_SCHEMA");
            $st->execute($names);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['s']] = round((float)$r['mb'], 1);
        } catch (Throwable $e) {}
        return $out;
    }
    private function fmtAgo(int $s): string {
        $s = max(0, $s);
        if ($s < 90) return $s.'s ago';
        $m = (int)($s / 60);
        if ($m < 90) return $m.'m ago';
        return (int)($m / 60).'h ago';
    }
}
