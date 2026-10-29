<?php
declare(strict_types=1);
// Fleet Updates: nightly scan results for panel-installed apps, visible to
// admin (whole box) and resellers (own clients only). Read-only dashboard in
// v1 — per-install update execution with restore points is phase 2.
class FleetController extends Controller {
    public function index(): void {
        Auth::requireRole(['admin','reseller']);
        $this->render();
    }
    private function render(?array $preview = null): void {
        $me = Auth::user();
        $where = '';
        $args = [];
        if ($me['role'] === 'reseller') { $where = 'WHERE u.owner_id=?'; $args = [(int)$me['id']]; }
        $rows = Database::query("SELECT s.id, s.app, s.url, u.username, d.domain, v.live_version, v.latest_version, v.update_available, v.plugins_total, v.plugins_available, v.scanned_at, v.scan_note FROM site_installs s JOIN users u ON u.id=s.user_id JOIN domains d ON d.id=s.domain_id LEFT JOIN app_versions v ON v.install_id=s.id $where ORDER BY COALESCE(v.update_available,0) DESC, s.id DESC", $args)->fetchAll();
        $nUpd = 0; $nStale = 0;
        foreach ($rows as $r) {
            if (!empty($r['update_available'])) $nUpd++;
            elseif (empty($r['scanned_at'])) $nStale++;
        }
        $fleetOn = '1'; $fleetHour = '3';
        if ($me['role'] === 'admin') {
            try {
                $fleetOn = Database::query("SELECT value FROM settings WHERE name='fleet_enabled'")->fetch()['value'] ?? '1';
                $fleetHour = Database::query("SELECT value FROM settings WHERE name='fleet_hour'")->fetch()['value'] ?? '3';
            } catch (Throwable $e) {}
        }
        $this->view('fleet', ['rows' => $rows, 'nUpd' => $nUpd, 'nStale' => $nStale, 'fleetOn' => $fleetOn, 'fleetHour' => $fleetHour, 'isAdmin' => $me['role'] === 'admin', 'preview' => $preview]);
    }
    // Nightly scan schedule: enabled flag + hour (script runs hourly and
    // fires only in the chosen slot). Admin-only: resellers see the fleet,
    // they don't command the box's cron. Same shape as Admin::malware().
    public function schedule(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/fleet'); }
        $on = isset($_POST['fleet_on']) ? '1' : '0';
        $hour = (int)($_POST['fleet_hour'] ?? 3);
        if ($hour < 0 || $hour > 23) { Helpers::flash('err','Hour must be 0-23'); Helpers::redirect('/fleet'); }
        try {
            Database::query("INSERT INTO settings(name,value) VALUES('fleet_enabled',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [$on]);
            Database::query("INSERT INTO settings(name,value) VALUES('fleet_hour',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [(string)$hour]);
            Helpers::flash('ok', $on === '1' ? "Fleet scan on, daily ~$hour:37" : 'Fleet scan disabled');
        } catch (Throwable $e) {
            try {
                Database::query("CREATE TABLE IF NOT EXISTS settings (name VARCHAR(190) PRIMARY KEY, value TEXT NOT NULL)");
                Database::query("INSERT INTO settings(name,value) VALUES('fleet_enabled',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [$on]);
                Database::query("INSERT INTO settings(name,value) VALUES('fleet_hour',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [(string)$hour]);
                Helpers::flash('ok','Fleet scan schedule saved');
            } catch (Throwable $e2) { Helpers::flash('err','Save failed: '.$e2->getMessage()); }
        }
        Helpers::redirect('/fleet');
    }
    // one install in scope? Admin: any row. Reseller: only own clients'.
    // Returns the site_installs row or null (never leaks existence: same
    // message either way, like the reseller plan-change guard).
    private function scopedInstall(int $id): ?array {
        $me = Auth::user();
        $w = $me['role'] === 'reseller' ? 'AND u.owner_id='.(int)$me['id'] : '';
        $r = Database::query("SELECT s.* FROM site_installs s JOIN users u ON u.id=s.user_id WHERE s.id=? $w", [$id])->fetch();
        return $r ?: null;
    }
    // Per-row update: core (minor unless --allow-major confirmed) or plugins.
    // Detached: the script snapshots first, updates, verifies, rolls back on
    // failure — the panel only starts it (15s sudo window).
    public function update(): void {
        Auth::requireRole(['admin','reseller']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/fleet'); }
        $id = (int)($_POST['id'] ?? 0);
        $mode = (string)($_POST['mode'] ?? '');
        if (!in_array($mode, ['core','plugins'], true)) { Helpers::flash('err','Bad mode'); Helpers::redirect('/fleet'); }
        $s = $this->scopedInstall($id);
        if (!$s || ($s['app'] ?? '') !== 'wordpress') { Helpers::flash('err','Install not found'); Helpers::redirect('/fleet'); }
        $args = [(string)$id, $mode];
        if ($mode === 'core' && !empty($_POST['allow_major'])) $args[] = '--allow-major';
        $r = ServiceManager::run('fleet-update.sh', $args);
        Helpers::log("fleet $mode install $id by ".Auth::user()['username'].": ".trim($r['out'] ?? ''));
        Helpers::flash('ok', trim($r['out'] ?? 'update started').' — progress in Logs → fleet');
        Helpers::redirect('/fleet');
    }
    // Mass safe pass over everything in scope with an update pending:
    // plugins + minor cores, majors always held. One detached run, serialized
    // by the script's own lock; per-install failures roll back and continue.
    public function updateSafe(): void {
        Auth::requireRole(['admin','reseller']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/fleet'); }
        $me = Auth::user();
        $scope = $me['role'] === 'reseller' ? (string)(int)$me['id'] : '0';
        $r = ServiceManager::run('fleet-update.sh', ['all', 'safe', $scope]);
        Helpers::log("fleet mass-safe scope=$scope by {$me['username']}: ".trim($r['out'] ?? ''));
        Helpers::flash('ok', trim($r['out'] ?? 'mass update started').' — progress in Logs → fleet');
        Helpers::redirect('/fleet');
    }
    // Dry-run report: what a mass pass WOULD touch, from the last scan rows.
    // Read-only — no script runs, nothing changes.
    public function dryRun(): void {
        Auth::requireRole(['admin','reseller']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/fleet'); }
        $me = Auth::user();
        $where = 'v.update_available=1';
        $args = [];
        if ($me['role'] === 'reseller') { $where .= ' AND u.owner_id=?'; $args = [(int)$me['id']]; }
        $preview = Database::query("SELECT u.username, d.domain, v.live_version, v.latest_version FROM site_installs s JOIN users u ON u.id=s.user_id JOIN domains d ON d.id=s.domain_id JOIN app_versions v ON v.install_id=s.id WHERE $where ORDER BY d.domain", $args)->fetchAll();
        $this->render($preview);
    }
}
