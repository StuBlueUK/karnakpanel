<?php
declare(strict_types=1);
class PhpController extends Controller {
    public function list(): void {
        Auth::requireLogin();
        $u = Auth::user();
        $domains = DomainModel::forUser((int)$u['id']);
        // per-user PHP error log (pool-level: covers all this user's domains)
        $phplog = [];
        $f = '/var/log/karnakpanel/php-'.$u['username'].'.error.log';
        if (preg_match('/^[a-z][a-z0-9_]{2,31}$/', $u['username']) && is_readable($f)) {
            $tail = file_get_contents($f, false, null, max(0, (filesize($f) ?: 0) - 65536));
            $all = array_filter(array_map('trim', explode("\n", (string)$tail)));
            $phplog = array_slice(array_reverse(array_values($all)), 0, 200);
        }
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q !== '') $phplog = array_values(array_filter($phplog, fn($l) => stripos($l, $q) !== false));
        // admin: accounts still on a version that is no longer offered
        // (legacy or retiring) — php-retire.sh won't purge until they move
        $stale = [];
        // (no selectable version installed = nothing to move TO; skip the banner)
        if (Auth::role() === 'admin' && PhpVersions::selectable()) {
            $ph = implode(',', array_fill(0, count(PhpVersions::selectable()), '?'));
            $stale = Database::query("SELECT d.php_version AS v, COUNT(DISTINCT d.user_id) AS n,
                GROUP_CONCAT(DISTINCT u.username ORDER BY u.username SEPARATOR ', ') AS who
                FROM domains d JOIN users u ON u.id=d.user_id
                WHERE d.php_version NOT IN ($ph) GROUP BY d.php_version ORDER BY d.php_version",
                PhpVersions::selectable())->fetchAll();
        }
        $this->view('php', compact('domains','phplog','q','stale'));
    }
    // admin bulk: every account with a site on <from> moves to the default
    // (php-switch.sh moves the account's pool and all its domain rows)
    public function moveAll(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/php'); }
        $from = (string)($_POST['from'] ?? '');
        if (!preg_match('/^8\.[0-9]$/', $from) || PhpVersions::isSelectable($from)) {
            Helpers::flash('err','Bad version'); Helpers::redirect('/php');
        }
        $to = PhpVersions::DEFAULT;
        $rows = Database::query("SELECT u.*, MIN(d.domain) AS one_domain FROM domains d JOIN users u ON u.id=d.user_id
            WHERE d.php_version=? GROUP BY u.id ORDER BY u.username", [$from])->fetchAll();
        $ok = []; $bad = [];
        foreach ($rows as $usr) {
            $r = ServiceManager::run('php-switch.sh', [$usr['one_domain'], $to, (string)(Helpers::poolChildren($usr) ?? 10)]);
            if (($r['code'] ?? 1) === 0) $ok[] = $usr['username']; else $bad[] = $usr['username'];
        }
        Helpers::log("php bulk move $from -> $to by ".Auth::user()['username'].": ".count($ok)." ok, ".count($bad)." failed");
        if ($bad) Helpers::flash('err', "Moved to PHP $to: ".(implode(', ', $ok) ?: 'none')."; FAILED: ".implode(', ', $bad));
        else Helpers::flash('ok', $ok ? "Moved to PHP $to: ".implode(', ', $ok) : "No accounts on PHP $from");
        Helpers::redirect('/php');
    }
    public function set(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/php'); }
        $u = Auth::user();
        $domainId = (int)($_POST['domain_id']??0); $ver = $_POST['php_version']??'';
        if (!PhpVersions::isSelectable($ver)) { Helpers::flash('err','Bad version'); Helpers::redirect('/php'); }
        $d = Database::query("SELECT * FROM domains WHERE id=? AND user_id=?", [$domainId,$u['id']])->fetch();
        if (!$d) { Helpers::flash('err','Domain not found'); Helpers::redirect('/php'); }
        Database::query("UPDATE domains SET php_version=? WHERE id=?", [$ver,$domainId]);
        $kids = Helpers::poolChildren($u) ?? 10;
        $r = ServiceManager::run('php-switch.sh', [$d['domain'], $ver, (string)$kids]);
        Helpers::flash('ok',"Account {$u['username']} now on PHP $ver. ".trim($r['out'] ?? ''));
        Helpers::redirect('/php');
    }
}
