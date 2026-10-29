<?php
declare(strict_types=1);
// Disk usage viewer: per-account breakdown (per-domain site files, mailboxes,
// databases) + per-folder drilldown. Numbers come from disk-tree.sh (root,
// scoped to the session user's own home — the script arg is never a POST
// field) and are cached 5min in disk_tree_cache; mail/DB sizes use the
// existing mail-usage.sh + provision-credential patterns. Admins may inspect
// any account via ?user= (role-checked); everyone else sees only themselves.
class DiskController extends Controller {
    private const REFRESH_SECS = 300;
    private const MAX_BOXES = 60;
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
        $quota = Helpers::quota($target);
        // elapsed math runs in MySQL (TIMESTAMPDIFF): PHP and MySQL can
        // disagree on timezone (2h skew seen live), which turned the wait
        // into "118m" when computed via strtotime().
        $cache = Database::query("SELECT payload, TIMESTAMPDIFF(SECOND, measured_at, NOW()) elapsed FROM disk_tree_cache WHERE user_id=?", [$target['id']])->fetch();
        $tree = null; $measuredAgo = null;
        if ($cache && ($tree = json_decode((string)$cache['payload'], true)) && is_array($tree)) {
            $measuredAgo = $this->fmtAgo(max(0, (int)$cache['elapsed']));
        }
        // drilldown measures live per click (one capped depth-1 du — bounded
        // cost, no cache to go stale). Overview reads the 5min cache.
        $drill = null;
        if ($path !== '') {
            $r = ServiceManager::run('disk-tree.sh', [$target['username'], $path]);
            if (($r['code'] ?? 1) === 0) $drill = $this->parse($r['out'] ?? '');
            if ($drill === null) { Helpers::flash('err','Folder not found'); Helpers::redirect($this->diskBack($u, $target)); }
        }
        // mail + DB sizes are cheap enough to read live every page view
        // (doveadm accounting + information_schema — no tree walks)
        [$mailByDomain, $mailCapped] = $this->mailSizes((int)$target['id']);
        $dbSizes = $this->dbSizes((int)$target['id']);
        $isAdminView = ($u['role'] === 'admin' && (int)$target['id'] !== (int)$u['id']);
        $targetName = $target['username'];
        $this->view('disk', compact('quota','tree','measuredAgo','domains','path','drill','mailByDomain','mailCapped','dbSizes','isAdminView','targetName'));
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
        $cache = Database::query("SELECT TIMESTAMPDIFF(SECOND, measured_at, NOW()) elapsed FROM disk_tree_cache WHERE user_id=?", [$target['id']])->fetch();
        $elapsed = $cache ? (int)$cache['elapsed'] : null;
        if ($elapsed !== null && $elapsed < self::REFRESH_SECS) {
            // fmtAgo() already ends in "ago" — don't append another (was "0s ago ago")
            $wait = max(1, self::REFRESH_SECS - $elapsed);
            $waitTxt = $wait >= 60 ? ((int)ceil($wait / 60)).'m' : $wait.'s';
            Helpers::flash('err','Measured '.$this->fmtAgo(max(0, $elapsed)).' — next refresh in '.$waitTxt);
            Helpers::redirect($back);
        }
        $r = ServiceManager::run('disk-tree.sh', [$target['username']]);
        if (($r['code'] ?? 1) !== 0) { Helpers::flash('err','Measure failed: '.trim((string)($r['out'] ?? ''))); Helpers::redirect($back); }
        $tree = $this->parse($r['out'] ?? '');
        if ($tree === null) { Helpers::flash('err','Measure returned nothing usable'); Helpers::redirect($back); }
        Database::query("INSERT INTO disk_tree_cache(user_id,payload) VALUES(?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload), measured_at=NOW()",
            [$target['id'], json_encode($tree)]);
        $totalMb = 0;
        foreach ($tree['rows'] as $row) { if ($row['kind'] === 'domain') $totalMb += $row['mb']; }
        Database::query("UPDATE users SET disk_used_mb=?, disk_measured_at=NOW() WHERE id=?", [(int)round($totalMb), $target['id']]);
        Helpers::flash('ok','Disk measured just now');
        Helpers::redirect($back);
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
            $row = Database::query("SELECT * FROM users WHERE username=?", [$name])->fetch();
            return $row ?: null;
        }
        if ($u['role'] === 'admin' && isset($_POST['user']) && $_POST['user'] !== '') {
            $name = strtolower(trim((string)$_POST['user']));
            if (!Helpers::validUsername($name)) return null;
            $row = Database::query("SELECT * FROM users WHERE username=?", [$name])->fetch();
            return $row ?: null;
        }
        return $u;
    }
    // "domain\tname\tMB" / "dir|file\trelpath\tMB" / "total\tlabel\tMB\tfiles"
    private function parse(string $out): ?array {
        $rows = []; $total = null;
        foreach (explode("\n", trim($out)) as $line) {
            $p = explode("\t", trim($line));
            if (count($p) < 3) continue;
            [$kind, $label, $mb] = $p;
            if (!in_array($kind, ['domain','dir','file','total'], true)) continue;
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
    // Per-domain mailbox totals via Dovecot accounting (same source the Email
    // page shows). Capped: huge fleets show the first 60 with a note.
    private function mailSizes(int $uid): array {
        $byDomain = []; $capped = false;
        try {
            $boxes = Database::query("SELECT e.localpart, d.domain FROM email_accounts e JOIN domains d ON d.id=e.domain_id WHERE e.user_id=? ORDER BY d.domain, e.localpart", [$uid])->fetchAll();
            $capped = count($boxes) > self::MAX_BOXES;
            $boxes = array_slice($boxes, 0, self::MAX_BOXES);
            $usage = MailUsage::many(array_map(fn($b) => $b['localpart'].'@'.$b['domain'], $boxes));
            foreach ($boxes as $b) {
                $a = $b['localpart'].'@'.$b['domain'];
                if (isset($usage[$a])) $byDomain[$b['domain']] = ($byDomain[$b['domain']] ?? 0) + $usage[$a];
            }
        } catch (Throwable $e) {}
        return [$byDomain, $capped];
    }
    // Per-database sizes via the provisioning credential (never the app one).
    private function dbSizes(int $uid): array {
        $out = [];
        try {
            $dbs = Database::query("SELECT db_name FROM mysql_dbs WHERE user_id=?", [$uid])->fetchAll();
            if (!$dbs) return [];
            $names = array_column($dbs, 'db_name');
            foreach ($names as $n) {
                if (!preg_match('/^[a-z0-9_]{1,64}$/', (string)$n)) continue;
            }
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
