<?php
declare(strict_types=1);
// ApiDomainController — inherits the API auth/scoping helpers from ApiController.
class ApiDomainController extends ApiController {
    public function domList(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $t = $this->scopedUser($me);
        $rows = ApiModel::domainsFor((int)$t['id']);
        foreach ($rows as &$r) {
            [$r['has_rules'], $r['rules_stale']] = DomainController::rewriteFlags($r);
            unset($r['rewrite_rules'], $r['rewrite_src_hash']);
        }
        unset($r);
        $this->json(['ok' => true, 'username' => $t['username'], 'domains' => $rows]);
    }
    public function domCreate(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $in = $this->apiInput();
        $t = $this->resolveTarget($me, (string)($in['username'] ?? $me['username']));
        $domain = strtolower(trim((string)($in['domain'] ?? '')));
        if (!Helpers::validDomain($domain)) $this->json(['error' => 'invalid domain'], 422);
        // mirrors DomainController::create (limits, seeds, scripts)
        $php = PhpVersions::orDefault(ApiModel::effectivePhp((int)$t['id']));
        if ($err = Quota::limitError($t, 'domains')) $this->json(['error' => $err], 422);
        try {
            $docroot = "/home/{$t['username']}/".$domain."/public_html";
            $domId = ApiModel::insertDomain((int)$t['id'], $domain, $docroot, $php);
            $ip = trim(ServiceManager::run('server-ip.sh', [])['out'] ?? '');
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $ip = '192.0.2.1';
            [$ns1, $ns2] = DnsController::effectiveNs((int)$t['id'], $domain);
            foreach (DnsController::defaultRows($domain, $ip, [$ns1, $ns2]) as $rec) {
                ApiModel::insertDnsRecord($domId, $rec[0], $rec[1], $rec[2], 10, 3600);
            }
            ServiceManager::run('create-account.sh', ['--repair', $t['username'], $php]);
            // re-resolve with the seeded rows: a reseller brand zone (glue seeded
            // above) advertises its own ns1/ns2, and the file must agree
            [$ns1, $ns2] = DnsController::effectiveNs((int)$t['id'], $domain, $domId);
            ServiceManager::run('dns-add-zone.sh', [$domain, $ns1, $ns2]);
            ServiceManager::run('vhost-add.sh', [$domain, $t['username'], $php]);
            ServiceManager::run('mail-autoconfig.sh', [$domain, $t['username']]);
            ServiceManager::run('expiry-check.sh', [$domain]);
            // DKIM from day one, as the Domains page does (best-effort)
            if ($row = ApiModel::domainById($domId)) {
                try { MailDns::signDomain($row); } catch (Throwable $e) {}
            }
            Helpers::log("API domain $domain created for {$t['username']} by {$me['username']}");
            $this->json(['ok' => true, 'id' => $domId, 'domain' => $domain, 'docroot' => $docroot]);
        } catch (Throwable $e) { $this->apiFailed('create', 500, $e); }
    }
    public function domDelete(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $in = $this->apiInput();
        $t = $this->resolveTarget($me, (string)($in['username'] ?? $me['username']));
        $d = $this->resolveDomain($t, (string)($in['domain'] ?? ''));
        $owner = ApiModel::username((int)$d['user_id']);
        if (!Helpers::validUsername($owner)) $this->json(['error' => 'owner account invalid'], 500);
        try {
            // mirrors DomainController::delete (mail, DNS, vhost, SSL, lists, rows)
            EmailModel::deleteMailboxesForDomain((int)$d['id']);
            ServiceManager::run('autoreply-sync.sh', []);
            ApiModel::deleteDnsFor((int)$d['id']);
            MailDns::purgeChild($d);
            MailAutopilot::purgeDomain((int)$d['id']);
            ApiModel::deleteDomain((int)$d['id']);
            ServiceManager::run('vhost-remove.sh', [$d['domain'], $owner]);
            ServiceManager::run('dns-remove-zone.sh', [$d['domain']]);
            ServiceManager::run('ssl-remove.sh', [$d['domain']]);
            $als = ApiModel::aliasesFor((int)$d['id']);
            foreach ($als as $a) ServiceManager::run('vhost-unalias.sh', [$d['domain'], $a['alias']]);
            DomainModel::purgeRows((int)$d['id']);
            ServiceManager::run('email-filters-sync.sh', []);
            ServiceManager::run('list-sync.sh', []);
            Helpers::log("API domain {$d['domain']} deleted by {$me['username']}");
            $this->json(['ok' => true, 'deleted' => $d['domain']]);
        } catch (Throwable $e) { $this->apiFailed('delete', 500, $e); }
    }
    // ---- DNS ----
    public function domRewrite(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $in = $this->apiInput();
        $t = $this->resolveTarget($me, (string)($in['username'] ?? $me['username']));
        $d = $this->resolveDomain($t, (string)($in['domain'] ?? ''));
        [$rules, $err] = DomainController::cleanRules((string)($in['rules'] ?? ''));
        if ($err !== null) $this->json(['error' => $err], 422);
        ApiModel::setRewriteRules((int)$d['id'], $rules);
        $r = ServiceManager::run('rewrite-sync.sh', [$d['domain']]);
        if (($r['code'] ?? 1) !== 0) $this->apiFailed('rewrite', 422, null, $r['out']);
        Helpers::log("API rewrite rules saved for {$d['domain']} by {$me['username']}");
        $this->json(['ok' => true, 'domain' => $d['domain']]);
    }
    public function domHtaccess(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $in = $this->apiInput();
        $t = $this->resolveTarget($me, (string)($in['username'] ?? $me['username']));
        $d = $this->resolveDomain($t, (string)($in['domain'] ?? ''));
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- path from owned domain row + fixed filename (docroot itself is trusted server data)
        $ht = rtrim((string)$d['docroot']).'/.htaccess';
        if (!is_file($ht) || !is_readable($ht)) $this->json(['error' => 'no readable .htaccess in the docroot'], 422);
        if (filesize($ht) > 65536) $this->json(['error' => '.htaccess too big (64KB max)'], 422);
        [$rules, $converted, $skipped, $report] = DomainController::convertHtaccess((string)file_get_contents($ht));
        if ($converted === 0) $this->json(['error' => 'nothing convertible', 'report' => $report], 422);
        [$clean, $err] = DomainController::cleanRules(implode("\n", $rules));
        if ($err !== null) $this->json(['error' => 'converter produced bad output (refused): '.$err], 500);
        $srcHash = hash('sha256', (string)file_get_contents($ht));
        ApiModel::setRewriteFromHtaccess((int)$d['id'], $clean, $srcHash);
        $r = ServiceManager::run('rewrite-sync.sh', [$d['domain']]);
        if (($r['code'] ?? 1) !== 0) $this->apiFailed('rewrite sync', 422, null, "converted=$converted skipped=$skipped; ".trim($r['out']));
        Helpers::log("API .htaccess import for {$d['domain']} by {$me['username']}: $converted converted, $skipped skipped");
        $this->json(['ok' => true, 'domain' => $d['domain'], 'converted' => $converted, 'skipped' => $skipped, 'report' => $report]);
    }
    public function sslIssue(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $in = $this->apiInput();
        $t = $this->resolveTarget($me, (string)($in['username'] ?? $me['username']));
        $d = $this->resolveDomain($t, (string)($in['domain'] ?? ''));
        $owner = ApiModel::userEmail((int)$d['user_id']);
        $email = strtolower(trim((string)($owner['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/@[^@]+\.[a-z]{2,}$/i', $email) || str_ends_with($email, '@localhost')) {
            $this->json(['error' => 'domain owner needs a real account email first (ACME rejects admin@localhost)'], 422);
        }
        $r = ServiceManager::run('ssl-issue.sh', [$d['domain'], $email], SslController::ISSUE_TIMEOUT);
        if (str_contains($r['out'], 'Congratulations') || ($r['code'] ?? 1) === 0) {
            ApiModel::enableSslByDomain($d['domain']);
            try { ServiceManager::run('expiry-check.sh', [$d['domain']]); } catch (Throwable $e) {}
            Helpers::log("API SSL issued for {$d['domain']} by {$me['username']}");
            $this->json(['ok' => true, 'domain' => $d['domain'], 'ssl' => true]);
        }
        $this->apiFailed('SSL issue', 502, null, $r['out']);
    }
    // ---- rewrite rules (.htaccess parity — OLS ignores .htaccess) ----
}
