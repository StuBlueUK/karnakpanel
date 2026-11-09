<?php
declare(strict_types=1);
// ApiDnsController — inherits the API auth/scoping helpers from ApiController.
class ApiDnsController extends ApiController {
    public function dnsList(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $in = array_merge($this->apiInput(), $_GET);
        $t = $this->resolveTarget($me, (string)($in['username'] ?? $me['username']));
        $d = $this->resolveDomain($t, (string)($in['domain'] ?? ''));
        $rows = ApiModel::dnsRecordsFor((int)$d['id']);
        $this->json(['ok' => true, 'domain' => $d['domain'], 'records' => $rows]);
    }
    public function dnsAdd(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $in = $this->apiInput();
        $t = $this->resolveTarget($me, (string)($in['username'] ?? $me['username']));
        $d = $this->resolveDomain($t, (string)($in['domain'] ?? ''));
        if ($pz = DnsController::parentZone($d)) $this->json(['error' => "{$d['domain']} has no zone of its own — its records live in the $pz zone"], 422);
        $dns = new DnsController();
        $type = strtoupper(trim((string)($in['type'] ?? '')));
        $host = trim((string)($in['host'] ?? '@')) ?: '@';
        $value = trim((string)($in['value'] ?? ''));
        if ($type === 'TXT' && preg_match('/\sIN\s+TXT\s+(.*)$/is', $value, $m)) $value = trim($m[1], " \t\n\r\"");
        $ttl = max(60, min(2147483, (int)($in['ttl'] ?? 3600)));
        $prio = max(0, min(65535, (int)($in['priority'] ?? 10)));
        if (($err = $dns->validateRecord($type, $host, $value, $prio))) $this->json(['error' => $err], 422);
        $clash = ApiModel::cnameClash((int)$d['id'], $host, $type);
        if ($clash > 0) $this->json(['error' => "$host: CNAME cannot share its name with other records"], 422);
        try {
            $newId = ApiModel::insertDnsRecord((int)$d['id'], $type, $host, $value, $prio, $ttl);
            $r = $dns->apply((int)$d['id'], $d['domain']);
            if (($r['code'] ?? 1) !== 0) {
                try { ApiModel::deleteDnsRecord($newId); } catch (Throwable $e2) {}
                $this->json(['error' => 'BIND rejected (nothing saved): '.trim($r['out'])], 422);
            }
            Helpers::log("API DNS $type $host added on {$d['domain']} by {$me['username']}");
            $this->json(['ok' => true, 'id' => $newId]);
        } catch (Throwable $e) { $this->json(['error' => 'add failed: '.$e->getMessage()], 500); }
    }
    public function dnsUpdate(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $in = $this->apiInput();
        $t = $this->resolveTarget($me, (string)($in['username'] ?? $me['username']));
        $id = (int)($in['id'] ?? 0);
        $row = ApiModel::dnsRecordOwned($id, (int)$t['id']);
        if (!$row) $this->json(['error' => 'record not found'], 404);
        if ($pz = DnsController::parentZone(['domain' => $row['domain'], 'user_id' => $t['id']])) $this->json(['error' => "{$row['domain']} has no zone of its own — its records live in the $pz zone"], 422);
        $dns = new DnsController();
        $type = strtoupper(trim((string)($in['type'] ?? $row['type'])));
        $host = trim((string)($in['host'] ?? $row['host'])) ?: '@';
        $value = trim((string)($in['value'] ?? $row['value']));
        $ttl = max(60, min(2147483, (int)($in['ttl'] ?? $row['ttl'])));
        $prio = max(0, min(65535, (int)($in['priority'] ?? $row['priority'])));
        if (($err = $dns->validateRecord($type, $host, $value, $prio))) $this->json(['error' => $err], 422);
        $clash = ApiModel::cnameClashExcept((int)$row['domain_id'], $host, $type, $id);
        if ($clash > 0) $this->json(['error' => "$host: CNAME cannot share its name with other records"], 422);
        try {
            ApiModel::updateDnsRecord($id, $type, $host, $value, $prio, $ttl);
            $r = $dns->apply((int)$row['domain_id'], $row['domain']);
            if (($r['code'] ?? 1) !== 0) {
                try { ApiModel::updateDnsRecord($id, (string)$row['type'], (string)$row['host'], (string)$row['value'], (int)$row['priority'], (int)$row['ttl']); } catch (Throwable $e2) {}
                $this->json(['error' => 'BIND rejected (kept previous values): '.trim($r['out'])], 422);
            }
            Helpers::log("API DNS record $id updated on {$row['domain']} by {$me['username']}");
            $this->json(['ok' => true, 'id' => $id]);
        } catch (Throwable $e) { $this->json(['error' => 'update failed: '.$e->getMessage()], 500); }
    }
    public function dnsDelete(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $in = $this->apiInput();
        $t = $this->resolveTarget($me, (string)($in['username'] ?? $me['username']));
        $id = (int)($in['id'] ?? 0);
        $row = ApiModel::dnsRecordOwned($id, (int)$t['id']);
        if (!$row) $this->json(['error' => 'record not found'], 404);
        ApiModel::deleteDnsRecord($id);
        $r = (new DnsController())->apply((int)$row['domain_id'], $row['domain']);
        Helpers::log("API DNS record $id deleted on {$row['domain']} by {$me['username']}");
        $this->json(['ok' => true, 'id' => $id, 'reloaded' => ($r['code'] ?? 1) === 0]);
    }
    // ---- mail ----
}
