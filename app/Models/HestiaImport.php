<?php
declare(strict_types=1);
// HestiaCP import, PHP half (scripts/hestia-import.sh is the driver; it runs
// these as root via the panel CLI loader). Everything free-text from the
// untrusted archive — DNS values, cron commands, auto-reply bodies — goes
// through here: prepared statements + the DNS page's own validator, never
// bash-built SQL. Input rows are TSV files the driver wrote (root-only temp
// dir), free-text columns base64. Output: one report line per event.
class HestiaImport {
    private static function owner(string $username): array {
        $o = Database::query("SELECT * FROM users WHERE username=?", [$username])->fetch();
        if (!$o) throw new RuntimeException("no panel user $username");
        return $o;
    }
    private static function rows(string $file, int $cols): array {
        $out = [];
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- driver-written TSV in its own root-only mktemp dir (CLI only, no request data)
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
            $f = explode("\t", $l);
            if (count($f) === $cols) $out[] = $f;
        }
        return $out;
    }
    // "ok <id> created|existing" or "skip <reason>"
    public static function ensureDomain(string $username, string $domain, string $phpHint): void {
        try {
            $o = self::owner($username);
            $d = Database::query("SELECT * FROM domains WHERE domain=?", [$domain])->fetch();
            if ($d) {
                if ((int)$d['user_id'] !== (int)$o['id']) { echo "skip hosted by another account\n"; return; }
                echo "ok {$d['id']} existing\n"; return;
            }
            if ($err = Helpers::limitError($o, 'domains')) { echo "skip $err\n"; return; }
            if ($parent = DomainModel::parentOf($domain, (int)$o['id'])) {
                $label = substr($domain, 0, -strlen('.'.$parent['domain']));
                // same rule as the Subdomains tab (validDomain wants an apex)
                if (!preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $label)) { echo "skip bad subdomain label\n"; return; }
                [$id] = DomainProvisioner::createSub($o, $parent, $label, PhpVersions::orDefault($parent['php_version'] ?? null));
                echo "ok $id created\n"; return;
            }
            if (!Helpers::validDomain($domain)) { echo "skip bad name\n"; return; }
            [$id, , $v] = DomainProvisioner::createTop($o, $domain, DomainProvisioner::effectivePhp((int)$o['id'], $phpHint));
            if (($v['code'] ?? 1) !== 0) echo "note vhost: ".str_replace("\n", ' ', trim($v['out'] ?? ''))."\n";
            echo "ok $id created\n";
        } catch (Throwable $e) { echo "skip ".str_replace("\n", ' ', $e->getMessage())."\n"; }
    }
    // OLS doesn't read .htaccess rewrites: same converter as the Domains page
    // $ht: the import's root-private copy of the site's .htaccess. This runs
    // as root, so it never opens a path inside the customer's docroot (a
    // planted link there would make root read any file).
    public static function rewrites(int $domainId, string $ht): void {
        $d = Database::query("SELECT * FROM domains WHERE id=?", [$domainId])->fetch();
        if (!$d) return;
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- root-private import copy passed by hestia-import.sh
        if (is_link($ht) || !is_file($ht) || filesize($ht) > 65536) return;
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- same root-private copy; converter whitelist gates what reaches the vhost
        $src = (string)file_get_contents($ht);
        [$rules, $converted, $skipped] = DomainController::convertHtaccess($src);
        if ($converted === 0) return;
        [$clean, $err] = DomainController::cleanRules(implode("\n", $rules));
        if ($err !== null) { echo "rewrites {$d['domain']}: converter output refused ($err)\n"; return; }
        Database::query("UPDATE domains SET rewrite_rules=?, rewrite_src_hash=?, rewrite_imported_at=NOW() WHERE id=?", [$clean, hash('sha256', $src), $domainId]);
        $r = ServiceManager::run('rewrite-sync.sh', [$d['domain']]);
        echo "rewrites {$d['domain']}: $converted rule(s) from .htaccess".($skipped ? ", $skipped skipped" : '').((($r['code'] ?? 1) !== 0) ? ' (sync refused: '.trim($r['out'] ?? '').')' : '')."\n";
    }
    // TSV: zone host type prio ttl b64(value). $oldIps: the source server's
    // addresses (records pointing there are re-pointed here). $newDoms:
    // domains this import created (only those lose the seeded MX when the
    // zone's mail lives elsewhere — an existing domain's DNS is the owner's).
    public static function dns(string $username, string $file, string $oldIps, string $newDoms): void {
        $o = self::owner($username);
        $old = array_filter(explode(' ', $oldIps));
        $new = array_filter(explode(' ', $newDoms));
        $ip = DomainProvisioner::serverIp();
        $dc = new DnsController();
        $touched = []; $extMx = []; $noted = []; $n = 0;
        foreach (self::rows($file, 6) as [$zone, $host, $type, $prio, $ttl, $b64]) {
            $type = strtoupper($type);
            $val = trim(str_replace('%quote%', "'", (string)base64_decode($b64, true)));
            $zd = Database::query("SELECT * FROM domains WHERE domain=? AND user_id=?", [$zone, $o['id']])->fetch();
            if (!$zd) { if (!isset($noted[$zone])) echo "dns $zone: not hosted here, zone skipped\n"; $noted[$zone] = 1; continue; }
            // a subdomain's records live in the PARENT zone, host relativized
            $target = $zd; $suffix = '';
            if ($p = DomainModel::parentOf($zone, (int)$o['id'])) {
                $target = $p; $suffix = substr($zone, 0, -strlen('.'.$p['domain']));
            }
            if ($host !== '@' && str_ends_with($host, '.'.$zone.'.')) $host = substr($host, 0, -strlen('.'.$zone.'.'));
            $h = ($host === '@') ? ($suffix === '' ? '@' : $suffix) : ($suffix === '' ? $host : "$host.$suffix");
            $h = strtolower($h);
            if ($type === 'SOA' || ($type === 'NS' && $host === '@')) continue;
            if (str_contains($h, '_domainkey')) { if (!isset($noted["dkim$zone"])) echo "dns $zone: old DKIM key skipped (this panel signs with its own)\n"; $noted["dkim$zone"] = 1; continue; }
            if (in_array($type, ['CNAME','MX','NS'], true)) $val = strtolower($val);
            if ($type === 'TXT' || $type === 'CAA') $val = str_replace('"', '', $val);
            $tid = (int)$target['id'];
            // old-server address: move it ONLY for names this panel hosts (+
            // their service names) — anything else on that box (e.g. the
            // Hestia panel's own hostname) stays where it is
            if (in_array($type, ['A','AAAA'], true) && in_array($val, $old, true)) {
                $fq = ($h === '@') ? $target['domain'] : $h.'.'.$target['domain'];
                $base = preg_replace('/^(www|mail|ftp|webmail|ns1|ns2|smtp|imap|pop|autodiscover|autoconfig)\./', '', $fq);
                $hosted = Database::query("SELECT COUNT(*) c FROM domains WHERE user_id=? AND domain IN (?,?)", [$o['id'], $fq, $base])->fetch()['c'];
                if (!$hosted) { echo "dns $zone: $fq still points at the old server ($val) — kept\n"; }
                elseif ($type === 'AAAA') continue;   // no v6 equivalent known here
                else $val = $ip;
            }
            // SPF naming the old box: this one sends now
            if ($type === 'TXT' && str_starts_with(strtolower($val), 'v=spf1')) {
                foreach ($old as $oi) if (filter_var($oi, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $val = preg_replace('/\bip4:'.preg_quote($oi, '/').'(?=\s|$)/i', 'ip4:'.$ip, $val);
            }
            if ($type === 'MX' && $h === '@' && $suffix === '') {
                $mv = rtrim($val, '.');
                if ($mv === 'mail.'.$zone || in_array($mv, $old, true)) continue;  // seeded default covers it
                $extMx[$tid] = $target['domain'];
            }
            if ($type === 'TXT' && str_starts_with(strtolower($val), 'v=spf1')) {
                $has = Database::query("SELECT COUNT(*) c FROM dns_records WHERE domain_id=? AND host=? AND type='TXT' AND value LIKE 'v=spf1%'", [$tid, $h])->fetch()['c'];
                if ($has) {
                    if (preg_match('/\b(include:|ip4:|ip6:|a:|mx:)/i', $val)) echo "dns $zone: source SPF had extra senders ($val) — review the SPF record\n";
                    continue;
                }
            }
            $p = in_array($type, ['MX','SRV'], true) ? (int)$prio : 10;
            // the source's own DMARC policy replaces the seeded default (one
            // DMARC per name — two make receivers ignore both)
            $isDmarc = $type === 'TXT' && stripos(trim($val, '"'), 'v=DMARC1') === 0;
            if ($e = $dc->validateRecord($type, $h, $val, $p)) { echo "dns $zone: $type $h skipped ($e)\n"; continue; }
            $dup = Database::query("SELECT COUNT(*) c FROM dns_records WHERE domain_id=? AND host=? AND type=? AND value=?", [$tid, $h, $type, $val])->fetch()['c'];
            $cn = Database::query("SELECT COUNT(*) c FROM dns_records WHERE domain_id=? AND host=? AND type='CNAME'", [$tid, $h])->fetch()['c'];
            $any = Database::query("SELECT COUNT(*) c FROM dns_records WHERE domain_id=? AND host=?", [$tid, $h])->fetch()['c'];
            if ($dup || $cn || ($type === 'CNAME' && $any)) continue;
            // single-value hosts: an A already at this name (seeded) wins
            if ($type === 'A' && Database::query("SELECT COUNT(*) c FROM dns_records WHERE domain_id=? AND host=? AND type='A'", [$tid, $h])->fetch()['c']) continue;
            $t = max(60, min(2147483, (int)$ttl ?: 3600));
            if ($isDmarc) Database::query("DELETE FROM dns_records WHERE domain_id=? AND host=? AND type='TXT' AND value LIKE 'v=DMARC1%'", [$tid, $h]);
            Database::query("INSERT INTO dns_records(domain_id,type,host,value,priority,ttl) VALUES(?,?,?,?,?,?)", [$tid, $type, $h, $val, $p, $t]);
            $touched[$tid] = $target['domain']; $n++;
        }
        foreach ($extMx as $tid => $dom) {
            if (!in_array($dom, $new, true)) { echo "dns $dom: mail is hosted elsewhere (external MX imported) — check the MX set\n"; continue; }
            Database::query("DELETE FROM dns_records WHERE domain_id=? AND type='MX' AND host='@' AND value=?", [$tid, 'mail.'.$dom]);
            $touched[$tid] = $dom;
            echo "dns $dom: mail is hosted elsewhere — kept the source MX, dropped mail.$dom (set Mail routing to Remote on Domains)\n";
        }
        foreach ($touched as $tid => $dom) {
            $r = $dc->apply((int)$tid, $dom);
            if (($r['code'] ?? 1) !== 0) echo "dns $dom: zone render refused: ".str_replace("\n", ' | ', trim($r['out'] ?? ''))."\n";
        }
        echo "dns: $n record(s) imported\n";
    }
    // TSV: schedule b64(command) suspended. Imported PAUSED: paths and php
    // binaries belong to the old box — the owner reviews, then enables.
    public static function cron(string $username, string $file, string $hUser): void {
        $o = self::owner($username);
        $n = 0;
        foreach (self::rows($file, 3) as [$sch, $b64, $susp]) {
            $cmd = trim(str_replace('%quote%', "'", (string)base64_decode($b64, true)));
            if ($cmd === '' || str_contains($cmd, '/usr/local/hestia') || preg_match('/(^|[\s;&|\/])v-[a-z]/', $cmd)) { echo "cron: Hestia system job skipped\n"; continue; }
            if (!preg_match('/^([\*\d,\-\/]+)\s+([\*\d,\-\/]+)\s+([\*\d,\-\/]+)\s+([\*\d,\-\/]+)\s+([\*\d,\-\/]+)$/', $sch)) { echo "cron: bad schedule skipped ($sch)\n"; continue; }
            // Hestia paths -> this panel's layout
            $cmd = str_replace(["/home/$hUser/web/", "/home/$hUser/"], ["/home/$username/", "/home/$username/"], $cmd);
            if (strlen($cmd) > 500 || str_contains($cmd, "\n")) { echo "cron: command too long/multi-line, skipped\n"; continue; }
            $dup = Database::query("SELECT COUNT(*) c FROM cron_jobs WHERE user_id=? AND schedule=? AND command=?", [$o['id'], $sch, $cmd])->fetch()['c'];
            if ($dup) continue;
            Database::query("INSERT INTO cron_jobs(user_id,schedule,command,active) VALUES(?,?,?,0)", [$o['id'], $sch, $cmd]);
            $n++;
        }
        if ($n) echo "cron: $n job(s) imported PAUSED — review paths, then enable on the Cron page\n";
    }
    // TSV: domain localpart b64(body). Hestia replies carry no subject.
    public static function autoreply(string $username, string $file): void {
        $o = self::owner($username);
        $n = 0;
        foreach (self::rows($file, 3) as [$dom, $local, $b64]) {
            $body = trim(substr((string)base64_decode($b64, true), 0, 2000));
            if ($body === '') continue;
            $n += Database::query("UPDATE email_accounts e JOIN domains d ON d.id=e.domain_id SET e.autoresp_enabled=1, e.autoresp_subject='Auto-reply', e.autoresp_text=? WHERE d.domain=? AND d.user_id=? AND e.localpart=?", [$body, $dom, $o['id'], $local])->rowCount();
        }
        if ($n) echo "auto-replies: $n (subject set to 'Auto-reply'; this panel replies weekly per sender)\n";
    }
}
