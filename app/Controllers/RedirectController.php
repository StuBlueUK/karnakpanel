<?php
declare(strict_types=1);
class RedirectController extends Controller {
    public function index(): void {
        Auth::requireLogin();
        $u = Auth::user();
        $scope = $u['role'] === 'admin' ? '' : 'WHERE d.user_id='.(int)$u['id'];
        $domains = Database::query("SELECT d.id, d.domain, d.rewrite_rules FROM domains d $scope ORDER BY d.domain")->fetchAll();
        $redirects = [];
        foreach ($domains as &$d) {
            $d['redirect_list'] = self::parseRedirects((string)($d['rewrite_rules'] ?? ''));
            foreach ($d['redirect_list'] as &$r) $r['domain_id'] = (int)$d['id'];
            $redirects = array_merge($redirects, $d['redirect_list']);
        }
        unset($d);
        $this->view('redirects', ['domains' => $domains, 'redirects' => $redirects]);
    }
    // Markers carry the data (code|source|target) so deletion never depends
    // on which line follows — and survives a hand-edit of the RewriteRule.
    // `|` separator: URLs contain `:`; `#` prefix keeps the line inside the
    // rewrite-sync.sh whitelist.
    public static function parseRedirects(string $rules): array {
        $out = [];
        foreach (explode("\n", $rules) as $i => $line) {
            if (preg_match('~^#\s*REDIRECT:(\d{3})\|([^\s|]*)\|(.+)$~i', trim($line), $m)) {
                $out[] = ['code' => $m[1], 'source' => $m[2], 'target' => trim($m[3]), 'line' => $i];
            }
        }
        return $out;
    }
    public function create(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/redirects'); }
        $u = Auth::user();
        $domId = (int)($_POST['domain_id'] ?? 0);
        $source = trim((string)($_POST['source'] ?? ''));
        $target = trim((string)($_POST['target'] ?? ''));
        $code = (int)($_POST['code'] ?? 301);
        $d = Database::query("SELECT * FROM domains WHERE id=?", [$domId])->fetch();
        if (!$d || ($u['role'] !== 'admin' && (int)$d['user_id'] !== (int)$u['id'])) {
            Helpers::flash('err','Domain not found'); Helpers::redirect('/redirects');
        }
        if (!in_array($code, [301, 302], true)) $code = 301;
        if ($source !== '/' && !preg_match('#^/[a-zA-Z0-9._~/?=&%-]{0,200}$#', $source)) {
            Helpers::flash('err','Bad source path'); Helpers::redirect('/redirects');
        }
        if (!preg_match('#^https?://[a-z0-9.-]+\.[a-z]{2,}(:[0-9]{1,5})?(/[^\s]*)?$#i', $target)
            || preg_match('/\s|\r|\n/', $target)) {
            Helpers::flash('err','Target must be a full http(s):// URL'); Helpers::redirect('/redirects');
        }
        // a target equal to the domain (either scheme) would bounce forever
        foreach (['http', 'https'] as $sch) {
            if (rtrim(strtolower($target), '/') === $sch.'://'.$d['domain']) {
                Helpers::flash('err','Redirect loop: target is this domain itself'); Helpers::redirect('/redirects');
            }
        }
        $rules = (string)($d['rewrite_rules'] ?? '');
        if (count(self::parseRedirects($rules)) >= 50) { Helpers::flash('err','Max 50 redirects per domain'); Helpers::redirect('/redirects'); }
        // pattern without leading slash (rewrite matches the URI), optional
        // trailing slash tolerant; everything else literal
        $bare = trim($source, '/');
        $pat = $bare === '' ? '^' : '^'.preg_quote($bare, '/');
        $rule = "RewriteRule $pat/?\$ $target [R=$code,L]";
        $marker = "# REDIRECT:$code|$source|$target";
        $newRules = (rtrim($rules) !== '' ? rtrim($rules)."\n" : '').$marker."\n".$rule;
        Database::query("UPDATE domains SET rewrite_rules=? WHERE id=?", [$newRules, $domId]);
        ServiceManager::run('rewrite-sync.sh', [$d['domain']]);
        Helpers::flash('ok',"Redirect added: $source → $target ($code)");
        Helpers::redirect('/redirects');
    }
    public function delete(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/redirects'); }
        $u = Auth::user();
        $domId = (int)($_POST['domain_id'] ?? 0);
        $line = (int)($_POST['line'] ?? -1);
        $d = Database::query("SELECT * FROM domains WHERE id=?", [$domId])->fetch();
        if (!$d || ($u['role'] !== 'admin' && (int)$d['user_id'] !== (int)$u['id'])) {
            Helpers::flash('err','Domain not found'); Helpers::redirect('/redirects');
        }
        $lines = explode("\n", (string)($d['rewrite_rules'] ?? ''));
        if ($line < 0 || $line >= count($lines)) { Helpers::flash('err','Redirect not found'); Helpers::redirect('/redirects'); }
        if (!preg_match('~^#\s*REDIRECT:(\d{3})\|~', trim($lines[$line]))) {
            Helpers::flash('err','Not a redirect marker'); Helpers::redirect('/redirects');
        }
        // drop the marker and its RewriteRule (adjacent, unless hand-split)
        array_splice($lines, $line, 1);
        if (isset($lines[$line]) && preg_match('/^RewriteRule\s/', (string)$lines[$line]) && preg_match('/\[R=\d+,L\]/', (string)$lines[$line])) {
            array_splice($lines, $line, 1);
        }
        $newRules = rtrim(implode("\n", $lines), "\n");
        // rewrite_rules is NOT NULL (schema): empty string, never null
        Database::query("UPDATE domains SET rewrite_rules=? WHERE id=?", [$newRules, $domId]);
        ServiceManager::run('rewrite-sync.sh', [$d['domain']]);
        Helpers::flash('ok','Redirect removed');
        Helpers::redirect('/redirects');
    }
}
