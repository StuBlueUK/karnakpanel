<?php
declare(strict_types=1);
// MTA-STS + TLS-RPT publishing and DMARC/TLS aggregate report intake (Email
// page "Mail authentication" card + /email/reports viewer).
//
// DNS goes through dns_records + DnsController::apply() only (the panel
// re-renders zones from the DB), always via MailDns: a subdomain's rows live
// in its PARENT zone at prefixed names (_dmarc.shop, mta-sts.shop, ...). Web side (policy file, mta-sts.<d> host) is
// mta-sts.sh; report intake map + spool is mail-reports.sh; parsing is the
// root ingest cron (cli/mail-reports-ingest.php).
//
// Safety model: testing/none can never lose mail (a sender that can't fetch
// or validate just has no policy). Only enforce can, so enforce is gated on
// the live checks a sender performs: policy over verified HTTPS + every MX
// presenting a valid cert for its own name on STARTTLS.
class MailPolicyController extends Controller {
    public const RUA_DMARC = 'dmarc-reports';
    public const RUA_TLS = 'tls-reports';

    private function ownDomain(array $u, int $id): ?array {
        $d = Database::query("SELECT * FROM domains WHERE id=? AND user_id=?", [$id, $u['id']])->fetch();
        return $d ?: null;
    }
    private static function owner(array $d): string {
        $o = Database::query("SELECT username FROM users WHERE id=?", [$d['user_id']])->fetch()['username'] ?? '';
        return Helpers::validUsername($o) ? $o : '';
    }
    private static function back(string $err = '', string $ok = ''): void {
        if ($err !== '') Helpers::flash('err', $err);
        if ($ok !== '') Helpers::flash('ok', $ok);
        Helpers::redirect('/email');
    }
    // true when the address is a reserved report local part of this domain
    public static function reserved(int $domainId, string $local): bool {
        if (!in_array(strtolower($local), [self::RUA_DMARC, self::RUA_TLS], true)) return false;
        $r = Database::query("SELECT mail_reports FROM domains WHERE id=?", [$domainId])->fetch();
        return (int)($r['mail_reports'] ?? 0) === 1;
    }

    // ---- tag-list records (DMARC / TLS-RPT share the k=v; k=v shape) -------
    // Add or remove one mailto: in the rua= list, keeping every other tag
    // (and the owner's own p=, other rua targets) exactly as published.
    public static function ruaMerge(string $rec, string $mailto, bool $add): string {
        $tags = [];
        foreach (explode(';', $rec) as $t) {
            $t = trim($t);
            if ($t === '') continue;
            $kv = explode('=', $t, 2);
            $tags[] = [trim($kv[0]), trim($kv[1] ?? '')];
        }
        $found = false;
        foreach ($tags as $i => [$k, $v]) {
            if (strtolower($k) !== 'rua') continue;
            $found = true;
            $list = array_values(array_filter(array_map('trim', explode(',', $v)), fn($x) => $x !== ''));
            $list = array_values(array_filter($list, fn($x) => strcasecmp($x, $mailto) !== 0));
            if ($add) $list[] = $mailto;
            if ($list) $tags[$i][1] = implode(',', $list); else unset($tags[$i]);
        }
        if ($add && !$found) $tags[] = ['rua', $mailto];
        return implode('; ', array_map(fn($kv) => $kv[0].'='.$kv[1], $tags));
    }
    // $host is the domain-relative name (_dmarc, _mta-sts, ...); MailDns maps
    // it into the zone that really holds it
    private static function txtRow(array $t, string $host, string $marker): ?array {
        if (!$t['zone']) return null;
        $r = Database::query("SELECT * FROM dns_records WHERE domain_id=? AND type='TXT' AND host=? AND value LIKE ? ORDER BY id LIMIT 1",
            [$t['zone']['id'], MailDns::host($t, $host), '%'.$marker.'%'])->fetch();
        return $r ?: null;
    }
    // set (insert/replace) or clear the single marker-tagged TXT at $host
    private static function setTxt(array $t, string $host, string $marker, ?string $value): void {
        if (!$t['zone']) return;
        $row = self::txtRow($t, $host, $marker);
        if ($value === null) { if ($row) Database::query("DELETE FROM dns_records WHERE id=?", [$row['id']]); return; }
        if ($row) Database::query("UPDATE dns_records SET value=? WHERE id=?", [$value, $row['id']]);
        else Database::query("INSERT INTO dns_records(domain_id,type,host,value,priority,ttl) VALUES(?,'TXT',?,?,10,3600)", [$t['zone']['id'], MailDns::host($t, $host), $value]);
    }
    private static function ruaTxt(array $d, string $host, string $marker, string $local, bool $add, string $fresh): void {
        $mailto = 'mailto:'.$local.'@'.$d['domain'];
        $t = MailDns::target($d);
        $row = self::txtRow($t, $host, $marker);
        if ($row) {
            $v = self::ruaMerge(trim((string)$row['value'], '"'), $mailto, $add);
            // a TLS-RPT record without rua is invalid; ours alone -> drop it
            if (!$add && $marker === 'v=TLSRPTv1' && stripos($v, 'rua=') === false) $v = '';
            if ($v === '') Database::query("DELETE FROM dns_records WHERE id=?", [$row['id']]);
            else Database::query("UPDATE dns_records SET value=? WHERE id=?", [$v, $row['id']]);
        } elseif ($add) {
            self::setTxt($t, $host, $marker, $fresh);
        }
    }
    private static function apply(array $d): array {
        return MailDns::apply(MailDns::target($d));
    }
    // a subdomain whose parent zone isn't on this panel has nowhere to write
    private static function needZone(array $d): void {
        $t = MailDns::target($d);
        if (!$t['zone']) self::back("{$d['domain']}'s records live in the {$t['origin']} zone, which isn't hosted on this panel — publish them where {$t['origin']}'s DNS is managed.");
    }
    private static function serverIp(): string {
        $ip = trim(ServiceManager::run('server-ip.sh', [])['out'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $ip : '';
    }
    // every MX the domain publishes, as FQDNs (relative labels get the origin)
    private static function mxHosts(array $d): array {
        $out = [];
        $t = MailDns::target($d);
        if (!$t['zone']) return [];
        foreach (Database::query("SELECT value FROM dns_records WHERE domain_id=? AND type='MX' AND host=? ORDER BY priority", [$t['zone']['id'], MailDns::host($t, '@')])->fetchAll() as $r) {
            // same rule apply() renders with: a dotted value is a FQDN, a bare
            // label is relative to the ZONE origin (the parent, for a subdomain)
            $h = strtolower(rtrim(trim((string)$r['value']), '.'));
            if ($h === '' || $h === '@') continue;
            if (!str_contains($h, '.')) $h .= '.'.$t['origin'];
            if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $h)) $out[] = $h;
        }
        return array_values(array_unique($out));
    }

    // ---- report intake ------------------------------------------------------
    private static function reportsOn(array $d): string {
        foreach ([self::RUA_DMARC, self::RUA_TLS] as $lp) {
            foreach (['email_accounts', 'email_forwards', 'email_lists'] as $t) {
                // table names are a fixed allowlist above, never input
                $hit = Database::query("SELECT 1 FROM $t WHERE domain_id=? AND localpart=? LIMIT 1", [$d['id'], $lp])->fetch();
                if ($hit) return "$lp@{$d['domain']} already exists (".str_replace('email_', '', $t).") — remove it first, report intake needs that address";
            }
        }
        $p = ServiceManager::run('exim-reports-patch.sh', []);
        if (($p['code'] ?? 1) !== 0) return 'Mail router setup failed: '.trim($p['out'] ?? '');
        $r = ServiceManager::run('mail-reports.sh', [$d['domain'], 'on']);
        if (($r['code'] ?? 1) !== 0) return 'Report intake failed: '.trim($r['out'] ?? '');
        Database::query("UPDATE domains SET mail_reports=1 WHERE id=?", [$d['id']]);
        // a subdomain's own record replaces what it inherits from the parent:
        // start at the parent's policy, never weaker (p=none under a parent
        // at reject would un-protect it)
        $p = MailDns::inheritedP(MailDns::target($d)) ?: 'none';
        self::ruaTxt($d, '_dmarc', 'v=DMARC1', self::RUA_DMARC, true,
            "v=DMARC1; p=$p; rua=mailto:".self::RUA_DMARC.'@'.$d['domain']);
        return '';
    }

    public function reportsToggle(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) self::back('Bad CSRF');
        $u = Auth::user();
        $d = $this->ownDomain($u, (int)($_POST['domain_id'] ?? 0));
        if (!$d) self::back('Domain not found');
        self::needZone($d);
        if ((int)$d['mail_reports'] === 1) {
            if (in_array($d['mta_sts_mode'], ['testing', 'enforce'], true)) {
                self::back("MTA-STS is {$d['mta_sts_mode']} for {$d['domain']} — its TLS reports arrive here. Switch MTA-STS to none/remove first.");
            }
            ServiceManager::run('mail-reports.sh', [$d['domain'], 'off']);
            Database::query("UPDATE domains SET mail_reports=0 WHERE id=?", [$d['id']]);
            self::ruaTxt($d, '_dmarc', 'v=DMARC1', self::RUA_DMARC, false, '');
            self::ruaTxt($d, '_smtp._tls', 'v=TLSRPTv1', self::RUA_TLS, false, '');
            $r = self::apply($d);
            // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string -- not SQL: flash text, no query sink
            self::back(($r['code'] ?? 1) !== 0 ? 'Intake off, but DNS update failed: '.trim($r['out'] ?? '') : '',
                "Report intake off for {$d['domain']} (rua removed from DMARC; stored reports kept).");
        }
        if (($d['mail_handling'] ?? 'local') === 'remote') self::back("{$d['domain']} mail is handled remotely — reports would go to your provider, not here.");
        if (($e = self::reportsOn($d)) !== '') self::back($e);
        $r = self::apply($d);
        // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string -- not SQL: flash text, no query sink
        if (($r['code'] ?? 1) !== 0) self::back('Intake on, but DNS update failed: '.trim($r['out'] ?? ''));
        self::back('', "Collecting DMARC reports for {$d['domain']} at ".self::RUA_DMARC."@{$d['domain']}. Receivers send daily — first reports usually arrive within 24–48h.".MailDns::ensureInbound($d));
    }

    // ---- MTA-STS ------------------------------------------------------------
    private static function publish(array $d, string $owner, string $mode, array $mx, string $ip): string {
        $id = gmdate('YmdHis');
        $w = ServiceManager::run('mta-sts.sh', [$d['domain'], $owner, 'write', $mode, implode(',', $mx)]);
        if (($w['code'] ?? 1) !== 0) return 'Policy write failed: '.trim($w['out'] ?? '');
        $did = (int)$d['id'];
        $t = MailDns::target($d);
        if (!$t['zone']) return "the {$t['origin']} zone isn't hosted on this panel";
        // policy host A record (ours only: an existing record there is the
        // owner's — refused upstream, never clobbered)
        $sts = MailDns::host($t, 'mta-sts');
        if ($ip !== '' && !Database::query("SELECT 1 FROM dns_records WHERE domain_id=? AND host=? LIMIT 1", [$t['zone']['id'], $sts])->fetch()) {
            Database::query("INSERT INTO dns_records(domain_id,type,host,value,priority,ttl) VALUES(?,'A',?,?,10,3600)", [$t['zone']['id'], $sts, $ip]);
        }
        self::setTxt($t, '_mta-sts', 'v=STSv1', "v=STSv1; id=$id");
        self::ruaTxt($d, '_smtp._tls', 'v=TLSRPTv1', self::RUA_TLS, true, 'v=TLSRPTv1; rua=mailto:'.self::RUA_TLS.'@'.$d['domain']);
        Database::query("UPDATE domains SET mta_sts_mode=?, mta_sts_id=? WHERE id=?", [$mode, $id, $did]);
        // new policy id = new evidence window: re-arm the "ready to enforce" alert
        Database::query("DELETE FROM mail_alerts WHERE domain_id=? AND kind='ready' AND item='enforce'", [$did]);
        // leaving enforce: its 7-day max_age copy may sit in sender caches
        if (($d['mta_sts_mode'] ?? '') === 'enforce' && $mode !== 'enforce') {
            Database::query("UPDATE domains SET mta_sts_cached_until=UTC_TIMESTAMP() + INTERVAL 7 DAY WHERE id=?", [$did]);
        }
        $r = self::apply($d);
        if (($r['code'] ?? 1) !== 0) return 'Policy written, but DNS update failed: '.trim($r['out'] ?? '');
        return '';
    }
    // the checks a sending MTA performs; [allOk, lines]
    private static function gate(array $d, string $owner, array $mx): array {
        $lines = []; $ok = true;
        $p = ServiceManager::run('mta-sts.sh', [$d['domain'], $owner, 'check-policy']);
        $ok = $ok && (($p['code'] ?? 1) === 0);
        $lines[] = str_replace("\n", ' · ', trim($p['out'] ?? ''));
        foreach ($mx as $m) {
            $r = ServiceManager::run('mta-sts.sh', [$d['domain'], $owner, 'check-mx', $m]);
            $ok = $ok && (($r['code'] ?? 1) === 0);
            $lines[] = trim($r['out'] ?? '');
        }
        return [$ok, $lines];
    }
    // remote-handling flip / domain hand-off: tell cached senders to stand
    // down (mode none + new id) instead of vanishing — an enforce policy
    // cached for a week would otherwise reject the new provider's MX.
    public static function windDown(array $d): string {
        if (!in_array($d['mta_sts_mode'] ?? 'off', ['testing', 'enforce'], true)) return '';
        $owner = self::owner($d);
        if ($owner === '') return 'MTA-STS wind-down skipped (owner invalid)';
        $e = self::publish($d, $owner, 'none', [], '');
        return $e !== '' ? $e : 'MTA-STS switched to none (cached policies expire safely).';
    }

    // mail moving to an external provider: our rua mailboxes and policy host
    // stop being reachable. Wind MTA-STS down and pull our rua tags so
    // receivers don't mail reports into the provider's void.
    public static function goingRemote(array $d): string {
        $notes = [];
        if (($w = self::windDown($d)) !== '') $notes[] = $w;
        if ((int)($d['mail_reports'] ?? 0) === 1) {
            ServiceManager::run('mail-reports.sh', [$d['domain'], 'off']);
            Database::query("UPDATE domains SET mail_reports=0 WHERE id=?", [$d['id']]);
            self::ruaTxt($d, '_dmarc', 'v=DMARC1', self::RUA_DMARC, false, '');
            self::ruaTxt($d, '_smtp._tls', 'v=TLSRPTv1', self::RUA_TLS, false, '');
            $r = self::apply($d);
            $notes[] = ($r['code'] ?? 1) === 0 ? 'DMARC/TLS report intake turned off.' : 'Report intake off, DNS update failed: '.trim($r['out'] ?? '');
        }
        return implode(' ', $notes);
    }

    public function mtaSts(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) self::back('Bad CSRF');
        $u = Auth::user();
        $d = $this->ownDomain($u, (int)($_POST['domain_id'] ?? 0));
        if (!$d) self::back('Domain not found');
        self::needZone($d);
        $owner = self::owner($d);
        if ($owner === '') self::back('Owner account invalid');
        $act = (string)($_POST['mode'] ?? '');
        $cur = (string)($d['mta_sts_mode'] ?? 'off');
        $mx = self::mxHosts($d);
        if (in_array($act, ['testing', 'enforce', 'check'], true)) {
            if (($d['mail_handling'] ?? 'local') === 'remote') self::back("{$d['domain']} mail is handled remotely — MTA-STS must be published by your provider's setup.");
            if (!$mx) self::back("{$d['domain']} has no MX records in its zone — add one first.");
        }
        switch ($act) {
        case 'testing':
            if ($cur === 'enforce') self::back('Already enforcing — use none to step down.');
            $t = MailDns::target($d);
            $own = Database::query("SELECT type, value FROM dns_records WHERE domain_id=? AND host=?", [$t['zone']['id'], MailDns::host($t, 'mta-sts')])->fetchAll();
            $ip = self::serverIp();
            foreach ($own as $o) {
                if ($o['type'] !== 'A' || $o['value'] !== $ip) self::back("mta-sts.{$d['domain']} already has a {$o['type']} record ({$o['value']}) — it must point at this server for the policy host. Remove it in DNS first.");
            }
            if ($ip === '') self::back('Could not determine the server IP');
            if ((int)$d['mail_reports'] !== 1 && ($e = self::reportsOn($d)) !== '') self::back($e);
            $d = $this->ownDomain($u, (int)$d['id']) ?? $d;
            if (($e = self::publish($d, $owner, 'testing', $mx, $ip)) !== '') self::back($e);
            self::back('', "MTA-STS testing published for {$d['domain']} (mx: ".implode(', ', $mx)."). Next: re-issue SSL on the SSL page so mta-sts.{$d['domain']} is covered, then Check. Testing never blocks mail; TLS reports arrive at ".self::RUA_TLS."@{$d['domain']}.");
        case 'check':
            if ($cur === 'off') self::back('Publish testing first.');
            [$ok, $lines] = self::gate($d, $owner, $mx);
            self::back($ok ? '' : 'MTA-STS check: '.implode(' · ', $lines), $ok ? 'MTA-STS check passed: '.implode(' · ', $lines) : '');
        case 'enforce':
            if (!in_array($cur, ['testing', 'enforce'], true)) self::back('Run testing first (and let TLS reports confirm senders succeed) before enforcing.');
            [$ok, $lines] = self::gate($d, $owner, $mx);
            if (!$ok) self::back('Refused — enforce would make senders reject delivery: '.implode(' · ', $lines));
            if (($e = self::publish($d, $owner, 'enforce', $mx, self::serverIp())) !== '') self::back($e);
            self::back('', "MTA-STS enforcing for {$d['domain']}. Senders now refuse unencrypted or unverified delivery to: ".implode(', ', $mx).'.');
        case 'none':
            if (!in_array($cur, ['testing', 'enforce'], true)) self::back('Nothing to step down.');
            if (($e = self::publish($d, $owner, 'none', [], '')) !== '') self::back($e);
            self::back('', "MTA-STS set to none for {$d['domain']}. Keep it published until cached policies expire (".($cur === 'enforce' ? '7 days' : '1 day').'), then remove.');
        case 'remove':
            if ($cur === 'enforce') self::back('Step down to none first — removing an enforce policy strands senders on the cached copy.');
            if ($cur === 'off') self::back('Not published.');
            // a policy that ever enforced must outlive its 7-day cached copy:
            // without a TXT senders keep (and enforce) whatever they cached
            $wait = Database::query("SELECT mta_sts_cached_until FROM domains WHERE id=? AND mta_sts_cached_until > UTC_TIMESTAMP()", [$d['id']])->fetch();
            if ($wait) self::back("Senders may still cache the enforce policy until {$wait['mta_sts_cached_until']} UTC — keep the none policy published until then.");
            $r = ServiceManager::run('mta-sts.sh', [$d['domain'], $owner, 'remove']);
            if (($r['code'] ?? 1) !== 0) self::back('Remove failed: '.trim($r['out'] ?? ''));
            // the mta-sts A record + host alias stay (cert renewal; see mta-sts.sh)
            self::setTxt(MailDns::target($d), '_mta-sts', 'v=STSv1', null);
            Database::query("UPDATE domains SET mta_sts_mode='off', mta_sts_id='', mta_sts_cached_until=NULL WHERE id=?", [$d['id']]);
            $a = self::apply($d);
            // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string -- not SQL: flash text, no query sink
            self::back(($a['code'] ?? 1) !== 0 ? 'Removed, but DNS update failed: '.trim($a['out'] ?? '') : '',
                "MTA-STS removed for {$d['domain']} (TLS reporting stays on while report intake is on).");
        default:
            self::back('Bad mode');
        }
    }

    // ---- deliverability autopilot (MailAutopilot judges, the owner clicks) ----
    private static function toReports(int $did, string $err = '', string $ok = ''): void {
        if ($err !== '') Helpers::flash('err', $err);
        if ($ok !== '') Helpers::flash('ok', $ok);
        Helpers::redirect('/email/reports?domain_id='.$did);
    }
    // step the DMARC p= tag: up only when the autopilot says ready, down any time
    public function dmarcStep(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) self::back('Bad CSRF');
        $u = Auth::user();
        $d = $this->ownDomain($u, (int)($_POST['domain_id'] ?? 0));
        if (!$d) self::back('Domain not found');
        self::needZone($d);
        $did = (int)$d['id'];
        $to = (string)($_POST['to'] ?? '');
        if (!in_array($to, ['none', 'quarantine', 'reject'], true)) self::toReports($did, 'Bad policy');
        $row = MailAutopilot::dmarcRow($d);
        if (!$row) self::toReports($did, 'No DMARC record. Turn on report collection first.');
        $rec = trim((string)$row['value'], '"');
        $cur = strtolower(MailAutopilot::tag($rec, 'p'));
        $rank = ['none' => 0, 'quarantine' => 1, 'reject' => 2];
        if (!isset($rank[$cur])) self::toReports($did, "Current policy p=$cur is not one the autopilot manages. Fix it in the DNS editor.");
        if ($to === $cur) self::toReports($did, "Already at p=$cur.");
        if ($rank[$to] > $rank[$cur]) {
            // one step at a time, and only on evidence
            $ap = MailAutopilot::dmarc($d);
            if ($to !== $ap['next']) self::toReports($did, "Step up one level at a time (next is p={$ap['next']}).");
            if (!$ap['ok']) self::toReports($did, 'Not ready: '.implode(' · ', array_map(fn($c) => $c[1], array_filter($ap['checks'], fn($c) => !$c[0]))));
        }
        Database::query("UPDATE dns_records SET value=? WHERE id=?", [MailAutopilot::setTag($rec, 'p', $to), $row['id']]);
        Database::query("DELETE FROM mail_alerts WHERE domain_id=? AND kind='ready' AND item IN ('quarantine','reject')", [$did]);
        $r = self::apply($d);
        // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string -- not SQL: flash text, no query sink
        if (($r['code'] ?? 1) !== 0) self::toReports($did, 'Policy saved, but DNS update failed: '.trim($r['out'] ?? ''));
        $msg = [
            'quarantine' => 'Receivers now send failing mail to spam instead of the inbox.',
            'reject' => 'Receivers now refuse mail that fails DMARC. Spoofing your domain stops working.',
            'none' => 'Receivers only report now, nothing is filtered.',
        ][$to];
        self::toReports($did, '', "DMARC for {$d['domain']} is now p=$to. $msg Reports reflect the change within a day or two; the next step unlocks after ".MailAutopilot::DWELL_DAYS.' clean days.');
    }
    // mark a failing source as "not mine" (spoofer): excluded from step-up
    // checks and alerts. The server's own IP can never be ignored.
    public function dmarcSource(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) self::back('Bad CSRF');
        $u = Auth::user();
        $d = $this->ownDomain($u, (int)($_POST['domain_id'] ?? 0));
        if (!$d) self::back('Domain not found');
        $did = (int)$d['id'];
        $ip = trim((string)($_POST['ip'] ?? ''));
        if (!filter_var($ip, FILTER_VALIDATE_IP)) self::toReports($did, 'Bad IP');
        if (($_POST['ignore'] ?? '') === '1') {
            if ($ip === self::serverIp()) self::toReports($did, 'That is this server. Its failures are real and need fixing (DKIM/SPF), so it cannot be ignored.');
            Database::query("INSERT IGNORE INTO dmarc_ignored(domain_id, source_ip) VALUES(?,?)", [$did, $ip]);
            self::toReports($did, '', "$ip is now ignored: it no longer blocks policy steps or sends alerts. Its mail is still filtered by your policy, which is what you want for a spoofer.");
        }
        Database::query("DELETE FROM dmarc_ignored WHERE domain_id=? AND source_ip=?", [$did, $ip]);
        self::toReports($did, '', "$ip counts again.");
    }

    // ---- viewer -------------------------------------------------------------
    public function reports(): void {
        Auth::requireLogin();
        $u = Auth::user();
        $d = $this->ownDomain($u, (int)($_GET['domain_id'] ?? 0));
        if (!$d) { Helpers::flash('err', 'Domain not found'); Helpers::redirect('/email'); }
        $days = (int)($_GET['days'] ?? 30);
        if (!in_array($days, [7, 30, 90, 180], true)) $days = 30;
        $did = (int)$d['id'];
        $tot = Database::query("SELECT COALESCE(SUM(r.msg_count),0) total,
              COALESCE(SUM(CASE WHEN r.dkim_eval='pass' OR r.spf_eval='pass' THEN r.msg_count ELSE 0 END),0) aligned,
              COALESCE(SUM(CASE WHEN r.disposition IN ('quarantine','reject') THEN r.msg_count ELSE 0 END),0) actioned,
              COUNT(DISTINCT p.id) reports
            FROM dmarc_reports p LEFT JOIN dmarc_records r ON r.report_id=p.id
            WHERE p.domain_id=? AND p.received_at >= NOW() - INTERVAL ? DAY", [$did, $days])->fetch();
        $sources = Database::query("SELECT r.source_ip, SUM(r.msg_count) msgs,
              SUM(CASE WHEN r.dkim_eval='pass' OR r.spf_eval='pass' THEN r.msg_count ELSE 0 END) aligned,
              SUM(CASE WHEN r.dkim_eval='pass' THEN r.msg_count ELSE 0 END) dkim_pass,
              SUM(CASE WHEN r.spf_eval='pass' THEN r.msg_count ELSE 0 END) spf_pass,
              GROUP_CONCAT(DISTINCT r.header_from ORDER BY r.header_from SEPARATOR ', ') froms,
              GROUP_CONCAT(DISTINCT p.org_name ORDER BY p.org_name SEPARATOR ', ') orgs
            FROM dmarc_records r JOIN dmarc_reports p ON p.id=r.report_id
            WHERE p.domain_id=? AND p.received_at >= NOW() - INTERVAL ? DAY
            GROUP BY r.source_ip ORDER BY msgs DESC LIMIT 50", [$did, $days])->fetchAll();
        $recent = Database::query("SELECT p.org_name, p.report_id, p.policy_p, p.date_begin, p.date_end, p.received_at,
              COALESCE(SUM(r.msg_count),0) msgs
            FROM dmarc_reports p LEFT JOIN dmarc_records r ON r.report_id=p.id
            WHERE p.domain_id=? GROUP BY p.id ORDER BY p.received_at DESC LIMIT 20", [$did])->fetchAll();
        $tls = Database::query("SELECT org_name, policy_type, date_begin, date_end, success_count, failure_count, failures
            FROM tlsrpt_reports WHERE domain_id=? AND received_at >= NOW() - INTERVAL ? DAY ORDER BY received_at DESC LIMIT 50", [$did, $days])->fetchAll();
        $serverIp = self::serverIp();
        $dom = $d;
        $ap = MailAutopilot::dmarc($d);
        $sts = MailAutopilot::mtaSts($d);
        $ignored = MailAutopilot::ignored($did);
        $this->view('email_reports', compact('dom', 'days', 'tot', 'sources', 'recent', 'tls', 'serverIp', 'ap', 'sts', 'ignored'));
    }
}
