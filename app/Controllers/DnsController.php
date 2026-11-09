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
            $ownerId = DnsModel::ownerOf($userId);
            if ($ownerId !== null && $ownerId > 0) {
                $r = DnsModel::resellerNs($ownerId);
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
            $pd = strtolower(trim((string)(Settings::get('panel_domain') ?? '')));
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
    public static function refuseChild(array $dom): void {
        if ($pz = self::parentZone($dom)) {
            Helpers::flash('err', "{$dom['domain']} has no zone of its own — its records live in the $pz zone");
            Helpers::redirect('/dns?domain='.urlencode($pz));
        }
    }
    public static function isLegacyNs(int $domainId): bool {
        return DnsModel::isLegacyNs($domainId);
    }
    // every reseller white-label nameserver: when one lives inside a zone
    // here (the reseller's brand.com), that zone must carry its A glue or
    // all of that reseller's client zones stop resolving
    public static function brandNs(): array {
        $out = [];
        try {
            foreach (DnsModel::brandNsRows() as $r) {
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
        $rows[] = ['TXT','@',MailDns::SPF_DEFAULT];
        $rows[] = ['TXT','_dmarc',MailDns::DMARC_DEFAULT];
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
        // record count per listed zone (one grouped query for the page)
        $rcount = [];
        $ids = array_map('intval', array_column($domlist, 'id'));
        if ($ids) {
            try { $rcount = DnsModel::countsByDomain($ids); } catch (Throwable $e) {}
        }
        // listed names that are records in a parent zone, not zones themselves
        $lparent = [];
        foreach ($domlist as $ld) { try { if ($pz = self::parentZone($ld)) $lparent[(int)$ld['id']] = $pz; } catch (Throwable $e) {} }
        if (!empty($_GET['domain'])) {
            $d = strtolower(trim($_GET['domain']));
            $dom = $this->findDomain($u, $d);
            if (!$dom) Helpers::flash('err','Domain not found in your account');
            else {
                $viewing = $dom;
                $rq = substr(trim((string)($_GET['rq'] ?? '')), 0, 64);
                $rlike = ($rq !== '') ? '%'.str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], strtolower($rq)).'%' : null;
                $p = Helpers::paginate(DnsModel::count((int)$dom['id'], $rlike), max(1, (int)($_GET['page'] ?? 1)));
                $pg = $p['pg']; $pages = $p['pages']; $total = $p['total'];
                $records = DnsModel::page((int)$dom['id'], $rlike, (int)$p['limit'], (int)$p['offset']);
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
        try { $glueOk = (string)(Settings::get('glue_ok') ?? '') === '1'; } catch (Throwable $e) {}
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
            // same look refreshes the cached "DNS is at the registrar" verdict
            ServiceManager::run('dns-delegation-check.sh', [strtolower((string)$viewing['domain'])], 60);
            $viewing = DnsModel::domainById((int)$viewing['id']) ?? $viewing;
        }
        $templates = $viewing ? DnsZoneController::templatesFor($u, $viewing) : [];
        $pg = $pg ?? 1; $pages = $pages ?? 1; $total = $total ?? count($records ?? []);
        $this->view('dns', compact('domains','records','viewing','zone','dnssecSigned','dnssecDs','pg','pages','total','rq','domlist','dp','dpages','dtotal','lq','nameservers','nsSwitchTo','glueOk','parentZone','parentHosted','protected','check','templates','rcount','lparent'));
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
        $rows = DnsModel::recordsFor($domainId);
        [$ns1, $ns2] = self::effectiveNs((int)DnsModel::domainOwner($domainId), $domain, $domainId);
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
        // zone text goes on the script's stdin (Secret = @stdin): no staging
        // file in the shared /tmp for another account to plant or race
        return ServiceManager::run('dns-save-zone.sh', [$domain, new Secret($z)]);
    }
    public function reset(): void {
        // back to the standard KarnakPanel zone (SOA/NS/A/www/mail/MX/SPF/DMARC)
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/dns'); }
        $u = Auth::user();
        $domain = strtolower(trim($_POST['domain'] ?? ''));
        $dom = $this->findDomain($u, $domain);
        if (!$dom) { Helpers::flash('err','Domain not found'); Helpers::redirect('/dns'); }
        self::refuseChild($dom);
        try {
            $ip = trim(ServiceManager::run('server-ip.sh', [])['out'] ?? '');
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $ip = '192.0.2.1';
            // defaults = TODAY's nameservers (no domainId: a legacy per-domain
            // ns1/ns2 pair is dropped on purpose — reset is the explicit move)
            $ns = self::effectiveNs((int)$dom['user_id'], $domain);
            DnsModel::deleteFor((int)$dom['id']);
            foreach (self::defaultRows($domain, $ip, $ns) as $r) {
                DnsModel::insert((int)$dom['id'], $r[0], $r[1], $r[2], 10, 3600);
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
        self::refuseChild($dom);
        $to = self::effectiveNs((int)$dom['user_id'], $domain);
        if (!self::isLegacyNs((int)$dom['id']) || str_ends_with($to[0], ".$domain")) {
            Helpers::flash('err','This zone already uses the default nameservers'); Helpers::redirect('/dns?domain='.urlencode($domain));
        }
        if (self::hostsBrandNs($domain)) {
            Helpers::flash('err','A reseller\'s white-label nameservers live in this zone — its ns1/ns2 records must stay'); Helpers::redirect('/dns?domain='.urlencode($domain));
        }
        // only the per-domain pair goes; NS @ rows a user added on purpose stay
        $pair = ["ns1.$domain", "ns2.$domain", "ns1.$domain.", "ns2.$domain."];
        $old = DnsModel::legacyNsRows((int)$dom['id'], $pair);
        DnsModel::deleteLegacyNsRows((int)$dom['id'], $pair);
        $r = $this->apply((int)$dom['id'], $domain);
        if (($r['code'] ?? 1) !== 0) {
            foreach ($old as $o) {
                try { DnsModel::restore($o); } catch (Throwable $e) {}
            }
            Helpers::flash('err', 'BIND rejected (nothing changed): '.trim($r['out']));
        } else {
            Helpers::log("dns $domain switched to nameservers {$to[0]}/{$to[1]} by {$u['username']}");
            Helpers::flash('ok', "Zone now advertises {$to[0]} / {$to[1]} — set the same two at your registrar");
        }
        Helpers::redirect('/dns?domain='.urlencode($domain));
    }
}
