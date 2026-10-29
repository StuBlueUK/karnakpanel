<?php
declare(strict_types=1);
class SslController extends Controller {
    // above ssl-issue.sh's own 150 s certbot bound, inside the 330 s backend limit
    public const ISSUE_TIMEOUT = 200;
    // Old page is gone (merged into Domains) — bookmarks land there.
    public function list(): void {
        Auth::requireLogin();
        Helpers::redirect('/domains');
    }
    // ACME contact is ALWAYS the domain owner's account email (LE uses it
    // only for expiry notices — per-issuance customization was worthless).
    // null = no usable address (ACME rejects admin@localhost & co).
    public static function acmeEmail(int $userId): ?string {
        $owner = Database::query("SELECT email FROM users WHERE id=?", [$userId])->fetch();
        $email = strtolower(trim((string)($owner['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/@[^@]+\.[a-z]{2,}$/i', $email) || str_ends_with($email, '@localhost')) return null;
        return $email;
    }
    // Background requests (ssl-issue-bg.sh): domain => [running|ok|fail, reason],
    // limited to $own (the caller's in-scope domain names). One sudo call.
    public static function bgStates(array $own): array {
        $out = [];
        if (!$own) return $out;
        $own = array_flip($own);
        try { $raw = (string)(ServiceManager::run('ssl-issue-bg.sh', ['pending'])['out'] ?? ''); } catch (Throwable $e) { return $out; }
        foreach (preg_split('/\R/', $raw) as $line) {
            $f = explode("\t", $line, 3);
            if (count($f) < 2 || !isset($own[$f[0]]) || !in_array($f[1], ['running', 'ok', 'fail'], true)) continue;
            $out[$f[0]] = [$f[1], $f[2] ?? ''];
        }
        return $out;
    }
    // GET /ssl/pending — the Domains page polls this while a row spins.
    public function pending(): void {
        Auth::requireLogin();
        $u = Auth::user();
        $rows = $u['role'] === 'admin'
            ? Database::query("SELECT domain FROM domains")->fetchAll(PDO::FETCH_COLUMN)
            : Database::query("SELECT domain FROM domains WHERE user_id=?", [$u['id']])->fetchAll(PDO::FETCH_COLUMN);
        $st = [];
        foreach (self::bgStates(array_map('strval', $rows)) as $d => [$state]) $st[$d] = $state;
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode(['states' => (object)$st], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        exit;
    }
    public function issue(): void {
        Auth::requireLogin();
        // subdomain rows post back=subdomains (fixed whitelist — no open redirect)
        $back = ($_POST['back'] ?? '') === 'subdomains' ? '/domains?tab=subdomains' : '/domains';
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect($back); }
        $u = Auth::user();
        // per-row button posts the id (old form posted the name — accept both)
        $domain = trim($_POST['domain'] ?? '');
        if ($domain === '' && isset($_POST['id'])) {
            $d = Database::query("SELECT * FROM domains WHERE id=?", [(int)$_POST['id']])->fetch();
            $domain = $d ? $d['domain'] : '';
        } else {
            $d = $domain !== '' ? Database::query("SELECT * FROM domains WHERE domain=?", [$domain])->fetch() : null;
        }
        if (!$d || ($u['role'] !== 'admin' && (int)$d['user_id'] !== (int)$u['id'])) {
            Helpers::flash('err','Domain not found'); Helpers::redirect($back);
        }
        $email = self::acmeEmail((int)$d['user_id']);
        if ($email === null) {
            Helpers::flash('err','Domain owner needs a real account email first (Accounts page) — ACME rejects admin@localhost'); Helpers::redirect($back);
        }
        $args = [$domain, $email];
        // services flag rewrites BOX-level listeners (panel/webmail/pma) —
        // admin-only; a forged reseller POST with services=1 is dropped here
        if (!empty($_POST['services']) && ($u['role'] ?? '') === 'admin') $args[] = 'services';
        $r = ServiceManager::run('ssl-issue.sh', $args, self::ISSUE_TIMEOUT);
        if (str_contains($r['out'],'Congratulations') || $r['code']===0) {
            Database::query("UPDATE domains SET ssl_enabled=1 WHERE domain=?", [$domain]);
            // refresh expiry now so the row shows pills immediately (not blank
            // until the nightly run); no-op when there is still no cert.
            try { ServiceManager::run('expiry-check.sh', [$domain]); } catch (Throwable $e) {}
            // the forgotten step: box listeners (:8090/:8091/:8092) stay on
            // the install self-signed cert until someone presses Services.
            // If they are STILL self-signed, apply this domain now (admin
            // only — a reseller's cert must never land on shared listeners).
            // Never clobbers a real cert: fires solely from the self state.
            $extra = '';
            if (($u['role'] ?? '') === 'admin') {
                try {
                    $st = trim(ServiceManager::run('ssl-services-state.sh', [])['out'] ?? '');
                    if ($st === 'self') {
                        $sr = ServiceManager::run('ssl-issue.sh', [$domain, $email, 'services'], self::ISSUE_TIMEOUT);
                        if (($sr['code'] ?? 1) === 0) $extra = ' — and applied to panel services (they were still self-signed)';
                        else $extra = ' — panel services still need one click on the Services page';
                        Helpers::log("ssl auto-services for $domain by {$u['username']}: ".trim($sr['out'] ?? ''));
                    }
                } catch (Throwable $e) {}
            }
            Helpers::flash('ok',"SSL issued for $domain$extra");
        } else Helpers::flash('err',"SSL failed: ".$r['out']);
        Helpers::redirect($back);
    }
    // Box-level cert for panel/webmail/phpMyAdmin listeners (admin Services
    // page). Reuses a domain's LE cert — the old per-issue checkbox's home.
    public function applyServices(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/services'); }
        $d = Database::query("SELECT * FROM domains WHERE id=?", [(int)($_POST['id'] ?? 0)])->fetch();
        if (!$d || empty($d['ssl_enabled'])) { Helpers::flash('err','Pick a domain that already has SSL'); Helpers::redirect('/admin/services'); }
        $owner = Database::query("SELECT email FROM users WHERE id=?", [$d['user_id']])->fetch();
        $email = strtolower(trim((string)($owner['email'] ?? '')));
        $r = ServiceManager::run('ssl-issue.sh', [$d['domain'], $email, 'services'], self::ISSUE_TIMEOUT);
        Helpers::flash(($r['code'] ?? 1) === 0 ? 'ok' : 'err', ($r['code'] ?? 1) === 0 ? "Panel services now use {$d['domain']} cert" : 'Apply failed: '.trim($r['out']));
        Helpers::redirect('/admin/services');
    }
}
