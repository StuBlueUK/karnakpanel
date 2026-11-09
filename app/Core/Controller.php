<?php
declare(strict_types=1);

class Controller {
    protected function view(string $page, array $data = []): void {
        // Templates read plain variables, so extract() stays — but the include
        // path is resolved BEFORE it and EXTR_SKIP protects the locals that
        // already exist ($page/$data/$__view). A view variable can therefore
        // never hijack the template file (structure.php also bans the reserved
        // keys page/content/data).
        $__view = __DIR__ . "/../Views/pages/{$page}.php";
        extract($data, EXTR_SKIP);
        $user = Auth::user();
        // quota from a FRESH row, not the session snapshot: disk numbers
        // move on every refresh/enforcer run while the session sits at
        // login-time values (one indexed PK read per page view)
        $quotaUser = Auth::fresh() ?? $user;
        if ($user && isset($user['id']) && Auth::fresh() === null) {
            try {
                $fresh = Database::query("SELECT * FROM users WHERE id=?", [$user['id']])->fetch();
                if ($fresh) $quotaUser = $fresh;
            } catch (Throwable $e) {}
        }
        $quota = $user ? Quota::usage($quotaUser) : null;
        $bwBytes = $user ? Quota::bandwidthMonthBytes($user) : null;
        $flash_ok = Helpers::flash('ok');
        $flash_err = Helpers::flash('err');
        $unread = $user ? Helpers::unreadCount($user) : 0;
        // reseller white-label for the viewed user (impersonation shows the client's)
        $__brand = $user ? Branding::forUser($user) : null;
        require __DIR__ . '/../Views/layout.php';
    }
    /** Ends the request. Declared never so callers need no dead `return`. */
    protected function json($data, int $code = 200): never {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}
