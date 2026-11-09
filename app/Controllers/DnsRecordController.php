<?php
declare(strict_types=1);
class DnsRecordController extends Controller {
    public function add(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/dns'); }
        $u = Auth::user();
        $domain = strtolower(trim($_POST['domain'] ?? ''));
        $dom = DomainModel::findInScope($u, $domain);
        if (!$dom) { Helpers::flash('err','Domain not found'); Helpers::redirect('/dns'); }
        DnsController::refuseChild($dom);
        $type = strtoupper(trim($_POST['type'] ?? ''));
        $host = trim($_POST['host'] ?? '@') ?: '@';
        $value = trim($_POST['value'] ?? '');
        // tolerate pasted zone lines: 'name. IN TXT "v=..."' -> just the rdata
        if ($type === 'TXT' && preg_match('/\sIN\s+TXT\s+(.*)$/is', $value, $m)) $value = trim($m[1], " \t\n\r\"");
        $ttl = max(60, min(2147483, (int)($_POST['ttl'] ?? 3600)));
        $prio = max(0, min(65535, (int)($_POST['priority'] ?? 10)));
        if (($err = (new DnsController())->validateRecord($type, $host, $value, $prio))) {
            Helpers::flash('err', $err); Helpers::redirect('/dns?domain='.urlencode($domain));
        }
        // CNAME exclusivity (real DNS rule, both directions)
        $clash = DnsModel::clashCount((int)$dom['id'], $host, $type);
        if ($clash > 0) { Helpers::flash('err',"$host: CNAME cannot share its name with other records"); Helpers::redirect('/dns?domain='.urlencode($domain)); }
        try {
            $newId = DnsModel::insert((int)$dom['id'], $type, $host, $value, $prio, $ttl);
            $r = (new DnsController())->apply((int)$dom['id'], $domain);
            // a rejected row would poison EVERY future zone rebuild — remove it
            if (($r['code'] ?? 1) !== 0) {
                try { DnsModel::delete($newId); } catch (Throwable $e2) {}
                Helpers::flash('err', 'BIND rejected (nothing saved): '.trim($r['out']));
            }
            else Helpers::flash('ok', "$type $host added & reloaded");
        } catch (Throwable $e) { Helpers::flash('err','Failed: '.$e->getMessage()); }
        Helpers::redirect('/dns?domain='.urlencode($domain));
    }
    public function update(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/dns'); }
        $u = Auth::user();
        $id = (int)($_POST['id'] ?? 0);
        $row = DnsModel::recordWithDomain($id);
        if (!$row || ($u['role'] !== 'admin' && (int)$row['user_id'] !== (int)$u['id'])) {
            Helpers::flash('err','Record not found'); Helpers::redirect('/dns');
        }
        DnsController::refuseChild($row);
        $type = strtoupper(trim($_POST['type'] ?? ''));
        $host = trim($_POST['host'] ?? '@') ?: '@';
        $value = trim($_POST['value'] ?? '');
        $ttl = max(60, min(2147483, (int)($_POST['ttl'] ?? 3600)));
        $prio = max(0, min(65535, (int)($_POST['priority'] ?? 10)));
        if (($err = (new DnsController())->validateRecord($type, $host, $value, $prio))) {
            Helpers::flash('err', $err); Helpers::redirect('/dns?domain='.urlencode($row['domain']));
        }
        $clash = DnsModel::clashCount((int)$row['domain_id'], $host, $type, $id);
        if ($clash > 0) { Helpers::flash('err',"$host: CNAME cannot share its name with other records"); Helpers::redirect('/dns?domain='.urlencode($row['domain'])); }
        try {
            DnsModel::update($id, $type, $host, $value, $prio, $ttl);
            $r = (new DnsController())->apply((int)$row['domain_id'], $row['domain']);
            // same poison rule as add(): restore the previous values
            if (($r['code'] ?? 1) !== 0) {
                try { DnsModel::update($id, (string)$row['type'], (string)$row['host'], (string)$row['value'], $row['priority'], $row['ttl']); } catch (Throwable $e2) {}
                Helpers::flash('err', 'BIND rejected (kept previous values): '.trim($r['out']));
            }
            else Helpers::flash('ok', 'Record updated & reloaded');
        } catch (Throwable $e) { Helpers::flash('err','Failed: '.$e->getMessage()); }
        Helpers::redirect('/dns?domain='.urlencode($row['domain']));
    }
    public function delete(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/dns'); }
        $u = Auth::user();
        $id = (int)($_POST['id'] ?? 0);
        $row = DnsModel::recordWithDomain($id);
        if (!$row || ($u['role'] !== 'admin' && (int)$row['user_id'] !== (int)$u['id'])) {
            Helpers::flash('err','Record not found'); Helpers::redirect('/dns');
        }
        DnsController::refuseChild($row);
        DnsModel::delete($id);
        $r = (new DnsController())->apply((int)$row['domain_id'], $row['domain']);
        // same poison rule as add(): a delete BIND refuses (e.g. the apex's
        // ns1/ns2 glue) would break every later rebuild — put the row back
        if (($r['code'] ?? 1) !== 0) {
            try { DnsModel::restore(['id' => $id] + $row); } catch (Throwable $e2) {}
            Helpers::flash('err', 'BIND rejected (record kept): '.trim($r['out']));
        } else Helpers::flash('ok', 'Record deleted & reloaded');
        Helpers::redirect('/dns?domain='.urlencode($row['domain']));
    }
    // Secondary nameservers (admin): IPs allowed AXFR + notified on change.
    // The secondary box + glue are documented setup, not managed here.
}
