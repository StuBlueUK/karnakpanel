<?php
declare(strict_types=1);

class AdminDiskController extends Controller {
    public function disk(): void {
        Auth::requireRole(['admin']);
        $usage = [];
        $r = ServiceManager::run('disk-usage.sh', []);
        if (($r['code'] ?? 1) === 0) {
            foreach (explode("\n", trim($r['out'] ?? '')) as $line) {
                $p = explode("\t", $line);
                if (count($p) === 2) $usage[$p[0]] = $p[1];
            }
        }
        $this->view('admin_disk', compact('usage'));
    }
    public function diskClean(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/disk'); }
        $t = (string)($_POST['target'] ?? '');
        if (!in_array($t, ['staging','sessions','releases'], true)) { Helpers::flash('err','Bad target'); Helpers::redirect('/admin/disk'); }
        $r = ServiceManager::run('disk-clean.sh', [$t]);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Clean failed: '.trim($r['out']));
        else { Helpers::log("disk clean $t by ".Auth::user()['username']); Helpers::flash('ok', trim($r['out'])); }
        Helpers::redirect('/admin/disk');
    }
}
