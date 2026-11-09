<?php
declare(strict_types=1);
class DnsZoneController extends Controller {
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
            if ((new DnsController())->validateRecord($type, $h, $val, $prio)) { $skip++; continue; }
            $rows[] = [$type, $h, $val, $prio, $ttl];
        }
        return [$rows, $skip];
    }
    // drop rows already present / CNAME-clashing (vs DB + earlier in batch)
    private static function newRows(int $domainId, array $rows, int &$skip): array {
        $have = DnsModel::keysFor($domainId);
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
            $o = DnsModel::ownedRecord((int)$id, (int)$dom['id']);
            if ($o) { $old[] = $o; DnsModel::delete((int)$o['id']); }
        }
        $ins = [];
        foreach ($rows as $r) {
            $ins[] = DnsModel::insert((int)$dom['id'], $r[0], $r[1], $r[2], $r[3] ?? 10, $r[4] ?? 3600);
        }
        $r = (new DnsController())->apply((int)$dom['id'], $dom['domain']);
        if (($r['code'] ?? 1) !== 0) {
            foreach ($ins as $id) { try { DnsModel::delete($id); } catch (Throwable $e) {} }
            foreach ($old as $o) {
                try { DnsModel::restore($o); } catch (Throwable $e) {}
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
        $dom = DomainModel::findInScope($u, $domain);
        if (!$dom) { Helpers::flash('err','Domain not found'); Helpers::redirect('/dns'); }
        DnsController::refuseChild($dom);
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
                    foreach (DnsModel::txtIdsStarting((int)$dom['id'], $r[1], $pre) as $o) $drop[] = $o;
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
                DnsModel::insert((int)$dom['id'], $r[0], $r[1], $r[2], $r[3], $r[4]);
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
                ['MX','@',"mail.$domain",10,3600], ['TXT','@',MailDns::spfDefault(),10,3600]]],
            'dmarcq'  => ['label' => 'DMARC (quarantine — recommended)', 'replace' => ['TXT','_dmarc'], 'rows' => [
                ['TXT','_dmarc',MailDns::DMARC_DEFAULT,10,3600]]],
            'dmarc'   => ['label' => 'DMARC (monitor only)', 'replace' => ['TXT','_dmarc'], 'rows' => [
                ['TXT','_dmarc',"v=DMARC1; p=none; rua=mailto:postmaster@$domain",10,3600]]],
            'caa'     => ['label' => 'CAA: only Let\'s Encrypt may issue', 'rows' => [
                ['CAA','@','0 issue letsencrypt.org',10,3600], ['CAA','@','0 issuewild letsencrypt.org',10,3600]]],
        ];
    }
    // presets for the page being viewed. A child (subdomain without a zone)
    // gets the mail ones, written into the parent zone under its label
    // (MailDns) — only when that zone is one the viewer manages here.
    public static function templatesFor(array $u, array $dom): array {
        $t = MailDns::target($dom);
        $all = self::templates((string)$dom['domain']);
        if ($t['label'] === '') return $all;
        if (!$t['zone'] || !$t['hosted'] || !DomainModel::findInScope($u, $t['origin'])) return [];
        $out = [];
        foreach (['google', 'm365', 'local', 'dmarcq', 'dmarc'] as $k) {
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
        $dom = DomainModel::findInScope($u, $domain);
        if (!$dom) { Helpers::flash('err','Domain not found'); Helpers::redirect('/dns'); }
        $t = MailDns::target($dom);
        $tpls = self::templatesFor($u, $dom);
        if ($t['label'] !== '' && !$tpls) DnsController::refuseChild($dom);
        // rows go to the zone that holds them (the parent, for a child)
        $zdom = $t['label'] !== '' ? $t['zone'] : $dom;
        $at = MailDns::host($t, '@');
        $back = '/dns?domain='.urlencode((string)$zdom['domain']);
        $tpl = $tpls[(string)($_POST['template'] ?? '')] ?? null;
        if (!$tpl) { Helpers::flash('err','Unknown template'); Helpers::redirect($back); }
        foreach ($tpl['rows'] as $r) {
            if ($e = (new DnsController())->validateRecord($r[0], $r[1], $r[2], $r[3])) { Helpers::flash('err', "Template invalid for this domain: $e"); Helpers::redirect($back); }
        }
        $drop = [];
        if (!empty($tpl['mail'])) {
            // + a previous M365 autodiscover CNAME (stale once mail moves)
            foreach (DnsModel::mailTemplateDropIds((int)$zdom['id'], $at, MailDns::host($t, 'autodiscover')) as $r) $drop[] = $r;
        }
        if (!empty($tpl['replace'])) {
            foreach (DnsModel::idsByTypeHost((int)$zdom['id'], $tpl['replace'][0], $tpl['replace'][1]) as $r) $drop[] = $r;
        }
        $skip = 0;
        // dedupe against what stays (dropped rows are being replaced)

        $have = DnsModel::keysForExcept((int)$zdom['id'], $drop);
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
        $dom = DomainModel::findInScope($u, $domain);
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
