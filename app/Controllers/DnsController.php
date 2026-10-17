<?php
declare(strict_types=1);
class DnsController extends Controller {
    private const TYPES = ['A','AAAA','CNAME','MX','TXT','NS','CAA','SRV','SSHFP'];
    // Nameservers a zone advertises, first match wins:
    //  1. the owner's reseller white-label pair;
    //  2. LEGACY zones (pre-1.5.34 seeds gave every domain its own
    //     ns1/ns2.<domain> + A rows) keep them while both A rows exist, so a
    //     record edit never silently re-points a live delegation;
    //  3. the SERVER pair ns1/ns2.<apex of panel_domain> (glue lives once,
    //     in the panel apex zone — what customers set at their registrar);
    //  4. ns1/ns2.<domain> only while no panel host is configured.
    public static function effectiveNs(int $userId, string $domain = '', int $domainId = 0): array {
        try {
            $u = Database::query("SELECT owner_id FROM users WHERE id=?", [$userId])->fetch();
            if (!empty($u['owner_id'])) {
                $r = Database::query("SELECT ns1,ns2 FROM users WHERE id=? AND role='reseller'", [$u['owner_id']])->fetch();
                if ($r && Helpers::validDomain((string)($r['ns1'] ?? '')) && Helpers::validDomain((string)($r['ns2'] ?? ''))) {
                    return [$r['ns1'], $r['ns2']];
                }
            }
            if ($domainId > 0 && self::isLegacyNs($domainId)) return ["ns1.$domain", "ns2.$domain"];
        } catch (Throwable $e) {}
        return self::serverNs() ?? ["ns1.$domain", "ns2.$domain"];
    }
    // ns1/ns2.<apex>, apex derived exactly like dns-glue-check.sh (strip one
    // left label from a 3-label host) so the checklist and zones agree.
    public static function serverNs(): ?array {
        $apex = self::panelApex();
        return $apex === null ? null : ["ns1.$apex", "ns2.$apex"];
    }
    // the panel's own hostname (settings.panel_domain), '' when unset/bad
    public static function panelHost(): string {
        try {
            $pd = strtolower(trim((string)(Database::query("SELECT value FROM settings WHERE name='panel_domain'")->fetch()['value'] ?? '')));
        } catch (Throwable $e) { return ''; }
        return preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $pd) ? $pd : '';
    }
    // ONE apex rule for PHP (panel-domain.sh + dns-glue-check.sh mirror it):
    // strip one left label from a 3-label host, panel.example.com -> example.com
    public static function panelApex(): ?string {
        $pd = self::panelHost();
        if ($pd === '') return null;
        return preg_match('/^[^.]+\.([^.]+\.[^.]+)$/', $pd, $m) ? $m[1] : $pd;
    }
    // A child name with NO zone file of its own (Subdomains-tab subdomain, or
    // the panel host under its apex) keeps its records in the PARENT zone —
    // returns that zone's name. Managing it as a zone would write a file that
    // shadows/contradicts the parent. Old-style children that do have their
    // own zone file keep the full page (null).
    public static function parentZone(array $dom): ?string {
        $name = strtolower((string)$dom['domain']);
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- zone path sanitized to [a-z0-9.-] under fixed dir; existence check only
        if (is_file('/etc/bind/zones/db.'.preg_replace('/[^a-z0-9.-]/', '', $name))) return null;
        if ($p = DomainModel::parentOf($name, (int)$dom['user_id'])) return (string)$p['domain'];
        $apex = self::panelApex();
        if ($apex !== null && $apex !== $name && $name === self::panelHost()) return $apex;
        return null;
    }
    private function refuseChild(array $dom): void {
        if ($pz = self::parentZone($dom)) {
            Helpers::flash('err', "{$dom['domain']} has no zone of its own — its records live in the $pz zone");
            Helpers::redirect('/dns?domain='.urlencode($pz));
        }
    }
    public static function isLegacyNs(int $domainId): bool {
        return (int)Database::query("SELECT COUNT(DISTINCT host) c FROM dns_records WHERE domain_id=? AND type='A' AND host IN ('ns1','ns2')", [$domainId])->fetch()['c'] === 2;
    }
    // every reseller white-label nameserver: when one lives inside a zone
    // here (the reseller's brand.com), that zone must carry its A glue or
    // all of that reseller's client zones stop resolving
    public static function brandNs(): array {
        $out = [];
        try {
            foreach (Database::query("SELECT ns1,ns2 FROM users WHERE role='reseller' AND ns1 IS NOT NULL AND ns1<>''")->fetchAll() as $r) {
                foreach ([$r['ns1'], $r['ns2']] as $n) if (Helpers::validDomain((string)$n)) $out[] = strtolower((string)$n);
            }
        } catch (Throwable $e) {}
        return $out;
    }
    public static function hostsBrandNs(string $domain): bool {
        foreach (self::brandNs() as $n) if (str_ends_with($n, ".$domain")) return true;
        return false;
    }
    // default rows for a fresh zone. ns1/ns2 A (glue) only when a nameserver
    // lives INSIDE this zone (the panel apex, or no panel host set) — for
    // everyone else they were dead records that looked like the NS setup.
    public static function defaultRows(string $domain, string $ip, array $ns): array {
        $rows = [['A','@',$ip],['A','www',$ip],['A','mail',$ip]];
        foreach (array_unique(array_merge($ns, self::brandNs())) as $n) {
            $n = rtrim(strtolower($n), '.');
            if (str_ends_with($n, ".$domain")) {
                $label = substr($n, 0, -strlen(".$domain"));
                if (preg_match('/^[a-z0-9-]+$/', $label)) $rows[] = ['A', $label, $ip];
            }
        }
        // the panel host below this apex (panel.example.com): its site, www
        // alias and mail.<host> (exim HELO + cert SAN) resolve from here —
        // also what makes a reset of the apex keep the panel reachable
        $ph = self::panelHost();
        if ($ph !== '' && self::panelApex() === $domain && $ph !== $domain) {
            $label = substr($ph, 0, -strlen(".$domain"));
            if (preg_match('/^[a-z0-9-]+$/', $label)) foreach ([$label, "www.$label", "mail.$label"] as $h) $rows[] = ['A', $h, $ip];
        }
        $rows[] = ['MX','@','mail.'.$domain];
        $rows[] = ['TXT','@','v=spf1 a mx ~all'];
        return $rows;
    }

    private function myDomains(array $u): array {
        return ($u['role']==='admin') ? DomainModel::all() : DomainModel::forUser((int)$u['id']);
    }
    private function findDomain(array $u, string $domain): ?array {
        foreach ($this->myDomains($u) as $d) {
            if (strtolower($d['domain']) === $domain) return $d;
        }
        return null;
    }
    public function list(): void {
        Auth::requireLogin();
        $u = Auth::user();
        $domains = $this->myDomains($u);
        $records = []; $viewing = null; $zone = null; $rq = '';
        // paginated domain browser under the finder (same scope rules)
        $lq = substr(trim((string)($_GET['lq'] ?? '')), 0, 64);
        $dpg = max(1, (int)($_GET['dpage'] ?? 1));
        if ($u['role'] === 'admin') {
            $dtotal = ($lq !== '') ? DomainModel::countSearch(null, $lq) : DomainModel::countAll();
            $dp = Helpers::paginate($dtotal, $dpg);
            $domlist = ($lq !== '') ? DomainModel::searchPaged(null, $lq, $dp['limit'], $dp['offset']) : DomainModel::allPaged($dp['limit'], $dp['offset']);
        } else {
            $dtotal = ($lq !== '') ? DomainModel::countSearch((int)$u['id'], $lq) : DomainModel::countForUser((int)$u['id']);
            $dp = Helpers::paginate($dtotal, $dpg);
            $domlist = ($lq !== '') ? DomainModel::searchPaged((int)$u['id'], $lq, $dp['limit'], $dp['offset']) : DomainModel::forUserPaged((int)$u['id'], $dp['limit'], $dp['offset']);
        }
        $dpg = $dp['pg']; $dpages = $dp['pages']; $dtotal = $dp['total'];
        if (!empty($_GET['domain'])) {
            $d = strtolower(trim($_GET['domain']));
            $dom = $this->findDomain($u, $d);
            if (!$dom) Helpers::flash('err','Domain not found in your account');
            else {
                $viewing = $dom;
                $rq = substr(trim((string)($_GET['rq'] ?? '')), 0, 64);
                $rlike = ($rq !== '') ? '%'.str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], strtolower($rq)).'%' : null;
                $p = Helpers::paginate((int)($rlike === null
                    ? Database::query("SELECT COUNT(*) c FROM dns_records WHERE domain_id=?", [$dom['id']])->fetch()['c']
                    : Database::query("SELECT COUNT(*) c FROM dns_records WHERE domain_id=? AND (host LIKE ? OR value LIKE ?)", [$dom['id'], $rlike, $rlike])->fetch()['c']), max(1, (int)($_GET['page'] ?? 1)));
                $pg = $p['pg']; $pages = $p['pages']; $total = $p['total'];
                $records = ($rlike === null)
                    ? Database::query("SELECT * FROM dns_records WHERE domain_id=? ORDER BY host, type LIMIT ? OFFSET ?", [$dom['id'], (int)$p['limit'], (int)$p['offset']])->fetchAll()
                    : Database::query("SELECT * FROM dns_records WHERE domain_id=? AND (host LIKE ? OR value LIKE ?) ORDER BY host, type LIMIT ? OFFSET ?", [$dom['id'], $rlike, $rlike, (int)$p['limit'], (int)$p['offset']])->fetchAll();
                $zoneFile = "/etc/bind/zones/db.".preg_replace('/[^a-z0-9.-]/i','',$d);
                // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- zone path: sanitized + ownership-checked domain
                $zone = (is_readable($zoneFile) ? file_get_contents($zoneFile) : false);
                if ($zone === false) $zone = "(not yet provisioned on disk — add a record or reset to default)";
            }
        }
        $dnssecSigned = false; $dnssecDs = '';
        if ($viewing) {
            try {
                $st = ServiceManager::run('dnssec-status.sh', [$viewing['domain']]);
                $lines = array_filter(array_map('trim', explode("\n", $st['out'] ?? '')));
                $dnssecSigned = (($lines[0] ?? '') === 'signed yes');
                if ($dnssecSigned) $dnssecDs = implode("\n", array_slice($lines, 1));
            } catch (Throwable $e) {}
        }
        // nameservers the zone advertises (header-managed, not DB rows) and,
        // for legacy per-domain ns1/ns2 zones, what a switch would move to
        $nameservers = []; $nsSwitchTo = null; $glueOk = true;
        try { $glueOk = (string)(Database::query("SELECT value FROM settings WHERE name='glue_ok'")->fetch()['value'] ?? '') === '1'; } catch (Throwable $e) {}
        if ($viewing) {
            try {
                $nameservers = self::effectiveNs((int)$viewing['user_id'], $viewing['domain'], (int)$viewing['id']);
                $fresh = self::effectiveNs((int)$viewing['user_id'], $viewing['domain']);
                if ($fresh !== $nameservers && !str_ends_with($fresh[0], '.'.$viewing['domain']) && !self::hostsBrandNs($viewing['domain'])) $nsSwitchTo = $fresh;
            } catch (Throwable $e) {}
        }
        // child names (subdomain / panel host under its apex) get a pointer
        // to the zone that really holds their records, nothing editable
        $parentZone = $viewing ? self::parentZone($viewing) : null;
        $parentHosted = $parentZone !== null && (bool)$this->findDomain($u, $parentZone);
        // rows the zone can't lose: in-zone nameserver glue + panel host
        $protected = [];
        if ($viewing && !$parentZone) {
            $dn = strtolower($viewing['domain']);
            foreach (array_unique(array_merge($nameservers, self::brandNs())) as $n) {
                if (str_ends_with($n, ".$dn")) $protected['A|'.substr($n, 0, -strlen(".$dn"))] = 'nameserver glue';
            }
            $ph = self::panelHost();
            if ($ph !== '' && $ph !== $dn && str_ends_with($ph, ".$dn")) {
                $l = substr($ph, 0, -strlen(".$dn"));
                foreach ([$l, "www.$l", "mail.$l"] as $h) $protected["A|$h"] = 'panel host';
            }
            if ($ph === $dn) foreach (['@', 'www', 'mail'] as $h) $protected["A|$h"] = 'panel host';
        }
        // delegation check: explicit button only (no live DNS on page loads)
        $check = null;
        if ($viewing && !$parentZone && !empty($_GET['check']) && count($nameservers) === 2) {
            $out = ServiceManager::run('dns-check.sh', [$viewing['domain'], $nameservers[0], $nameservers[1]], 30);
            $check = [];
            foreach (explode("\n", (string)($out['out'] ?? '')) as $l) {
                if (preg_match('/^([a-z0-9_]+) ?(.*)$/', trim($l), $m)) $check[$m[1]] = trim($m[2]);
            }
            if (($out['code'] ?? 1) !== 0) $check = ['error' => trim((string)($out['out'] ?? 'check failed'))];
        }
        $templates = $viewing ? $this->templatesFor($u, $viewing) : [];
        $pg = $pg ?? 1; $pages = $pages ?? 1; $total = $total ?? count($records ?? []);
        $this->view('dns', compact('domains','records','viewing','zone','dnssecSigned','dnssecDs','pg','pages','total','rq','domlist','dp','dpages','dtotal','lq','nameservers','nsSwitchTo','glueOk','parentZone','parentHosted','protected','check','templates'));
    }
    // public for the API (same validation, no duplication)
    public function validateRecord(string $type, string $host, string $value, int $prio): ?string {
        if (!in_array($type, self::TYPES, true)) return 'Bad type';
        // optional leading '*.' / bare '*' = wildcard owner (BIND-valid)
        if ($host !== '@' && !preg_match('/^(?=.{1,190}$)(\*|(\*\.)?[a-z0-9_-]{1,63}(\.[a-z0-9_-]{1,63})*)$/i', $host)) return 'Bad host (use @ for root, * for wildcard)';
        if ($type === 'A' && !filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return 'Bad IPv4';
        if ($type === 'AAAA' && !filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) return 'Bad IPv6';
        if (in_array($type, ['CNAME','NS'], true) && !preg_match('/^[a-z0-9.-]+\.?$/i', $value)) return 'Bad hostname';
        if ($type === 'MX') {
            if ($prio < 0 || $prio > 65535) return 'Bad MX priority (0-65535)';
            if (!preg_match('/^[a-z0-9.-]+\.?$/i', $value)) return 'Bad MX host';
        }
        if ($type === 'TXT' && strlen($value) > 800) return 'TXT too long';
        // CAA: '0 issue "letsencrypt.org"' (quotes optional, added at render).
        // Only letsencrypt + common commercial CAs accepted — a typo here
        // silently blocks issuance, so free-form values are refused.
        if ($type === 'CAA') {
            $v = trim(str_replace('"', '', $value), "; \t");
            if (!preg_match('/^([01]) (issue|issuewild|iodef) (\S+)$/i', $v, $m)) return 'Bad CAA (e.g. 0 issue letsencrypt.org)';
            $tag = strtolower($m[2]); $val2 = strtolower(rtrim($m[3], '.'));
            $ok = ($tag === 'iodef' && (str_starts_with($val2, 'mailto:') || str_starts_with($val2, 'http')))
                || ($tag !== 'iodef' && in_array($val2, ['letsencrypt.org','digicert.com','sectigo.com','godaddy.com','comodoca.com','globalsign.com','entrust.net'], true));
            if (!$ok) return 'Unknown CA for CAA (letsencrypt.org, digicert.com, sectigo.com, godaddy.com, comodoca.com, globalsign.com, entrust.net, or mailto:/http(s) for iodef)';
        }
        // SRV: priority column + 'weight port target'; host must be
        // _service._proto (underscore labels, optional suffix).
        if ($type === 'SRV') {
            if ($prio < 0 || $prio > 65535) return 'Bad SRV priority (0-65535)';
            if (!preg_match('/^_[a-z0-9-]+\._[a-z0-9-]+(\.[a-z0-9_.-]+)?$/i', $host)) return 'Bad SRV host (e.g. _sip._tcp)';
            if (!preg_match('/^(\d{1,5}) (\d{1,5}) ([a-z0-9.-]+\.?)$/i', $value, $m) || (int)$m[1] > 65535 || (int)$m[2] > 65535) return 'Bad SRV value (weight port target)';
            $port = (int)explode(' ', $value)[1];
            if ($port < 1 || $port > 65535) return 'Bad SRV port (1-65535)';
        }
        // SSHFP: 'alg type HEX' — digest length depends on type (SHA1=40,
        // SHA256=64); BIND rejects mismatches, so validate here, not in logs.
        if ($type === 'SSHFP') {
            $v = str_replace([' ',':'], '', $value);
            if (!preg_match('/^([124])([12])([0-9a-fA-F]+)$/', $v, $m)) return 'Bad SSHFP (e.g. 4 2 <64-hex-sha256>)';
            $need = ($m[2] === '1') ? 40 : 64;
            if (strlen($m[3]) !== $need) return "Bad SSHFP: type {$m[2]} needs exactly $need hex chars";
        }
        return null;
    }
    // public so DomainController can re-render after managing the *.preview
    // wildcard row for preview URLs (same validate+install path as /dns/*).
    public function apply(int $domainId, string $domain): array {
        // render zone from DB rows, stage, validate+install+reload via existing script
        $rows = Database::query("SELECT * FROM dns_records WHERE domain_id=? ORDER BY host, type", [$domainId])->fetchAll();
        [$ns1, $ns2] = self::effectiveNs((int)Database::query("SELECT user_id FROM domains WHERE id=?", [$domainId])->fetch()['user_id'], $domain, $domainId);
        // serial must always move FORWARD (signed zones auto-bump past date-based values).
        // BIND compares against the SIGNED copy — read that first, unsigned as fallback.
        $serial = (int)(date('Ymd').'01');
        $zbase = '/etc/bind/zones/db.'.preg_replace('/[^a-z0-9.-]/i','',$domain);
        foreach ([$zbase.'.signed', $zbase] as $zf) {
            // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- zone path sanitized to [a-z0-9.-] under fixed dir; read-only SOA serial sniff (zone data is public)
            if (is_readable($zf) && preg_match('/SOA\s+\S+\s+\S+\s*\(\s*(\d{8,10})/', (string)file_get_contents($zf), $m)) {
                $serial = max($serial, (int)$m[1] + 1);
                break;
            }
        }
        $z = "\$TTL 3600\n@ IN SOA {$ns1}. admin.$domain. ( $serial 7200 3600 1209600 3600 )\n";
        $z .= "@ IN NS {$ns1}.\n@ IN NS {$ns2}.\n";
        foreach ($rows as $r) {
            // header already emits the zone's NS pair; older seeds also stored
            // it as a row (rendered twice)
            if ($r['type'] === 'NS' && $r['host'] === '@' && in_array(rtrim(strtolower($r['value']), '.'), [$ns1, $ns2], true)) continue;
            $ttl = max(60, min(2147483, (int)$r['ttl']));
            $val = $r['value'];
            if ($r['type'] === 'TXT') {
                $val = str_replace('"', '', trim($val, '"'));
                // zone wire format: max 255 chars per quoted chunk (DKIM keys need 2)
                $val = '"'.implode('" "', str_split($val, 255)).'"';
            }
            // FQDNs need a trailing dot or the origin gets appended
            // (mail.example.com -> mail.example.com.example.com). Bare labels
            // like 'mail' stay relative on purpose.
            if (in_array($r['type'], ['MX','NS','CNAME'], true) && str_contains($val, '.') && !str_ends_with(rtrim($val), '.')) {
                $val .= '.';
            }
            if ($r['type'] === 'MX') $val = ((int)$r['priority']).' '.$val;
            // SRV like MX: priority column first, then weight port target
            if ($r['type'] === 'SRV') {
                $val = ((int)$r['priority']).' '.$val;
                // target is a hostname: trailing dot or origin gets appended
                $parts = explode(' ', $val);
                $tgt = end($parts);
                if (str_contains($tgt, '.') && !str_ends_with(rtrim($tgt), '.')) {
                    $parts[count($parts)-1] = $tgt.'.';
                    $val = implode(' ', $parts);
                }
            }
            // CAA: flags tag "value" (quotes added here, stripped on input)
            if ($r['type'] === 'CAA') {
                // ALL quotes out (validateRecord accepts them anywhere): a
                // stored '0 issue "letsencrypt.org"' rendered ""letsencrypt.org"
                // and BIND refused the whole zone
                $v = trim(str_replace('"', '', $val), "; \t");
                if (preg_match('/^([01]) (issue|issuewild|iodef) (\S+)$/i', $v, $m)) {
                    $val = $m[1].' '.strtolower($m[2]).' "'.strtolower(rtrim($m[3], '.')).'"';
                }
            }
            // SSHFP: normalize to 'alg type HEX' (input tolerates :/spaces)
            if ($r['type'] === 'SSHFP') {
                $v = str_replace([' ',':'], '', $val);
                if (preg_match('/^([124])([12])([0-9a-fA-F]+)$/', $v, $m)) {
                    $val = $m[1].' '.$m[2].' '.strtoupper($m[3]);
                }
            }
            $z .= "{$r['host']} $ttl IN {$r['type']} $val\n";
        }
        // O_EXCL staging: /tmp is shared — never follow a pre-planted symlink
        // (fail closed with "retry"; the save script removes it on success).
        // A stale regular file (crashed run) older than 10 min is reclaimed
        // once; symlinks are never touched — fopen 'x' refuses those anyway.
        $stage = "/tmp/skypanel-".$domain.".zone";
        $fh = @fopen($stage, 'xb');
        if (!$fh && is_file($stage) && !is_link($stage) && (time() - ((int)@filemtime($stage))) > 600) {
            // nosemgrep: php.lang.security.unlink-use.unlink-use -- staging path from ownership-checked domain; symlinks + fresh files refused
            @unlink($stage);
            // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- staging path from validated domain; xb refuses symlinks
            $fh = @fopen($stage, 'xb');
        }
        if (!$fh) { Helpers::flash('err','Zone staging blocked — retry'); Helpers::redirect('/dns'); }
        fwrite($fh, $z); fclose($fh);
        return ServiceManager::run('dns-save-zone.sh', [$domain]);
    }
    public function add(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/dns'); }
        $u = Auth::user();
        $domain = strtolower(trim($_POST['domain'] ?? ''));
        $dom = $this->findDomain($u, $domain);
        if (!$dom) { Helpers::flash('err','Domain not found'); Helpers::redirect('/dns'); }
        $this->refuseChild($dom);
        $type = strtoupper(trim($_POST['type'] ?? ''));
        $host = trim($_POST['host'] ?? '@') ?: '@';
        $value = trim($_POST['value'] ?? '');
        // tolerate pasted zone lines: 'name. IN TXT "v=..."' -> just the rdata
        if ($type === 'TXT' && preg_match('/\sIN\s+TXT\s+(.*)$/is', $value, $m)) $value = trim($m[1], " \t\n\r\"");
        $ttl = max(60, min(2147483, (int)($_POST['ttl'] ?? 3600)));
        $prio = max(0, min(65535, (int)($_POST['priority'] ?? 10)));
        if (($err = $this->validateRecord($type, $host, $value, $prio))) {
            Helpers::flash('err', $err); Helpers::redirect('/dns?domain='.urlencode($domain));
        }
        // CNAME exclusivity (real DNS rule, both directions)
        $clash = Database::query(
            "SELECT COUNT(*) c FROM dns_records WHERE domain_id=? AND host=? AND ((type='CNAME') OR (?='CNAME'))",
            [$dom['id'], $host, $type])->fetch()['c'];
        if ($clash > 0) { Helpers::flash('err',"$host: CNAME cannot share its name with other records"); Helpers::redirect('/dns?domain='.urlencode($domain)); }
        try {
            Database::query("INSERT INTO dns_records(domain_id,type,host,value,priority,ttl) VALUES(?,?,?,?,?,?)",
                [$dom['id'], $type, $host, $value, $prio, $ttl]);
            $newId = (int)Database::conn()->lastInsertId();
            $r = $this->apply((int)$dom['id'], $domain);
            // a rejected row would poison EVERY future zone rebuild — remove it
            if (($r['code'] ?? 1) !== 0) {
                try { Database::query("DELETE FROM dns_records WHERE id=?", [$newId]); } catch (Throwable $e2) {}
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
        $row = Database::query("SELECT r.*, d.domain, d.user_id FROM dns_records r JOIN domains d ON d.id=r.domain_id WHERE r.id=?", [$id])->fetch();
        if (!$row || ($u['role'] !== 'admin' && (int)$row['user_id'] !== (int)$u['id'])) {
            Helpers::flash('err','Record not found'); Helpers::redirect('/dns');
        }
        $this->refuseChild($row);
        $type = strtoupper(trim($_POST['type'] ?? ''));
        $host = trim($_POST['host'] ?? '@') ?: '@';
        $value = trim($_POST['value'] ?? '');
        $ttl = max(60, min(2147483, (int)($_POST['ttl'] ?? 3600)));
        $prio = max(0, min(65535, (int)($_POST['priority'] ?? 10)));
        if (($err = $this->validateRecord($type, $host, $value, $prio))) {
            Helpers::flash('err', $err); Helpers::redirect('/dns?domain='.urlencode($row['domain']));
        }
        $clash = Database::query(
            "SELECT COUNT(*) c FROM dns_records WHERE domain_id=? AND host=? AND id<>? AND ((type='CNAME') OR (?='CNAME'))",
            [$row['domain_id'], $host, $id, $type])->fetch()['c'];
        if ($clash > 0) { Helpers::flash('err',"$host: CNAME cannot share its name with other records"); Helpers::redirect('/dns?domain='.urlencode($row['domain'])); }
        try {
            Database::query("UPDATE dns_records SET type=?,host=?,value=?,priority=?,ttl=? WHERE id=?",
                [$type, $host, $value, $prio, $ttl, $id]);
            $r = $this->apply((int)$row['domain_id'], $row['domain']);
            // same poison rule as add(): restore the previous values
            if (($r['code'] ?? 1) !== 0) {
                try { Database::query("UPDATE dns_records SET type=?,host=?,value=?,priority=?,ttl=? WHERE id=?",
                    [$row['type'], $row['host'], $row['value'], $row['priority'], $row['ttl'], $id]); } catch (Throwable $e2) {}
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
        $row = Database::query("SELECT r.*, d.domain, d.user_id FROM dns_records r JOIN domains d ON d.id=r.domain_id WHERE r.id=?", [$id])->fetch();
        if (!$row || ($u['role'] !== 'admin' && (int)$row['user_id'] !== (int)$u['id'])) {
            Helpers::flash('err','Record not found'); Helpers::redirect('/dns');
        }
        $this->refuseChild($row);
        Database::query("DELETE FROM dns_records WHERE id=?", [$id]);
        $r = $this->apply((int)$row['domain_id'], $row['domain']);
        // same poison rule as add(): a delete BIND refuses (e.g. the apex's
        // ns1/ns2 glue) would break every later rebuild — put the row back
        if (($r['code'] ?? 1) !== 0) {
            try { Database::query("INSERT INTO dns_records(id,domain_id,type,host,value,priority,ttl) VALUES(?,?,?,?,?,?,?)",
                [$id, $row['domain_id'], $row['type'], $row['host'], $row['value'], $row['priority'], $row['ttl']]); } catch (Throwable $e2) {}
            Helpers::flash('err', 'BIND rejected (record kept): '.trim($r['out']));
        } else Helpers::flash('ok', 'Record deleted & reloaded');
        Helpers::redirect('/dns?domain='.urlencode($row['domain']));
    }
    // Secondary nameservers (admin): IPs allowed AXFR + notified on change.
    // The secondary box + glue are documented setup, not managed here.
    public function secondary(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/dns'); }
        $domain = strtolower(trim($_POST['domain'] ?? ''));
        $dom = Database::query("SELECT * FROM domains WHERE domain=?", [$domain])->fetch();
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
            Database::query("UPDATE domains SET secondary_ns=? WHERE id=?", [$csv, $dom['id']]);
            $r = ServiceManager::run('dns-secondary.sh', [$domain, $csv]);
            if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Not applied: '.trim($r['out']));
            else { Helpers::log("secondaries for $domain: ".($csv !== '' ? $csv : 'cleared')." by ".Auth::user()['username']); Helpers::flash('ok', trim($r['out'])); }
        } catch (Throwable $e) { Helpers::flash('err','Failed: '.$e->getMessage()); }
        Helpers::redirect('/dns?domain='.urlencode($domain));
    }
    public function reset(): void {
        // back to the standard KarnakPanel zone (SOA/NS/A/www/mail/MX/SPF)
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/dns'); }
        $u = Auth::user();
        $domain = strtolower(trim($_POST['domain'] ?? ''));
        $dom = $this->findDomain($u, $domain);
        if (!$dom) { Helpers::flash('err','Domain not found'); Helpers::redirect('/dns'); }
        $this->refuseChild($dom);
        try {
            $ip = trim(ServiceManager::run('server-ip.sh', [])['out'] ?? '');
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $ip = '192.0.2.1';
            // defaults = TODAY's nameservers (no domainId: a legacy per-domain
            // ns1/ns2 pair is dropped on purpose — reset is the explicit move)
            $ns = self::effectiveNs((int)$dom['user_id'], $domain);
            Database::query("DELETE FROM dns_records WHERE domain_id=?", [$dom['id']]);
            foreach (self::defaultRows($domain, $ip, $ns) as $r) {
                Database::query("INSERT INTO dns_records(domain_id,type,host,value,priority,ttl) VALUES(?,?,?,?,?,?)",
                    [$dom['id'], $r[0], $r[1], $r[2], 10, 3600]);
            }
            $a = $this->apply((int)$dom['id'], $domain);
            if (($a['code'] ?? 1) !== 0) Helpers::flash('err', 'Reset but BIND rejected: '.trim($a['out']));
            else {
                $ns = self::effectiveNs((int)$dom['user_id'], $domain, (int)$dom['id']);
                Helpers::flash('ok', "Zone reset to KarnakPanel defaults ($ip) — nameservers: {$ns[0]} / {$ns[1]}");
            }
        } catch (Throwable $e) { Helpers::flash('err','Failed: '.$e->getMessage()); }
        Helpers::redirect('/dns?domain='.urlencode($domain));
    }
    // legacy zone -> server (or reseller) nameservers: drop the per-domain
    // ns1/ns2 A rows + any stored NS @ rows, re-render. Explicit only: the
    // registrar must be updated too, so it never happens as a side effect.
    public function useServerNs(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/dns'); }
        $u = Auth::user();
        $domain = strtolower(trim($_POST['domain'] ?? ''));
        $dom = $this->findDomain($u, $domain);
        if (!$dom) { Helpers::flash('err','Domain not found'); Helpers::redirect('/dns'); }
        $this->refuseChild($dom);
        $to = self::effectiveNs((int)$dom['user_id'], $domain);
        if (!self::isLegacyNs((int)$dom['id']) || str_ends_with($to[0], ".$domain")) {
            Helpers::flash('err','This zone already uses the default nameservers'); Helpers::redirect('/dns?domain='.urlencode($domain));
        }
        if (self::hostsBrandNs($domain)) {
            Helpers::flash('err','A reseller\'s white-label nameservers live in this zone — its ns1/ns2 records must stay'); Helpers::redirect('/dns?domain='.urlencode($domain));
        }
        // only the per-domain pair goes; NS @ rows a user added on purpose stay
        $pair = ["ns1.$domain", "ns2.$domain", "ns1.$domain.", "ns2.$domain."];
        $where = "domain_id=? AND ((type='A' AND host IN ('ns1','ns2')) OR (type='NS' AND host='@' AND LOWER(value) IN (?,?,?,?)))";
        $old = Database::query("SELECT * FROM dns_records WHERE $where", array_merge([$dom['id']], $pair))->fetchAll();
        Database::query("DELETE FROM dns_records WHERE $where", array_merge([$dom['id']], $pair));
        $r = $this->apply((int)$dom['id'], $domain);
        if (($r['code'] ?? 1) !== 0) {
            foreach ($old as $o) {
                try { Database::query("INSERT INTO dns_records(id,domain_id,type,host,value,priority,ttl) VALUES(?,?,?,?,?,?,?)",
                    [$o['id'], $o['domain_id'], $o['type'], $o['host'], $o['value'], $o['priority'], $o['ttl']]); } catch (Throwable $e) {}
            }
            Helpers::flash('err', 'BIND rejected (nothing changed): '.trim($r['out']));
        } else {
            Helpers::log("dns $domain switched to nameservers {$to[0]}/{$to[1]} by {$u['username']}");
            Helpers::flash('ok', "Zone now advertises {$to[0]} / {$to[1]} — set the same two at your registrar");
        }
        Helpers::redirect('/dns?domain='.urlencode($domain));
    }
    public function dnssecEnable(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/dns'); }
        $u = Auth::user();
        $domain = strtolower(trim($_POST['domain'] ?? ''));
        $dom = $this->findDomain($u, $domain);
        if (!$dom) { Helpers::flash('err','Domain not found'); Helpers::redirect('/dns'); }
        $this->refuseChild($dom);
        $r = ServiceManager::run('dnssec-enable.sh', [$domain]);
        if (($r['code'] ?? 1) !== 0) { Helpers::flash('err','Signing failed: '.trim($r['out'])); }
        else {
            Database::query("UPDATE domains SET dnssec=1 WHERE id=?", [$dom['id']]);
            Helpers::flash('ok',"Zone signed. DS for the registrar: ".trim($r['out']));
        }
        Helpers::redirect('/dns?domain='.urlencode($domain));
    }
    public function dnssecDisable(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/dns'); }
        $u = Auth::user();
        $domain = strtolower(trim($_POST['domain'] ?? ''));
        $dom = $this->findDomain($u, $domain);
        if (!$dom) { Helpers::flash('err','Domain not found'); Helpers::redirect('/dns'); }
        $r = ServiceManager::run('dnssec-disable.sh', [$domain]);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Unsign failed: '.trim($r['out']));
        else {
            Database::query("UPDATE domains SET dnssec=0 WHERE id=?", [$dom['id']]);
            Helpers::flash('ok',"Signing disabled for $domain (keys kept for re-enable)");
        }
        Helpers::redirect('/dns?domain='.urlencode($domain));
    }
    // zone-file text -> [type,host,value,prio,ttl] rows for $domain. Handles
    // what BIND / Cloudflare / cPanel exports contain: FQDN owners (made
    // relative), blank owner = previous, optional TTL/class in either order,
    // quoted ';' inside TXT (DMARC), multi-chunk TXT (DKIM), $TTL. Skips SOA,
    // the apex NS (the panel manages it), out-of-zone and invalid lines.
    public function parseZone(string $text, string $domain): array {
        $rows = []; $skip = 0; $depth = 0; $prev = '@'; $defTtl = 3600; $pending = null; $pendBlank = false;
        foreach (preg_split('/\r?\n/', $text) as $raw) {
            // strip comments + unquoted ( ) (multi-line records), tracking depth
            $line = ''; $inq = false;
            for ($i = 0, $n = strlen($raw); $i < $n; $i++) {
                $c = $raw[$i];
                if ($c === '\\' && $inq && $i + 1 < $n) { $line .= $c.$raw[++$i]; continue; }
                if ($c === '"') $inq = !$inq;
                if (!$inq && $c === ';') break;
                if (!$inq && ($c === '(' || $c === ')')) { $depth += ($c === '(') ? 1 : -1; $line .= ' '; continue; }
                $line .= $c;
            }
            $depth = max(0, $depth);
            $blankOwner = $line !== '' && ($line[0] === ' ' || $line[0] === "\t");
            if ($pending !== null) { $line = $pending.' '.trim($line); $blankOwner = $pendBlank; }
            if ($depth > 0) { $pending = ($pending === null) ? rtrim($line) : $line; $pendBlank = $blankOwner; continue; }
            $pending = null;
            $line = trim($line);
            if ($line === '') continue;
            if (preg_match('/^\$TTL\s+(\d+)/i', $line, $m)) { $defTtl = (int)$m[1]; continue; }
            if (str_starts_with($line, '$')) continue;
            $types = 'A|AAAA|CNAME|MX|TXT|NS|CAA|SRV|SSHFP';
            $re = '/^(?:(\d+)\s+)?(?:IN\s+)?(?:(\d+)\s+)?('.$types.'|SOA)\s+(.+)$/i';
            if ($blankOwner) $host = $prev;
            else {
                $parts = preg_split('/\s+/', $line, 2);
                $host = $parts[0]; $line = $parts[1] ?? '';
            }
            if (!preg_match($re, $line, $m)) { $skip++; continue; }
            [, $t1, $t2, $type, $val] = $m;
            $type = strtoupper($type);
            $h = strtolower($host);
            if ($h === "$domain." || $h === '@') $h = '@';
            elseif (str_ends_with($h, ".$domain.")) $h = substr($h, 0, -strlen(".$domain."));
            elseif (str_ends_with($h, '.')) { $skip++; continue; }   // other zone
            $prev = $host;
            if ($type === 'SOA' || ($type === 'NS' && $h === '@')) continue;
            $ttl = max(60, min(2147483, (int)($t1 !== '' ? $t1 : ($t2 !== '' ? $t2 : $defTtl))));
            $val = trim($val); $prio = 10;
            if ($type === 'MX' && preg_match('/^(\d+)\s+(\S+)$/', $val, $mm)) { $prio = (int)$mm[1]; $val = $mm[2]; }
            if ($type === 'SRV' && preg_match('/^(\d+)\s+(\d+)\s+(\d+)\s+(\S+)$/', $val, $mm)) { $prio = (int)$mm[1]; $val = $mm[2].' '.$mm[3].' '.$mm[4]; }
            if ($type === 'TXT' && preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $val, $mm)) $val = stripslashes(implode('', $mm[1]));
            // "@"-style or origin-relative targets: make them absolute
            if (in_array($type, ['MX','CNAME','NS'], true)) {
                if ($val === '@') $val = "$domain.";
                elseif (!str_ends_with($val, '.')) $val = "$val.$domain.";
            }
            if ($this->validateRecord($type, $h, $val, $prio)) { $skip++; continue; }
            $rows[] = [$type, $h, $val, $prio, $ttl];
        }
        return [$rows, $skip];
    }
    // drop rows already present / CNAME-clashing (vs DB + earlier in batch)
    private static function newRows(int $domainId, array $rows, int &$skip): array {
        $have = Database::query("SELECT type,host,value FROM dns_records WHERE domain_id=?", [$domainId])->fetchAll();
        $key = fn($t, $h, $v) => strtoupper($t).'|'.strtolower($h).'|'.strtolower(rtrim($v, '.'));
        $seen = []; $byHost = [];
        foreach ($have as $r) { $seen[$key($r['type'], $r['host'], $r['value'])] = 1; $byHost[strtolower($r['host'])][] = $r['type']; }
        $out = [];
        foreach ($rows as $r) {
            $k = $key($r[0], $r[1], $r[2]); $hh = strtolower($r[1]);
            $others = $byHost[$hh] ?? [];
            if (isset($seen[$k]) || ($r[0] === 'CNAME' && $others) || in_array('CNAME', $others, true)) { $skip++; continue; }
            $seen[$k] = 1; $byHost[$hh][] = $r[0]; $out[] = $r;
        }
        return $out;
    }
    // insert $rows, delete $drop (ids), apply; on BIND refusal undo both
    private function applyBatch(array $dom, array $rows, array $drop = []): array {
        $old = [];
        foreach ($drop as $id) {
            $o = Database::query("SELECT * FROM dns_records WHERE id=? AND domain_id=?", [(int)$id, $dom['id']])->fetch();
            if ($o) { $old[] = $o; Database::query("DELETE FROM dns_records WHERE id=?", [$o['id']]); }
        }
        $ins = [];
        foreach ($rows as $r) {
            Database::query("INSERT INTO dns_records(domain_id,type,host,value,priority,ttl) VALUES(?,?,?,?,?,?)", [$dom['id'], $r[0], $r[1], $r[2], $r[3] ?? 10, $r[4] ?? 3600]);
            $ins[] = (int)Database::conn()->lastInsertId();
        }
        $r = $this->apply((int)$dom['id'], $dom['domain']);
        if (($r['code'] ?? 1) !== 0) {
            foreach ($ins as $id) { try { Database::query("DELETE FROM dns_records WHERE id=?", [$id]); } catch (Throwable $e) {} }
            foreach ($old as $o) {
                try { Database::query("INSERT INTO dns_records(id,domain_id,type,host,value,priority,ttl) VALUES(?,?,?,?,?,?,?)",
                    [$o['id'], $o['domain_id'], $o['type'], $o['host'], $o['value'], $o['priority'], $o['ttl']]); } catch (Throwable $e) {}
            }
        }
        return $r;
    }
    public function import(): void {
        // pasted zone text (other provider's export) -> rows + apply, or the
        // on-disk zone (created by scripts/older versions) -> rows only
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/dns'); }
        $u = Auth::user();
        $domain = strtolower(trim($_POST['domain'] ?? ''));
        $dom = $this->findDomain($u, $domain);
        if (!$dom) { Helpers::flash('err','Domain not found'); Helpers::redirect('/dns'); }
        $this->refuseChild($dom);
        $back = '/dns?domain='.urlencode($domain);
        $paste = (string)($_POST['zone'] ?? '');
        if (trim($paste) !== '') {
            if (strlen($paste) > 65536) { Helpers::flash('err','Zone text too large (64 KB max)'); Helpers::redirect($back); }
            [$rows, $skip] = $this->parseZone($paste, $domain);
            $rows = self::newRows((int)$dom['id'], $rows, $skip);
            if (count($rows) > 500) { Helpers::flash('err','Too many records (500 max per import)'); Helpers::redirect($back); }
            if (!$rows) { Helpers::flash('err', "Nothing new to import".($skip ? " ($skip line(s) skipped: duplicates, SOA/apex NS, other zones or invalid)" : '')); Helpers::redirect($back); }
            // one SPF per host / one DMARC per name: two make receivers
            // ignore both, so an imported one REPLACES the existing one
            $drop = [];
            foreach ($rows as $r) {
                if ($r[0] !== 'TXT') continue;
                foreach (['v=spf1', 'v=dmarc1'] as $pre) {
                    if (!str_starts_with(strtolower($r[2]), $pre)) continue;
                    foreach (Database::query("SELECT id FROM dns_records WHERE domain_id=? AND type='TXT' AND host=? AND LOWER(value) LIKE ?", [$dom['id'], $r[1], "$pre%"])->fetchAll() as $o) $drop[] = (int)$o['id'];
                }
            }
            $r = $this->applyBatch($dom, $rows, array_unique($drop));
            if (($r['code'] ?? 1) !== 0) Helpers::flash('err', 'BIND rejected (nothing imported): '.trim($r['out']));
            else {
                Helpers::log("dns $domain: imported ".count($rows)." pasted record(s) by {$u['username']}");
                Helpers::flash('ok', 'Imported '.count($rows).' record(s) & reloaded'.($drop ? ' (replaced '.count(array_unique($drop)).' existing SPF/DMARC)' : '').($skip ? " — skipped $skip (duplicates, SOA/apex NS, other zones or invalid)" : ''));
            }
            Helpers::redirect($back);
        }
        $zoneFile = "/etc/bind/zones/db.".preg_replace('/[^a-z0-9.-]/i','',$domain);
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- zone path sanitized to [a-z0-9.-] under fixed dir + ownership-checked domain (read-only check)
        if (!is_readable($zoneFile)) { Helpers::flash('err','No zone file on disk'); Helpers::redirect($back); }
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- zone path: sanitized + ownership-checked domain
        [$rows, $skip] = $this->parseZone((string)file_get_contents($zoneFile), $domain);
        $n = 0;
        foreach (self::newRows((int)$dom['id'], $rows, $skip) as $r) {
            try {
                Database::query("INSERT INTO dns_records(domain_id,type,host,value,priority,ttl) VALUES(?,?,?,?,?,?)", [$dom['id'], $r[0], $r[1], $r[2], $r[3], $r[4]]);
                $n++;
            } catch (Throwable $e) { $skip++; }
        }
        Helpers::flash('ok', "Imported $n record(s)".($skip ? ", skipped $skip" : '')." — zone file untouched until you add/edit");
        Helpers::redirect($back);
    }
    // one-click provider records. Mail providers REPLACE the apex MX + SPF
    // (a leftover local MX would split delivery); everything goes in one
    // apply with full rollback.
    public static function templates(string $domain): array {
        $m365 = str_replace('.', '-', $domain).'.mail.protection.outlook.com';
        return [
            'google'  => ['label' => 'Google Workspace mail', 'mail' => true, 'rows' => [
                ['MX','@','smtp.google.com',1,3600], ['TXT','@','v=spf1 include:_spf.google.com ~all',10,3600]]],
            'm365'    => ['label' => 'Microsoft 365 mail', 'mail' => true, 'rows' => [
                ['MX','@',$m365,0,3600], ['TXT','@','v=spf1 include:spf.protection.outlook.com -all',10,3600],
                ['CNAME','autodiscover','autodiscover.outlook.com',10,3600]]],
            'local'   => ['label' => 'This server\'s mail (default)', 'mail' => true, 'rows' => [
                ['MX','@',"mail.$domain",10,3600], ['TXT','@','v=spf1 a mx ~all',10,3600]]],
            'dmarc'   => ['label' => 'DMARC (monitor only)', 'replace' => ['TXT','_dmarc'], 'rows' => [
                ['TXT','_dmarc',"v=DMARC1; p=none; rua=mailto:postmaster@$domain",10,3600]]],
            'caa'     => ['label' => 'CAA: only Let\'s Encrypt may issue', 'rows' => [
                ['CAA','@','0 issue letsencrypt.org',10,3600], ['CAA','@','0 issuewild letsencrypt.org',10,3600]]],
        ];
    }
    // presets for the page being viewed. A child (subdomain without a zone)
    // gets the mail ones, written into the parent zone under its label
    // (MailDns) — only when that zone is one the viewer manages here.
    public function templatesFor(array $u, array $dom): array {
        $t = MailDns::target($dom);
        $all = self::templates((string)$dom['domain']);
        if ($t['label'] === '') return $all;
        if (!$t['zone'] || !$t['hosted'] || !$this->findDomain($u, $t['origin'])) return [];
        $out = [];
        foreach (['google', 'm365', 'local', 'dmarc'] as $k) {
            $tp = $all[$k];
            foreach ($tp['rows'] as $i => $r) {
                $tp['rows'][$i][1] = MailDns::host($t, $r[1]);
                if ($k === 'local' && $r[0] === 'MX') $tp['rows'][$i][2] = MailDns::mxTarget($t, $dom);
            }
            if (!empty($tp['replace'])) $tp['replace'][1] = MailDns::host($t, $tp['replace'][1]);
            $out[$k] = $tp;
        }
        return $out;
    }
    public function template(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/dns'); }
        $u = Auth::user();
        $domain = strtolower(trim($_POST['domain'] ?? ''));
        $dom = $this->findDomain($u, $domain);
        if (!$dom) { Helpers::flash('err','Domain not found'); Helpers::redirect('/dns'); }
        $t = MailDns::target($dom);
        $tpls = $this->templatesFor($u, $dom);
        if ($t['label'] !== '' && !$tpls) $this->refuseChild($dom);
        // rows go to the zone that holds them (the parent, for a child)
        $zdom = $t['label'] !== '' ? $t['zone'] : $dom;
        $at = MailDns::host($t, '@');
        $back = '/dns?domain='.urlencode((string)$zdom['domain']);
        $tpl = $tpls[(string)($_POST['template'] ?? '')] ?? null;
        if (!$tpl) { Helpers::flash('err','Unknown template'); Helpers::redirect($back); }
        foreach ($tpl['rows'] as $r) {
            if ($e = $this->validateRecord($r[0], $r[1], $r[2], $r[3])) { Helpers::flash('err', "Template invalid for this domain: $e"); Helpers::redirect($back); }
        }
        $drop = [];
        if (!empty($tpl['mail'])) {
            // + a previous M365 autodiscover CNAME (stale once mail moves)
            foreach (Database::query("SELECT id FROM dns_records WHERE domain_id=? AND ((host=? AND (type='MX' OR (type='TXT' AND LOWER(value) LIKE 'v=spf1%'))) OR (type='CNAME' AND host=? AND LOWER(TRIM(TRAILING '.' FROM value))='autodiscover.outlook.com'))", [$zdom['id'], $at, MailDns::host($t, 'autodiscover')])->fetchAll() as $r) $drop[] = (int)$r['id'];
        }
        if (!empty($tpl['replace'])) {
            foreach (Database::query("SELECT id FROM dns_records WHERE domain_id=? AND type=? AND host=?", [$zdom['id'], $tpl['replace'][0], $tpl['replace'][1]])->fetchAll() as $r) $drop[] = (int)$r['id'];
        }
        $skip = 0;
        // dedupe against what stays (dropped rows are being replaced)
        $keepIds = $drop ? ' AND id NOT IN ('.implode(',', array_map('intval', $drop)).')' : '';
        $have = Database::query("SELECT type,host,value FROM dns_records WHERE domain_id=?$keepIds", [$zdom['id']])->fetchAll();
        $rows = [];
        foreach ($tpl['rows'] as $r) {
            $clash = false;
            foreach ($have as $h) {
                $same = strtoupper($h['type']) === $r[0] && strtolower($h['host']) === $r[1] && strtolower(rtrim($h['value'], '.')) === strtolower($r[2]);
                $cn = strtolower($h['host']) === $r[1] && ($r[0] === 'CNAME' || strtoupper($h['type']) === 'CNAME');
                if ($same || $cn) { $clash = true; break; }
            }
            if ($clash) $skip++; else $rows[] = $r;
        }
        if (!$rows && !$drop) { Helpers::flash('ok', "{$tpl['label']}: already in place"); Helpers::redirect($back); }
        $r = $this->applyBatch($zdom, $rows, $drop);
        if (($r['code'] ?? 1) !== 0) { Helpers::flash('err', 'BIND rejected (nothing changed): '.trim($r['out'])); Helpers::redirect($back); }
        Helpers::log("dns $domain: template $tpl[label] applied by {$u['username']}".($t['label'] !== '' ? " (in the {$t['origin']} zone)" : ''));
        $msg = "{$tpl['label']} applied & reloaded".($skip ? " ($skip record(s) already present or blocked by an existing CNAME/record — skipped)" : '');
        if (!empty($tpl['mail'])) {
            $remote = ($_POST['template'] ?? '') !== 'local';
            $msg .= $remote
                ? ' — now set Mail to 📮 Remote on the Domains page so this server stops accepting mail for the domain locally'
                : ' — set Mail back to 📬 Local on the Domains page if it was Remote';
        }
        Helpers::flash('ok', $msg);
        Helpers::redirect($back);
    }
    // the live zone file as a download (BIND format — importable elsewhere)
    public function export(): void {
        Auth::requireLogin();
        $u = Auth::user();
        $domain = strtolower(trim((string)($_GET['domain'] ?? '')));
        $dom = $this->findDomain($u, $domain);
        if (!$dom) { Helpers::flash('err','Domain not found'); Helpers::redirect('/dns'); }
        $zoneFile = "/etc/bind/zones/db.".preg_replace('/[^a-z0-9.-]/', '', $domain);
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- zone path sanitized to [a-z0-9.-] under fixed dir + ownership-checked domain
        $z = is_readable($zoneFile) ? (string)file_get_contents($zoneFile) : '';
        if ($z === '') { Helpers::flash('err','No zone file on disk yet'); Helpers::redirect('/dns?domain='.urlencode($domain)); }
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.preg_replace('/[^a-z0-9.-]/', '', $domain).'.zone"');
        header('X-Content-Type-Options: nosniff');
        // nosemgrep: php.lang.security.injection.echoed-request.echoed-request, php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag -- text/plain + nosniff attachment; $domain matched exactly against the user's own domains
        echo '$ORIGIN '.preg_replace('/[^a-z0-9.-]/', '', $domain).".\n", $z;
        exit;
    }
}
