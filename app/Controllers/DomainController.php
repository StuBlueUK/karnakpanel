<?php
declare(strict_types=1);
class DomainController extends Controller {
    public function list(): void {
        Auth::requireLogin();
        $u = Auth::user();
        $page = max(1, (int)($_GET['page'] ?? 1));
        $q = substr(trim((string)($_GET['q'] ?? '')), 0, 64);
        $aliases = [];
        try { $aliases = Database::query("SELECT a.*, d.domain FROM domain_aliases a JOIN domains d ON d.id=a.domain_id WHERE a.user_id=? ORDER BY a.alias", [$u['id']])->fetchAll(); } catch (Throwable $e) {}
        $previews = [];
        try {
            $psql = ($u['role'] === 'admin')
                ? "SELECT p.*, d.domain FROM preview_tokens p JOIN domains d ON d.id=p.domain_id ORDER BY d.domain"
                : "SELECT p.*, d.domain FROM preview_tokens p JOIN domains d ON d.id=p.domain_id WHERE p.user_id=? ORDER BY d.domain";
            $previews = Database::query($psql, ($u['role'] === 'admin') ? [] : [$u['id']])->fetchAll();
        } catch (Throwable $e) {}
        // NOTE: never pass a view var named $page — Controller::view() takes
        // $page as the template name and extract() would clobber it.
        $tab = ($_GET['tab'] ?? 'domains') === 'subdomains' ? 'subdomains' : 'domains';
        // Full in-scope list, split once: top-level domains own the Domains
        // tab, subdomains (name ends in ".<owned domain>") own the Subdomains
        // tab. Split in PHP over one query — no extra round-trips, and the
        // tab counts stay exact. Search filters the split lists the same way
        // the old SQL LIKE did (case-insensitive substring).
        $all = $u['role'] === 'admin'
            ? Database::query("SELECT d.*, u.username FROM domains d JOIN users u ON u.id=d.user_id ORDER BY d.id DESC")->fetchAll()
            : Database::query("SELECT d.*, u.username FROM domains d JOIN users u ON u.id=d.user_id WHERE d.user_id=? ORDER BY d.id DESC", [$u['id']])->fetchAll();
        $subs = []; $tops = [];
        foreach ($all as $d) {
            $p = DomainModel::parentOf($d['domain'], (int)$d['user_id']);
            if ($p) { $d['parent_domain'] = $p['domain']; $subs[] = $d; }
            else $tops[] = $d;
        }
        if ($q !== '') {
            $tops = array_values(array_filter($tops, fn($d) => stripos($d['domain'], $q) !== false));
            $subs = array_values(array_filter($subs, fn($d) => stripos($d['domain'], $q) !== false));
        }
        $p = Helpers::paginate(count($tops), $page);
        $domains = array_slice($tops, $p['offset'], $p['limit']);
        $pg = $p['pg']; $pages = $p['pages']; $total = $p['total'];
        $parents = $tops;
        // rewrite pills: has-rules + stale-since-import (the docroot
        // .htaccess drifts on app updates — WordPress rewrites it).
        // Hash failures fail OPEN (no pill) — a missing/unreadable file
        // must never cry stale.
        foreach ($domains as &$dd) {
            [$dd['has_rules'], $dd['rules_stale']] = self::rewriteFlags($dd);
        }
        unset($dd);
        foreach ($subs as &$dd) {
            [$dd['has_rules'], $dd['rules_stale']] = self::rewriteFlags($dd);
        }
        unset($dd);
        // rewrite editor (?rewrite=id): owned domain + stored rules + last report
        $rwdom = null;
        try {
            $rwid = (int)($_GET['rewrite'] ?? 0);
            if ($rwid > 0) {
                $rd = Database::query("SELECT * FROM domains WHERE id=?", [$rwid])->fetch();
                if ($rd && (($u['role'] ?? '') === 'admin' || (int)$rd['user_id'] === (int)$u['id'])) $rwdom = $rd;
            }
        } catch (Throwable $e) {}
        $this->view('domains', compact('domains','aliases','pg','pages','total','previews','q','rwdom','tab','subs','parents'));
    }
    // Rewrite pills helper (panel list + API): [has_rules, stale]. Stale =
    // imported rules whose source .htaccess has since changed. Fail-open.
    public static function rewriteFlags(array $d): array {
        $has = trim((string)($d['rewrite_rules'] ?? '')) !== '';
        $stale = false;
        if ($has && !empty($d['rewrite_src_hash'])) {
            $ht = rtrim((string)($d['docroot'] ?? '')).'/.htaccess';
            if (is_file($ht) && is_readable($ht) && filesize($ht) <= 65536) {
                $h = @hash_file('sha256', $ht);
                if (is_string($h) && $h !== '' && !hash_equals((string)$d['rewrite_src_hash'], $h)) $stale = true;
            }
        }
        return [$has, $stale];
    }
    // Shared by the panel list and the API: same validation, same errors.
    // Returns [rules|null, error|null]. Rules are RewriteCond/RewriteRule/
    // comments/blank ONLY (anything else is refused — the sync script's
    // whitelist is the load-bearing gate; this is immediate feedback).
    public static function cleanRules(string $raw): array {
        $raw = str_replace("\0", '', $raw);
        if (strlen($raw) > 16384) return [null, 'Rules too long (16KB max)'];
        $out = [];
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            $t = rtrim($line);
            if ($t === '' || str_starts_with(ltrim($t), '#')) { $out[] = $t; continue; }
            if (preg_match('/^\s*Rewrite(Cond|Rule)\s+\S/', $t)) {
                // type/handler flags would let a rule serve PHP source as text
                // (e.g. another account's wp-config.php through a symlink)
                if (preg_match('/\[(?:[^\]]*[,\s])?\s*(?:T|type|H|handler)\s*=/i', $t)) {
                    return [null, 'RewriteRule T=/H= (type/handler) flags are not allowed (got: '.substr($t, 0, 60).')'];
                }
                $out[] = $t; continue;
            }
            return [null, 'Only RewriteCond/RewriteRule lines allowed (got: '.substr($t, 0, 60).')'];
        }
        return [implode("\n", $out), null];
    }
    // Editor return URL: subdomains reopen on their own tab.
    private static function rewriteBack(array $d): string {
        $sub = DomainModel::parentOf((string)$d['domain'], (int)$d['user_id']) ? 'tab=subdomains&' : '';
        return '/domains?'.$sub.'rewrite='.(int)$d['id'];
    }
    public function rewriteSave(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/domains'); }
        $u = Auth::user();
        $id = (int)($_POST['id'] ?? 0);
        $d = Database::query("SELECT * FROM domains WHERE id=?", [$id])->fetch();
        if (!$d || (($u['role'] ?? '') !== 'admin' && (int)$d['user_id'] !== (int)$u['id'])) {
            Helpers::flash('err','Domain not found'); Helpers::redirect('/domains');
        }
        [$rules, $err] = self::cleanRules((string)($_POST['rules'] ?? ''));
        if ($err !== null) { Helpers::flash('err', $err); Helpers::redirect(self::rewriteBack($d)); }
        // hand save = editor is the source of truth (clears import tracking)
        Database::query("UPDATE domains SET rewrite_rules=?, rewrite_src_hash=NULL WHERE id=?", [$rules, $id]);
        $r = ServiceManager::run('rewrite-sync.sh', [$d['domain']]);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Rules refused: '.trim($r['out']));
        else Helpers::flash('ok', trim($r['out']));
        Helpers::redirect(self::rewriteBack($d));
    }
    // .htaccess import: converts what OLS understands (Rewrite*), REPORTS
    // everything else line-by-line (never silently drops). Reads the docroot
    // file directly (panel worker ACLs); domain ownership checked first.
    public static function convertHtaccess(string $src): array {
        $rules = [];
        $report = [];
        $converted = 0; $skipped = 0;
        $safeFlags = ['L','NC','QSA','NE','R','R=301','R=302','F','G','OR'];
        foreach (preg_split('/\r?\n/', str_replace("\0", '', $src)) as $ln => $line) {
            $t = trim($line);
            if ($t === '' || str_starts_with($t, '#')) continue;
            $n = $ln + 1;
            if (preg_match('/^RewriteEngine\s+on$/i', $t)) { $rules[] = "# line $n: RewriteEngine on (OLS rewrites are always on at vhost level)"; $converted++; continue; }
            if (preg_match('/^RewriteEngine\s+/i', $t)) { $report[] = "line $n: RewriteEngine off cannot be honored (rules always active) — SKIPPED"; $skipped++; continue; }
            if (preg_match('/^RewriteBase\s+\/\s*$/', $t)) { $rules[] = "# line $n: RewriteBase / (OLS serves from the docroot context)"; $converted++; continue; }
            if (preg_match('/^RewriteBase\s+/i', $t)) { $report[] = "line $n: RewriteBase with sub-path needs manual mapping — SKIPPED"; $skipped++; continue; }
            if (preg_match('/^RewriteCond\s+(\S+)\s+(\S+)(\s+\[(.+)\])?$/i', $t, $m)) {
                $flags = array_map('trim', explode(',', strtoupper($m[4] ?? '')));
                $bad = array_filter($flags, fn($f) => $f !== '' && !in_array($f, ['NC','OR'], true));
                if ($bad !== []) { $report[] = "line $n: RewriteCond flag(s) ".implode(',', $bad)." unsupported — SKIPPED"; $skipped++; continue; }
                $rules[] = "RewriteCond {$m[1]} {$m[2]}".(($m[4] ?? '') !== '' ? " [{$m[4]}]" : '');
                $converted++; continue;
            }
            if (preg_match('/^RewriteRule\s+(\S+)\s+(\S+)(\s+\[(.+)\])?$/i', $t, $m)) {
                $flags = array_map('trim', explode(',', strtoupper($m[4] ?? '')));
                $bad = array_filter($flags, fn($f) => $f !== '' && !in_array($f, $safeFlags, true));
                if ($bad !== []) { $report[] = "line $n: RewriteRule flag(s) ".implode(',', $bad)." unsupported — SKIPPED"; $skipped++; continue; }
                // OLS runs vhost-level rules WITHOUT the leading slash
                // (per-dir style — proven live: ^magic$ fires on /magic,
                // ^/magic$ never does). Patterns/subs pass through untouched.
                $rules[] = "RewriteRule {$m[1]} {$m[2]}".(($m[4] ?? '') !== '' ? " [{$m[4]}]" : '');
                $converted++; continue;
            }
            if (preg_match('/^php_(value|flag)\s+(\S+)/i', $t, $m)) { $report[] = "line $n: {$m[1]} {$m[2]} — set it on the PHP page instead (per-user php.ini) — SKIPPED"; $skipped++; continue; }
            if (preg_match('/^Options\s+/i', $t)) { $report[] = "line $n: Options has no OLS equivalent — SKIPPED"; $skipped++; continue; }
            if (preg_match('/^(AuthType|AuthName|Require|AuthUserFile)/i', $t)) { $report[] = "line $n: password protection needs Directory Privacy (not yet built) — SKIPPED"; $skipped++; continue; }
            if (preg_match('/^ErrorDocument\s+/i', $t)) { $report[] = "line $n: ErrorDocument needs the error-pages editor (not yet built) — SKIPPED"; $skipped++; continue; }
            if (preg_match('/^(Header|SetEnvIf|SetEnv|ExpiresActive|ExpiresDefault|ExpiresByType|AddType|AddHandler|DirectoryIndex)/i', $t)) { $report[] = "line $n: ".strtok($t, ' ')." has no OLS mapping — SKIPPED"; $skipped++; continue; }
            if (preg_match('/^(Deny|Allow|Order)\s+/i', $t)) { $report[] = "line $n: Apache 2.2 access control is obsolete — manage IPs on the Firewall page — SKIPPED"; $skipped++; continue; }
            $report[] = "line $n: not understood (".substr($t, 0, 50).") — SKIPPED";
            $skipped++;
        }
        return [$rules, $converted, $skipped, $report];
    }
    public function htaccessImport(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/domains'); }
        $u = Auth::user();
        $id = (int)($_POST['id'] ?? 0);
        $d = Database::query("SELECT * FROM domains WHERE id=?", [$id])->fetch();
        if (!$d || (($u['role'] ?? '') !== 'admin' && (int)$d['user_id'] !== (int)$u['id'])) {
            Helpers::flash('err','Domain not found'); Helpers::redirect('/domains');
        }
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- path from owned domain row + fixed filename (docroot itself is trusted server data)
        $ht = rtrim((string)$d['docroot']).'/.htaccess';
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- read-only existence check on owned-row docroot + fixed name (docroot is server-created from a validated domain, never request data)
        if (!is_file($ht) || !is_readable($ht)) { Helpers::flash('err','No readable .htaccess in the docroot'); Helpers::redirect(self::rewriteBack($d)); }
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- read-only size cap on the same owned-row path as above
        if (filesize($ht) > 65536) { Helpers::flash('err','.htaccess too big (64KB max)'); Helpers::redirect(self::rewriteBack($d)); }
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- read-only parse of the same owned-row path as above (converter whitelist gates what reaches the vhost)
        [$rules, $converted, $skipped, $report] = self::convertHtaccess((string)file_get_contents($ht));
        if ($converted === 0) { Helpers::flash('err','Nothing convertible: '.implode(' · ', array_slice($report, 0, 6))); Helpers::redirect(self::rewriteBack($d)); }
        [$clean, $err] = self::cleanRules(implode("\n", $rules));
        if ($err !== null) { Helpers::flash('err','Converter produced bad output (refused): '.$err); Helpers::redirect(self::rewriteBack($d)); }
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- read-only hash of the same owned-row path as above (import tracking, never served)
        $srcHash = hash('sha256', (string)file_get_contents($ht));
        Database::query("UPDATE domains SET rewrite_rules=?, rewrite_src_hash=?, rewrite_imported_at=NOW() WHERE id=?", [$clean, $srcHash, $id]);
        $r = ServiceManager::run('rewrite-sync.sh', [$d['domain']]);
        $msg = "Imported $converted rule(s), skipped $skipped.";
        if (!empty($report)) $msg .= " Skipped: ".implode(' · ', array_slice($report, 0, 8)).(count($report) > 8 ? ' (+'.(count($report)-8).' more)' : '');
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err', $msg.' Sync refused: '.trim($r['out']));
        else Helpers::flash('ok', $msg.' '.trim($r['out']));
        Helpers::redirect(self::rewriteBack($d));
    }
    public function create(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/domains'); }
        $domain = strtolower(trim($_POST['domain']??''));
        $u = Auth::user();
        if (!Helpers::validDomain($domain)) { Helpers::flash('err','Invalid domain'); Helpers::redirect('/domains'); }
        // refuse subdomains of a domain you own — those live on the
        // Subdomains tab (A record in the parent zone, no zone of their own).
        if ($parent = DomainModel::parentOf($domain, (int)$u['id'])) {
            Helpers::flash('err', "$domain is a subdomain of {$parent['domain']} — add it on the Subdomains tab");
            Helpers::redirect('/domains');
        }
        $php = DomainProvisioner::effectivePhp((int)$u['id'], (string)($_POST['php_version'] ?? ''));
        if ($err = Helpers::limitError($u, 'domains')) { Helpers::flash('err', $err); Helpers::redirect('/domains'); }
        try {
            [, $r, $r2] = DomainProvisioner::createTop($u, $domain, $php);
            Helpers::flash('ok',"Domain $domain added. ".$r['out']." ".$r2['out']);
        } catch (Throwable $e) { Helpers::flash('err','Failed: '.$e->getMessage()); }
        Helpers::redirect('/domains');
    }
    public function delete(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/domains'); }
        $u = Auth::user();
        $id = (int)($_POST['id'] ?? 0);
        $d = Database::query("SELECT * FROM domains WHERE id=?", [$id])->fetch();
        if (!$d || ($u['role'] !== 'admin' && (int)$d['user_id'] !== (int)$u['id'])) {
            Helpers::flash('err','Domain not found'); Helpers::redirect('/domains');
        }
        // owner username for system paths: look up, never trust the row blindly
        $owner = Database::query("SELECT username FROM users WHERE id=?", [$d['user_id']])->fetch()['username'] ?? '';
        if (!Helpers::validUsername($owner)) { Helpers::flash('err','Owner account invalid — DB row removed only'); }
        try {
            $mails = (int)Database::query("SELECT COUNT(*) c FROM email_accounts WHERE domain_id=?", [$d['id']])->fetch()['c'];
            Database::query("DELETE FROM email_accounts WHERE domain_id=?", [$d['id']]);
            ServiceManager::run('autoreply-sync.sh', []);
            Database::query("DELETE FROM dns_records WHERE domain_id=?", [$d['id']]);
            // a zone-less child's records (A, mail, policy) are rows of its
            // parent zone — drop them while the row still names it
            MailDns::purgeChild($d);
            Database::query("DELETE FROM domains WHERE id=?", [$d['id']]);
            $notes = [];
            if (Helpers::validUsername($owner)) {
                $notes[] = ServiceManager::run('vhost-remove.sh', [$d['domain'], $owner])['out'] ?? '';
                $notes[] = ServiceManager::run('dns-remove-zone.sh', [$d['domain']])['out'] ?? '';
                // orphan LE certs renew-fail forever against dead DNS — drop it
                // (refuses while any domain row still references the name)
                $notes[] = ServiceManager::run('ssl-remove.sh', [$d['domain']])['out'] ?? '';
            }
            // parked aliases die with their domain (vhost line + zone each)
            $als = Database::query("SELECT alias FROM domain_aliases WHERE domain_id=?", [$d['id']])->fetchAll();
            foreach ($als as $a) ServiceManager::run('vhost-unalias.sh', [$d['domain'], $a['alias']]);
            // aggregate reports (dmarc_records cascade); maps + spool go with vhost-remove
            MailAutopilot::purgeDomain((int)$d['id']);
            DomainModel::purgeRows((int)$d['id']);
            ServiceManager::run('email-filters-sync.sh', []);
            ServiceManager::run('list-sync.sh', []);
            // converge the pool: deleting the odd-version domain can strand the
            // shared pool on a version none of the remaining domains want
            // (Oct 2026 forum outage). Move it to the first sibling's version.
            $sib = Database::query("SELECT * FROM domains WHERE user_id=? LIMIT 1", [$d['user_id']])->fetch();
            $pnote = '';
            if ($sib && PhpVersions::valid((string)($sib['php_version'] ?? ''))) {
                $kids = Helpers::poolChildren(['username' => $owner, 'id' => $d['user_id']]) ?? 10;
                $pr = ServiceManager::run('php-switch.sh', [$sib['domain'], $sib['php_version'], (string)$kids]);
                if (($pr['code'] ?? 1) === 0) $pnote = ' Pool converged to '.$sib['php_version'].' ('.trim($pr['out']).').';
            }
            Helpers::flash('ok', "Domain {$d['domain']} removed (vhost, DNS, $mails mailbox(es)). ".implode(' ', $notes).$pnote);
        } catch (Throwable $e) { Helpers::flash('err','Remove failed: '.$e->getMessage()); }
        Helpers::redirect('/domains');
    }
    public function maintenance(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/domains'); }
        $u = Auth::user();
        $id = (int)($_POST['id'] ?? 0);
        $d = Database::query("SELECT * FROM domains WHERE id=?", [$id])->fetch();
        if (!$d || ($u['role'] !== 'admin' && (int)$d['user_id'] !== (int)$u['id'])) {
            Helpers::flash('err','Domain not found'); Helpers::redirect('/domains');
        }
        $to = empty($d['maintenance']) ? 'on' : 'off';
        $r = ServiceManager::run('maintenance.sh', [$d['domain'], $to]);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Toggle failed: '.trim($r['out']));
        else {
            Database::query("UPDATE domains SET maintenance=? WHERE id=?", [$to === 'on' ? 1 : 0, $id]);
            Helpers::flash('ok', $to === 'on' ? "Maintenance ON for {$d['domain']} (holding page live)" : "Maintenance OFF — site restored");
        }
        Helpers::redirect('/domains');
    }
    public function waf(): void {        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/domains'); }
        $u = Auth::user();
        $id = (int)($_POST['id'] ?? 0);
        $d = Database::query("SELECT * FROM domains WHERE id=?", [$id])->fetch();
        if (!$d || ($u['role'] !== 'admin' && (int)$d['user_id'] !== (int)$u['id'])) {
            Helpers::flash('err','Domain not found'); Helpers::redirect('/domains');
        }
        $to = empty($d['waf'] ?? 1) ? 'on' : 'off';
        $r = ServiceManager::run('waf-shield.sh', [$d['domain'], $to]);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Shield toggle failed: '.trim($r['out']));
        else {
            Database::query("UPDATE domains SET waf=? WHERE id=?", [$to === 'on' ? 1 : 0, $id]);
            Helpers::flash('ok', $to === 'on' ? "Shield ON for {$d['domain']} (bot traffic blocked)" : "Shield OFF for {$d['domain']} — site unfiltered");
        }
        Helpers::redirect('/domains');
    }
    // Mail handling local↔remote (cPanel Email Routing equivalent). Remote =
    // inbound accepted, delivered by PUBLIC MX; local mailboxes/forwards/
    // lists/autoresponders go dormant (rows kept) and reactivate on the way
    // back. Autoconfig XML follows the mode so clients aren't pointed at us
    // while a provider serves the domain.
    public function mailRouting(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/domains'); }
        $u = Auth::user();
        $id = (int)($_POST['id'] ?? 0);
        $d = Database::query("SELECT * FROM domains WHERE id=?", [$id])->fetch();
        if (!$d || ($u['role'] !== 'admin' && (int)$d['user_id'] !== (int)$u['id'])) {
            Helpers::flash('err','Domain not found'); Helpers::redirect('/domains');
        }
        $owner = Database::query("SELECT username FROM users WHERE id=?", [$d['user_id']])->fetch()['username'] ?? '';
        if (!Helpers::validUsername($owner)) { Helpers::flash('err','Owner account invalid'); Helpers::redirect('/domains'); }
        $to = (($d['mail_handling'] ?? 'local') === 'remote') ? 'local' : 'remote';
        // upgraders from <=1.5.8 lack the remote_route router (fresh installs
        // render it; panel updates never touch exim.conf). Ensure it exists
        // before the first remote toggle — no-op afterwards.
        if ($to === 'remote') {
            $pr = ServiceManager::run('exim-remote-patch.sh', []);
            if (($pr['code'] ?? 1) !== 0) { Helpers::flash('err','Mail router setup failed: '.trim($pr['out'])); Helpers::redirect('/domains'); }
        }
        $r = ServiceManager::run('mail-routing.sh', [$d['domain'], $to]);
        if (($r['code'] ?? 1) !== 0) { Helpers::flash('err','Mail routing failed: '.trim($r['out'])); Helpers::redirect('/domains'); }
        Database::query("UPDATE domains SET mail_handling=? WHERE id=?", [$to, $id]);
        $pol = ($to === 'remote') ? MailPolicyController::goingRemote($d) : '';
        $an = ($to === 'remote')
            ? ServiceManager::run('mail-autoconfig.sh', [$d['domain'], $owner, 'remove'])
            : ServiceManager::run('mail-autoconfig.sh', [$d['domain'], $owner]);
        Helpers::flash('ok', $to === 'remote'
            ? "Mail for {$d['domain']} now routes to your external provider (public MX). Local mailboxes kept but dormant. ".trim($an['out'] ?? '').($pol !== '' ? ' '.$pol : '')
            : "Mail for {$d['domain']} delivers locally again (dormant mailboxes reactivated). ".trim($an['out'] ?? ''));
        Helpers::redirect('/domains');
    }
    public function aliasCreate(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/domains'); }
        $u = Auth::user();
        $domainId = (int)($_POST['domain_id'] ?? 0);
        $alias = strtolower(trim($_POST['alias'] ?? ''));
        if (!Helpers::validDomain($alias)) { Helpers::flash('err','Bad alias domain'); Helpers::redirect('/domains'); }
        $d = Database::query("SELECT * FROM domains WHERE id=?", [$domainId])->fetch();
        if (!$d || ($u['role'] !== 'admin' && (int)$d['user_id'] !== (int)$u['id'])) {
            Helpers::flash('err','Domain not found'); Helpers::redirect('/domains');
        }
        // alias must not collide with a real domain, mailbox domain, or another alias
        $taken = Database::query("SELECT 1 FROM domains WHERE domain=? UNION SELECT 1 FROM domain_aliases WHERE alias=? LIMIT 1", [$alias, $alias])->fetch();
        if ($taken) { Helpers::flash('err','That name is already hosted here'); Helpers::redirect('/domains'); }
        try {
            Database::query("INSERT INTO domain_aliases(user_id,domain_id,alias) VALUES(?,?,?)", [$u['id'], $d['id'], $alias]);
            $r = ServiceManager::run('vhost-alias.sh', [$d['domain'], $alias]);
            if (($r['code'] ?? 1) !== 0) {
                Database::query("DELETE FROM domain_aliases WHERE alias=?", [$alias]);
                Helpers::flash('err','Park failed: '.trim($r['out']));
            } else Helpers::flash('ok', "Parked $alias → {$d['domain']} (web-only, no mailboxes)");
        } catch (Throwable $e) { Helpers::flash('err','Park failed: '.$e->getMessage()); }
        Helpers::redirect('/domains');
    }
    // Preview URLs: unguessable token host served as a vhost alias, with a
    // *.preview wildcard A record rendered into the (locally hosted) zone.
    // HTTP only — no cert exists for random hosts. Revoking kills the alias.
    public function previewCreate(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/domains'); }
        $u = Auth::user();
        $domainId = (int)($_POST['domain_id'] ?? 0);
        $d = Database::query("SELECT * FROM domains WHERE id=?", [$domainId])->fetch();
        if (!$d || ($u['role'] !== 'admin' && (int)$d['user_id'] !== (int)$u['id'])) {
            Helpers::flash('err','Domain not found'); Helpers::redirect('/domains');
        }
        $token = bin2hex(random_bytes(12));
        try {
            Database::query("INSERT INTO preview_tokens(user_id,domain_id,token) VALUES(?,?,?)", [$u['id'], $d['id'], $token]);
            // wildcard DNS (once per domain) through the normal zone pipeline
            $has = Database::query("SELECT 1 FROM dns_records WHERE domain_id=? AND type='A' AND host='*.preview' LIMIT 1", [$d['id']])->fetch();
            if (!$has) {
                $ip = trim(ServiceManager::run('server-ip.sh', [])['out'] ?? '');
                if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $ip = '192.0.2.1';
                Database::query("INSERT INTO dns_records(domain_id,type,host,value,priority,ttl) VALUES(?,'A','*.preview',?,10,3600)", [$d['id'], $ip]);
                (new DnsController())->apply((int)$d['id'], $d['domain']);
            }
            $r = ServiceManager::run('preview-add.sh', [$d['domain'], $token]);
            if (($r['code'] ?? 1) !== 0) { Database::query("DELETE FROM preview_tokens WHERE token=?", [$token]); Helpers::flash('err','Preview failed: '.trim($r['out'])); }
            else Helpers::flash('ok', "Preview live: http://preview-$token.{$d['domain']}/ (HTTP only, needs public DNS to resolve)");
        } catch (Throwable $e) { Helpers::flash('err','Preview failed: '.$e->getMessage()); }
        Helpers::redirect('/domains');
    }
    public function previewDelete(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/domains'); }
        $u = Auth::user();
        $id = (int)($_POST['id'] ?? 0);
        $row = Database::query("SELECT p.*, d.domain FROM preview_tokens p JOIN domains d ON d.id=p.domain_id WHERE p.id=?", [$id])->fetch();
        if (!$row || ($u['role'] !== 'admin' && (int)$row['user_id'] !== (int)$u['id'])) {
            Helpers::flash('err','Preview not found'); Helpers::redirect('/domains');
        }
        Database::query("DELETE FROM preview_tokens WHERE id=?", [$id]);
        ServiceManager::run('preview-remove.sh', [$row['domain'], $row['token']]);
        Helpers::flash('ok', "Preview revoked for {$row['domain']}");
        Helpers::redirect('/domains');
    }
    public function aliasDelete(): void {        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/domains'); }
        $u = Auth::user();
        $id = (int)($_POST['id'] ?? 0);
        $a = Database::query("SELECT a.*, d.domain FROM domain_aliases a JOIN domains d ON d.id=a.domain_id WHERE a.id=?", [$id])->fetch();
        if (!$a || ($u['role'] !== 'admin' && (int)$a['user_id'] !== (int)$u['id'])) {
            Helpers::flash('err','Alias not found'); Helpers::redirect('/domains');
        }
        Database::query("DELETE FROM domain_aliases WHERE id=?", [$id]);
        ServiceManager::run('vhost-unalias.sh', [$a['domain'], $a['alias']]);
        Helpers::flash('ok', "Unparked {$a['alias']}"); Helpers::redirect('/domains');
    }
}
