<?php
declare(strict_types=1);
// Deliverability autopilot: judges when a domain's DMARC policy can step up
// (none -> quarantine -> reject) and when MTA-STS testing can move to enforce,
// from the aggregate reports receivers send (dmarc_reports / tlsrpt_reports).
//
// Guided, never unattended: this class only judges (and the report ingester
// alerts from it); the owner clicks each step (MailPolicyController). A wrong
// step-up makes receivers bin or refuse real mail, so no cron flips policy.
//
// Ground truth is what the REPORTS say receivers applied (policy_p), not the
// panel's DB row: a registrar-hosted zone still publishing the old policy
// shows "not live yet" instead of quietly passing.
//
// Times: report date ranges are stored UTC (ingester gmdate), so every window
// here compares against UTC_TIMESTAMP().
class MailAutopilot {
    public const DWELL_DAYS = 14;   // days reported at a policy before the next step
    public const WINDOW_DAYS = 14;  // evidence window for pass rates
    public const MIN_REPORTS = 3;
    public const MIN_MSGS = 10;
    public const PASS_PCT = 98.0;   // overall and per-source aligned pass rate
    public const BLOCK_FAILS = 5;   // failing messages that make one source a blocker
    public const STS_DAYS = 7;      // clean TLS-report days in testing before enforce
    public const NEXT = ['none' => 'quarantine', 'quarantine' => 'reject'];

    // ---- DMARC tag list ------------------------------------------------------
    public static function tag(string $rec, string $key): string {
        foreach (explode(';', $rec) as $t) {
            $kv = explode('=', trim($t), 2);
            if (strcasecmp(trim($kv[0]), $key) === 0) return trim($kv[1] ?? '');
        }
        return '';
    }
    // replace one tag's value, keeping every other tag exactly as published
    public static function setTag(string $rec, string $key, string $val): string {
        $out = []; $done = false;
        foreach (explode(';', $rec) as $t) {
            $t = trim($t);
            if ($t === '') continue;
            $kv = explode('=', $t, 2);
            if (strcasecmp(trim($kv[0]), $key) === 0) { if (!$done) $out[] = $key.'='.$val; $done = true; continue; }
            $out[] = $t;
        }
        // p= must directly follow v= (RFC 7489 6.3)
        if (!$done) array_splice($out, 1, 0, [$key.'='.$val]);
        return implode('; ', $out);
    }
    // the domain's OWN _dmarc row: in its zone, or _dmarc.<label> in the
    // parent zone for a subdomain (MailDns). Never the parent's record.
    public static function dmarcRow(array $d): ?array {
        $t = MailDns::target($d);
        if (!$t['zone']) return null;
        $r = Database::query("SELECT * FROM dns_records WHERE domain_id=? AND type='TXT' AND host=? AND value LIKE '%v=DMARC1%' ORDER BY id LIMIT 1", [$t['zone']['id'], MailDns::host($t, '_dmarc')])->fetch();
        return $r ?: null;
    }
    public static function ignored(int $did): array {
        return array_column(Database::query("SELECT source_ip FROM dmarc_ignored WHERE domain_id=? ORDER BY source_ip", [$did])->fetchAll(), 'source_ip');
    }
    private static function days(?string $utc): float {
        if ($utc === null || $utc === '') return 0.0;
        $t = strtotime($utc.' UTC');
        return $t ? max(0.0, (time() - $t) / 86400) : 0.0;
    }

    // ['p','next','live','ok','checks'=>[[bool,text]],'blockers'=>[ip=>[msgs,fails]]]
    public static function dmarc(array $d): array {
        $did = (int)$d['id'];
        $out = ['p' => '', 'next' => '', 'live' => '', 'ok' => false, 'checks' => [], 'blockers' => []];
        $row = self::dmarcRow($d);
        if (!$row) { $out['checks'][] = [false, 'No DMARC record of its own yet. Turn on report collection, which publishes one.']; return $out; }
        $p = strtolower(self::tag(trim((string)$row['value'], '"'), 'p'));
        $out['p'] = $p;
        $out['next'] = self::NEXT[$p] ?? '';
        if ($p === 'reject') { $out['checks'][] = [true, 'At p=reject, the strongest policy. Alerts keep watching for new failing senders.']; return $out; }
        if ($out['next'] === '') { $out['checks'][] = [false, "Unrecognised policy p=$p. Set p=none in DNS to start."]; return $out; }
        if ((int)($d['mail_reports'] ?? 0) !== 1) { $out['checks'][] = [false, 'Report collection is off. The autopilot judges from reports.']; return $out; }

        // what receivers applied most recently. Policy-clock queries only use
        // reports about the domain itself with a stated p: a same-owner
        // subdomain's own _dmarc (or a reporter omitting p) must not reset
        // the dwell or stall the live check. Pass rates below still include
        // subdomain traffic at this p (p governs subdomains without sp=).
        $dom = strtolower((string)$d['domain']);
        $live = Database::query("SELECT LOWER(policy_p) p FROM dmarc_reports WHERE domain_id=? AND policy_domain=? AND policy_p<>'' ORDER BY COALESCE(date_end, received_at) DESC, id DESC LIMIT 1", [$did, $dom])->fetch();
        $out['live'] = (string)($live['p'] ?? '');
        if ($out['live'] === '') $out['checks'][] = [false, 'No reports yet. Big receivers send one a day once they have seen mail from you.'];
        elseif ($out['live'] !== $p) $out['checks'][] = [false, "Receivers still apply p={$out['live']} (latest report), not p=$p. If this zone is hosted at your registrar, update _dmarc there."];
        else $out['checks'][] = [true, "Receivers apply p=$p"];

        // the stretch at the current policy starts after the last report that
        // showed a different one (date_end, so late stragglers don't reset it)
        $other = Database::query("SELECT MAX(COALESCE(date_end, received_at)) t FROM dmarc_reports WHERE domain_id=? AND policy_domain=? AND policy_p<>'' AND LOWER(policy_p)<>?", [$did, $dom, $p])->fetch()['t'] ?? null;
        $since = $other ?: '1970-01-01 00:00:00';
        $first = Database::query("SELECT MIN(COALESCE(date_begin, received_at)) t FROM dmarc_reports WHERE domain_id=? AND policy_domain=? AND LOWER(policy_p)=? AND COALESCE(date_begin, received_at) >= ?", [$did, $dom, $p, $since])->fetch()['t'] ?? null;
        $dwell = self::days($first);
        $out['checks'][] = [$dwell >= self::DWELL_DAYS, sprintf('Reported at p=%s for %d day(s) (needs %d)', $p, (int)floor($dwell), self::DWELL_DAYS)];

        $rows = Database::query("SELECT r.source_ip, SUM(r.msg_count) msgs,
              SUM(CASE WHEN r.dkim_eval='pass' OR r.spf_eval='pass' THEN r.msg_count ELSE 0 END) aligned
            FROM dmarc_records r JOIN dmarc_reports p ON p.id=r.report_id
            WHERE p.domain_id=? AND LOWER(p.policy_p)=? AND COALESCE(p.date_begin, p.received_at) >= ?
              AND COALESCE(p.date_end, p.received_at) >= UTC_TIMESTAMP() - INTERVAL ? DAY
            GROUP BY r.source_ip", [$did, $p, $since, self::WINDOW_DAYS])->fetchAll();
        $nrep = (int)(Database::query("SELECT COUNT(*) n FROM dmarc_reports WHERE domain_id=? AND LOWER(policy_p)=? AND COALESCE(date_begin, received_at) >= ?
              AND COALESCE(date_end, received_at) >= UTC_TIMESTAMP() - INTERVAL ? DAY", [$did, $p, $since, self::WINDOW_DAYS])->fetch()['n'] ?? 0);
        $ign = array_flip(self::ignored($did));
        $msgs = 0; $aligned = 0; $skipped = 0;
        foreach ($rows as $r) {
            if (isset($ign[$r['source_ip']])) { $skipped++; continue; }
            $m = (int)$r['msgs']; $a = (int)$r['aligned'];
            $msgs += $m; $aligned += $a;
            if ($m - $a >= self::BLOCK_FAILS && 100 * $a / max(1, $m) < self::PASS_PCT) $out['blockers'][$r['source_ip']] = [$m, $m - $a];
        }
        $out['checks'][] = [$nrep >= self::MIN_REPORTS && $msgs >= self::MIN_MSGS,
            sprintf('%d report(s), %d message(s) in the last %d days (needs %d / %d)', $nrep, $msgs, self::WINDOW_DAYS, self::MIN_REPORTS, self::MIN_MSGS)];
        $pct = $msgs > 0 ? round(100 * $aligned / $msgs, 1) : 0.0;
        $out['checks'][] = [$msgs > 0 && $pct >= self::PASS_PCT, sprintf('%s%% of your mail passed DMARC (needs %s%%)', $pct, self::PASS_PCT)
            .($skipped ? " · $skipped ignored source(s) excluded" : '')];
        arsort($out['blockers']);
        $out['checks'][] = $out['blockers']
            ? [false, count($out['blockers']).' sender(s) failing DMARC: fix SPF/DKIM for each, or ignore any that are not yours']
            : [true, 'No sender is failing DMARC'];
        $out['ok'] = !in_array(false, array_column($out['checks'], 0), true);
        return $out;
    }

    // testing -> enforce readiness from TLS reports: ['ok','checks']
    public static function mtaSts(array $d): array {
        $out = ['ok' => false, 'checks' => []];
        if (($d['mta_sts_mode'] ?? '') !== 'testing') return $out;
        // the policy id is the UTC publish time (MailPolicyController::publish)
        $id = (string)($d['mta_sts_id'] ?? '');
        $since = preg_match('/^(\d{4})(\d\d)(\d\d)(\d\d)(\d\d)(\d\d)$/', $id, $m) ? "$m[1]-$m[2]-$m[3] $m[4]:$m[5]:$m[6]" : '';
        if ($since === '') { $out['checks'][] = [false, 'Policy id unreadable. Re-publish testing.']; return $out; }
        $days = self::days($since);
        $out['checks'][] = [$days >= self::STS_DAYS, sprintf('Testing for %d day(s) (needs %d)', (int)floor($days), self::STS_DAYS)];
        $t = Database::query("SELECT COUNT(*) n, COALESCE(SUM(success_count),0) ok
            FROM tlsrpt_reports WHERE domain_id=? AND policy_type='sts' AND COALESCE(date_begin, received_at) >= ?", [$d['id'], $since])->fetch();
        $n = (int)($t['n'] ?? 0); $ok = (int)($t['ok'] ?? 0);
        // failures count over the last STS_DAYS only: a fixed one-off (cert
        // re-issued) clears itself instead of blocking until a re-publish
        $bad = (int)(Database::query("SELECT COALESCE(SUM(failure_count),0) bad FROM tlsrpt_reports WHERE domain_id=? AND policy_type='sts'
            AND COALESCE(date_begin, received_at) >= ? AND COALESCE(date_end, received_at) >= UTC_TIMESTAMP() - INTERVAL ? DAY", [$d['id'], $since, self::STS_DAYS])->fetch()['bad'] ?? 0);
        $out['checks'][] = [$n > 0 && $ok > 0, "$n TLS report(s) for this policy, $ok successful session(s)"];
        $out['checks'][] = [$bad === 0, $bad === 0 ? 'No sender failed TLS to your MX in the last '.self::STS_DAYS.' days' : "$bad failed session(s) in the last ".self::STS_DAYS.' days. Enforcing would turn these into refused mail.'];
        $out['ok'] = !in_array(false, array_column($out['checks'], 0), true);
        return $out;
    }

    // every row the report features keep for a domain (domain-delete paths)
    public static function purgeDomain(int $did): void {
        foreach (['dmarc_reports', 'tlsrpt_reports', 'dmarc_ignored', 'mail_alerts'] as $t) {
            // fixed table allowlist, never input
            Database::query("DELETE FROM $t WHERE domain_id=?", [$did]);
        }
    }
}
