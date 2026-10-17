<?php
declare(strict_types=1);
// Where a domain's mail DNS lives. A Subdomains-tab subdomain (or the panel
// host under its apex) has no zone file of its own: its records are rows of
// the PARENT zone with the label prefixed — for shop.example.com SPF/MX sit
// at "shop", DMARC at "_dmarc.shop", DKIM at "default._domainkey.shop".
// Every mail-DNS reader and writer (Email page, MailPolicyController,
// MailAutopilot, DNS mail presets, cli/mail-dns-converge.php) resolves
// through target() + host(): rows used to land under the subdomain's own
// domain id, which no zone renders — silently never published.
//
// Automatic writes (first mailbox, DKIM, converge) go through addRows():
// apply() re-renders the WHOLE parent zone, so a row BIND refuses is taken
// out again instead of breaking every later save of the parent.
class MailDns {
    public const ZONES = '/etc/bind/zones';

    // ['zone' => domains row whose zone holds the records (null: that zone
    //  isn't on this panel / another account's), 'origin' => its name,
    //  'label' => '' for a domain with its own zone, 'hosted' => zone file
    //  exists here]. Same owner only, except the panel host under its apex.
    public static function target(array $d): array {
        $name = strtolower((string)$d['domain']);
        $zone = $d;
        for ($i = 0; $i < 8 && ($pz = DnsController::parentZone($zone)) !== null; $i++) {
            $row = Database::query("SELECT * FROM domains WHERE domain=?", [$pz])->fetch();
            $ok = $row && ((int)$row['user_id'] === (int)$d['user_id'] || $name === DnsController::panelHost());
            if (!$ok) return ['zone' => null, 'origin' => $pz, 'label' => self::labelOf($name, $pz), 'hosted' => false];
            $zone = $row;
        }
        $origin = strtolower((string)$zone['domain']);
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- zone path sanitized to [a-z0-9.-] under fixed dir; existence check only
        $hosted = is_file(self::ZONES.'/db.'.preg_replace('/[^a-z0-9.-]/', '', $origin));
        return ['zone' => $zone, 'origin' => $origin, 'label' => self::labelOf($name, $origin), 'hosted' => $hosted];
    }
    private static function labelOf(string $name, string $origin): string {
        return $name === $origin ? '' : substr($name, 0, -strlen(".$origin"));
    }
    // zone-relative owner name: '@' -> 'shop', '_dmarc' -> '_dmarc.shop'
    public static function host(array $t, string $h): string {
        if ($t['label'] === '') return $h;
        return $h === '@' ? $t['label'] : "$h.{$t['label']}";
    }
    public static function apply(array $t): array {
        if (!$t['zone']) return ['code' => 1, 'out' => "the {$t['origin']} zone isn't hosted on this panel"];
        return (new DnsController())->apply((int)$t['zone']['id'], (string)$t['zone']['domain']);
    }
    public static function serverIp(): string {
        $ip = trim(ServiceManager::run('server-ip.sh', [])['out'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $ip : '';
    }
    public static function cnameAt(int $zoneId, string $host): bool {
        return (bool)Database::query("SELECT 1 FROM dns_records WHERE domain_id=? AND host=? AND type='CNAME' LIMIT 1", [$zoneId, $host])->fetch();
    }
    // insert [type, host, value, prio] rows into the zone and apply; a BIND
    // refusal removes them again. ['code','out','ids']
    public static function addRows(array $t, array $rows): array {
        $ids = [];
        foreach ($rows as $r) {
            Database::query("INSERT INTO dns_records(domain_id,type,host,value,priority,ttl) VALUES(?,?,?,?,?,3600)",
                [$t['zone']['id'], $r[0], $r[1], $r[2], $r[3] ?? 10]);
            $ids[] = (int)Database::conn()->lastInsertId();
        }
        if (!$ids) return ['code' => 0, 'out' => '', 'ids' => []];
        $r = self::apply($t);
        if (($r['code'] ?? 1) !== 0) {
            foreach ($ids as $id) { try { Database::query("DELETE FROM dns_records WHERE id=?", [$id]); } catch (Throwable $e) {} }
            $ids = [];
        }
        return $r + ['ids' => $ids];
    }

    // MX host for a subdomain: the parent's mail.<parent> while that is an A
    // to this server (its cert already carries the SAN), else the subdomain
    // itself (its A points here). A parent moved to remote mail may have
    // repointed mail.<parent> elsewhere.
    public static function mxTarget(array $t, array $d): string {
        if ($t['label'] === '' || !$t['zone']) return 'mail.'.$d['domain'];
        $ip = self::serverIp();
        $rows = Database::query("SELECT type, value FROM dns_records WHERE domain_id=? AND host='mail'", [$t['zone']['id']])->fetchAll();
        $ok = $ip !== '' && $rows !== [];
        foreach ($rows as $r) if ($r['type'] !== 'A' || trim((string)$r['value']) !== $ip) $ok = false;
        return $ok ? 'mail.'.$t['origin'] : (string)$d['domain'];
    }
    // first mailbox / forwarder / list on a subdomain: MX + SPF at its label
    // in the parent zone, each only when absent (the owner's own records, or
    // the pre-1.5.38 MX shop -> mail.shop.<parent> + A mail.shop, stay).
    // Returns a sentence for the flash, '' when nothing was needed.
    public static function ensureInbound(array $d): string {
        if (($d['mail_handling'] ?? 'local') === 'remote') return '';
        $t = self::target($d);
        if ($t['label'] === '' || !$t['zone'] || !$t['hosted']) return '';
        $zid = (int)$t['zone']['id']; $h = $t['label'];
        if (self::cnameAt($zid, $h)) return " {$d['domain']} is a CNAME in the {$t['origin']} zone, so it can't carry MX/SPF records.";
        $rows = [];
        if (!Database::query("SELECT 1 FROM dns_records WHERE domain_id=? AND host=? AND type='MX' LIMIT 1", [$zid, $h])->fetch()) {
            $rows[] = ['MX', $h, self::mxTarget($t, $d), 10];
        }
        if (!Database::query("SELECT 1 FROM dns_records WHERE domain_id=? AND host=? AND type='TXT' AND value LIKE '%v=spf1%' LIMIT 1", [$zid, $h])->fetch()) {
            $rows[] = ['TXT', $h, 'v=spf1 a mx ~all', 10];
        }
        if (!$rows) return '';
        $r = self::addRows($t, $rows);
        if (($r['code'] ?? 1) !== 0) return " MX/SPF for {$d['domain']} not added (BIND refused: ".trim((string)($r['out'] ?? '')).") — add them in the {$t['origin']} zone.";
        return ' Added '.implode(' + ', array_map(fn($x) => $x[0] === 'TXT' ? 'SPF' : 'MX', $rows))." for {$d['domain']} in the {$t['origin']} zone.";
    }

    // the TXT value dkim-status.sh reports for the signing key, '' when unsigned
    public static function dkimValue(string $domain): string {
        $st = ServiceManager::run('dkim-status.sh', [$domain]);
        $lines = array_values(array_filter(array_map('trim', explode("\n", (string)($st['out'] ?? '')))));
        if (($lines[0] ?? '') !== 'signed yes') return '';
        return preg_match('/"(v=DKIM1;[^"]*)"/', $lines[1] ?? '', $m) ? $m[1] : '';
    }
    // publish the signing key's TXT when the zone is hosted here and has no
    // DKIM record at that name yet. One of: published | exists | differs |
    // cname | external | unsigned | failed: <why>
    public static function publishDkim(array $d, string $val): string {
        if ($val === '') return 'unsigned';
        $t = self::target($d);
        if (!$t['zone'] || !$t['hosted']) return 'external';
        $zid = (int)$t['zone']['id']; $h = self::host($t, 'default._domainkey');
        if (self::cnameAt($zid, $h)) return 'cname';
        $have = Database::query("SELECT value FROM dns_records WHERE domain_id=? AND host=? AND type='TXT' AND value LIKE '%v=DKIM1%'", [$zid, $h])->fetchAll();
        $p = MailAutopilot::tag($val, 'p');
        if ($have) return MailAutopilot::tag(str_replace(['"', ' '], '', (string)$have[0]['value']), 'p') === $p ? 'exists' : 'differs';
        $r = self::addRows($t, [['TXT', $h, $val, 10]]);
        return ($r['code'] ?? 1) === 0 ? 'published' : 'failed: '.trim((string)($r['out'] ?? ''));
    }

    // the DMARC policy a subdomain gets from its parent's record today: sp=
    // when set, else p= ('' when the parent publishes none). Seeds a
    // subdomain's own _dmarc so turning on reports never downgrades it.
    public static function inheritedP(array $t): string {
        if ($t['label'] === '' || !$t['zone']) return '';
        $r = Database::query("SELECT value FROM dns_records WHERE domain_id=? AND host='_dmarc' AND type='TXT' AND value LIKE '%v=DMARC1%' ORDER BY id LIMIT 1", [$t['zone']['id']])->fetch();
        if (!$r) return '';
        $rec = trim((string)$r['value'], '"');
        $p = strtolower(MailAutopilot::tag($rec, 'sp') ?: MailAutopilot::tag($rec, 'p'));
        return in_array($p, ['none', 'quarantine', 'reject'], true) ? $p : '';
    }

    // subdomain going away: every record under its name in the parent zone
    // (A shop, www.shop, mail.shop, MX/TXT shop, _dmarc.shop, mta-sts.shop,
    // default._domainkey.shop, ...) EXCEPT names that belong to another
    // domain row (x.shop.example.com added as its own subdomain). Call while
    // the domains row still exists. Returns the number of rows removed.
    public static function purgeChild(array $d): int {
        $t = self::target($d);
        if ($t['label'] === '' || !$t['zone']) return 0;
        $name = strtolower((string)$d['domain']);
        $others = array_column(Database::query("SELECT domain FROM domains WHERE domain<>? AND RIGHT(domain, ?)=?",
            [$name, strlen(".$name"), ".$name"])->fetchAll(), 'domain');
        $n = 0;
        foreach (Database::query("SELECT id, host FROM dns_records WHERE domain_id=?", [$t['zone']['id']])->fetchAll() as $r) {
            $h = strtolower((string)$r['host']);
            if ($h !== $t['label'] && !str_ends_with($h, '.'.$t['label'])) continue;
            $fq = "$h.{$t['origin']}";
            foreach ($others as $o) if ($fq === $o || str_ends_with($fq, ".$o")) continue 2;
            Database::query("DELETE FROM dns_records WHERE id=?", [$r['id']]);
            $n++;
        }
        if ($n > 0) self::apply($t);
        return $n;
    }

    // pre-1.5.38 orphans: the mail-policy rows MailPolicyController wrote
    // under a subdomain's own id move into the parent zone at the prefixed
    // name. Only those shapes; a conflicting row in the zone wins (the
    // orphan stays, reported). Returns report lines.
    public static function adopt(array $d, bool $dry): array {
        $t = self::target($d);
        if ($t['label'] === '' || !$t['zone'] || !$t['hosted']) return [];
        $zid = (int)$t['zone']['id'];
        $out = []; $moved = [];
        foreach (Database::query("SELECT * FROM dns_records WHERE domain_id=?", [$d['id']])->fetchAll() as $r) {
            $v = trim((string)$r['value'], '"');
            $marker = match (true) {
                $r['type'] === 'TXT' && $r['host'] === '_dmarc' => 'v=DMARC1',
                $r['type'] === 'TXT' && $r['host'] === '_mta-sts' => 'v=STSv1',
                $r['type'] === 'TXT' && $r['host'] === '_smtp._tls' => 'v=TLSRPTv1',
                $r['type'] === 'TXT' && $r['host'] === '@' && stripos($v, 'v=spf1') === 0 => 'v=spf1',
                $r['type'] === 'A' && $r['host'] === 'mta-sts' => 'A',
                default => '',
            };
            $h = self::host($t, (string)$r['host']);
            if ($marker === '') { $out[] = "{$d['domain']}: left alone {$r['type']} {$r['host']} (not a mail-policy row)"; continue; }
            $clash = $marker === 'A'
                ? Database::query("SELECT value FROM dns_records WHERE domain_id=? AND host=? AND type IN ('A','CNAME')", [$zid, $h])->fetchAll()
                : Database::query("SELECT value FROM dns_records WHERE domain_id=? AND host=? AND type='TXT' AND value LIKE ?", [$zid, $h, "%$marker%"])->fetchAll();
            if (!$clash && self::cnameAt($zid, $h)) $clash = [['value' => 'CNAME']];
            if ($clash) {
                $same = count($clash) === 1 && trim((string)$clash[0]['value'], '"') === $v;
                if ($same && !$dry) Database::query("DELETE FROM dns_records WHERE id=?", [$r['id']]);
                $out[] = $same ? "{$d['domain']}: duplicate {$r['type']} $h dropped" : "{$d['domain']}: kept orphan {$r['type']} {$r['host']} ({$t['origin']} already has $h)";
                continue;
            }
            if (!$dry) {
                Database::query("UPDATE dns_records SET domain_id=?, host=? WHERE id=?", [$zid, $h, $r['id']]);
                $moved[] = $r;
            }
            $out[] = "{$d['domain']}: ".($dry ? 'would move' : 'moved')." {$r['type']} {$r['host']} -> $h in {$t['origin']}";
        }
        if ($moved) {
            $a = self::apply($t);
            if (($a['code'] ?? 1) !== 0) {
                foreach ($moved as $r) Database::query("UPDATE dns_records SET domain_id=?, host=? WHERE id=?", [$r['domain_id'], $r['host'], $r['id']]);
                $out[] = "{$d['domain']}: BIND refused the {$t['origin']} zone, moves undone: ".trim((string)($a['out'] ?? ''));
            }
        }
        return $out;
    }
}
