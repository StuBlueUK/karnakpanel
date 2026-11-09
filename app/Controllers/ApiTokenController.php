<?php
declare(strict_types=1);
// ApiTokenController — inherits the API auth/scoping helpers from ApiController.
class ApiTokenController extends ApiController {
    public function tokens(): void {
        Auth::requireRole(['admin','reseller']);
        $u = Auth::user();
        $p = Helpers::paginate(ApiModel::countTokens((int)$u['id']), max(1, (int)($_GET['page'] ?? 1)));
        $pg = $p['pg']; $pages = $p['pages']; $total = $p['total'];
        $tokens = ApiModel::tokenPage((int)$u['id'], (int)$p['limit'], (int)$p['offset']);
        $this->view('api_tokens', compact('tokens','pg','pages','total'));
    }
    public function mint(): void {
        Auth::requireRole(['admin','reseller']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/api-tokens'); }
        $u = Auth::user();
        $label = substr(trim($_POST['label'] ?? 'panel'), 0, 80);
        $token = bin2hex(random_bytes(32));
        // optional lifetime: 0 = never (billing integrations), else N days
        $days = (int)($_POST['days'] ?? 0);
        if (!in_array($days, [0, 30, 90, 365], true)) $days = 0;
        ApiModel::insertToken((int)$u['id'], $label, $token, $days);
        Helpers::flash('ok', "Token minted ($label): $token — send as X-API-KEY header. Shown only once.".($days ? " Expires in $days days." : ''));
        Helpers::redirect('/api-tokens');
    }
    public function revoke(): void {        Auth::requireRole(['admin','reseller']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/api-tokens'); }
        $u = Auth::user();
        ApiModel::deleteToken((int)($_POST['id'] ?? 0), (int)$u['id']);
        Helpers::flash('ok','Token revoked');
        Helpers::redirect('/api-tokens');
    }
    public function pin(): void {
        Auth::requireRole(['admin','reseller']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/api-tokens'); }
        $u = Auth::user();
        $ip = trim((string)($_POST['ip'] ?? ''));
        if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP)) {
            Helpers::flash('err','Not a valid IP address'); Helpers::redirect('/api-tokens');
        }
        ApiModel::setTokenIp((int)($_POST['id'] ?? 0), (int)$u['id'], $ip !== '' ? $ip : null);
        Helpers::flash('ok', $ip !== '' ? "Token pinned to $ip" : 'IP pin removed (any host)');
        Helpers::redirect('/api-tokens');
    }
    // ---- resource API: admin = any account, reseller = own subtree (direct
    // owner_id, same convention as ResellerController), user = self only ----
}
