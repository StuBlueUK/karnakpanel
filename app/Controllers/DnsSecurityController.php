<?php
declare(strict_types=1);
class DnsSecurityController extends Controller {
    public function secondary(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/dns'); }
        $domain = strtolower(trim($_POST['domain'] ?? ''));
        $dom = DnsModel::domainByName($domain);
        if (!$dom) { Helpers::flash('err','Domain not found'); Helpers::redirect('/dns'); }
        $raw = trim((string)($_POST['secondaries'] ?? ''));
        $ips = [];
        if ($raw !== '') {
            foreach (preg_split('/[\s,]+/', $raw) as $ip) {
                $ip = trim($ip);
                if ($ip === '') continue;
                if (!filter_var($ip, FILTER_VALIDATE_IP)) { Helpers::flash('err',"Bad IP: $ip"); Helpers::redirect('/dns?domain='.urlencode($domain)); }
                $ips[] = $ip;
            }
            $ips = array_values(array_unique($ips));
            if (count($ips) > 2) { Helpers::flash('err','Max 2 secondaries'); Helpers::redirect('/dns?domain='.urlencode($domain)); }
        }
        $csv = implode(',', $ips);
        try {
            DnsModel::setSecondaryNs((int)$dom['id'], $csv);
            $r = ServiceManager::run('dns-secondary.sh', [$domain, $csv]);
            if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Not applied: '.trim($r['out']));
            else { Helpers::log("secondaries for $domain: ".($csv !== '' ? $csv : 'cleared')." by ".Auth::user()['username']); Helpers::flash('ok', trim($r['out'])); }
        } catch (Throwable $e) { Helpers::flash('err','Failed: '.$e->getMessage()); }
        Helpers::redirect('/dns?domain='.urlencode($domain));
    }
    public function dnssecEnable(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/dns'); }
        $u = Auth::user();
        $domain = strtolower(trim($_POST['domain'] ?? ''));
        $dom = DomainModel::findInScope($u, $domain);
        if (!$dom) { Helpers::flash('err','Domain not found'); Helpers::redirect('/dns'); }
        DnsController::refuseChild($dom);
        $r = ServiceManager::run('dnssec-enable.sh', [$domain]);
        if (($r['code'] ?? 1) !== 0) { Helpers::flash('err','Signing failed: '.trim($r['out'])); }
        else {
            DnsModel::setDnssec((int)$dom['id'], 1);
            Helpers::flash('ok',"Zone signed. DS for the registrar: ".trim($r['out']));
        }
        Helpers::redirect('/dns?domain='.urlencode($domain));
    }
    public function dnssecDisable(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/dns'); }
        $u = Auth::user();
        $domain = strtolower(trim($_POST['domain'] ?? ''));
        $dom = DomainModel::findInScope($u, $domain);
        if (!$dom) { Helpers::flash('err','Domain not found'); Helpers::redirect('/dns'); }
        $r = ServiceManager::run('dnssec-disable.sh', [$domain]);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Unsign failed: '.trim($r['out']));
        else {
            DnsModel::setDnssec((int)$dom['id'], 0);
            Helpers::flash('ok',"Signing disabled for $domain (keys kept for re-enable)");
        }
        Helpers::redirect('/dns?domain='.urlencode($domain));
    }
    // zone-file text -> [type,host,value,prio,ttl] rows for $domain. Handles
    // what BIND / Cloudflare / cPanel exports contain: FQDN owners (made
    // relative), blank owner = previous, optional TTL/class in either order,
    // quoted ';' inside TXT (DMARC), multi-chunk TXT (DKIM), $TTL. Skips SOA,
    // the apex NS (the panel manages it), out-of-zone and invalid lines.
}
