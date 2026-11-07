<?php
declare(strict_types=1);

class Controller {
    protected function view(string $page, array $data = []): void {
        extract($data);
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
        $quota = $user ? Helpers::quota($quotaUser) : null;
        $bwBytes = $user ? Helpers::bandwidthMonthBytes($user) : null;
        $flash_ok = Helpers::flash('ok');
        $flash_err = Helpers::flash('err');
        $unread = $user ? Helpers::unreadCount($user) : 0;
        // reseller white-label for the viewed user (impersonation shows the client's)
        $__brand = $user ? Branding::forUser($user) : null;
        // NOTE: never name a view variable $content — the layout slot uses $__view
        $__view = __DIR__ . "/../Views/pages/{$page}.php";
        require __DIR__ . '/../Views/layout.php';
    }
    protected function json($data, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}
