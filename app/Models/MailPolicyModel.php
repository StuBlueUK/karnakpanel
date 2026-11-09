<?php
declare(strict_types=1);
/**
 * MailPolicyModel — the SQL behind MTA-STS / DMARC / TLS-RPT policy work:
 * report-intake address checks, the autopilot alerts, the ignored-source
 * list and the aggregate-report reads on the /email/reports page.
 *
 * Domain flags and DNS rows stay in DomainModel / DnsModel.
 */
class MailPolicyModel {

    /** a localpart that intake needs (mailbox/forwarder/list) is already taken */
    public static function localpartTaken(int $domainId, string $local, string $table): bool {
        // fixed allowlist, never input
        if (!in_array($table, ['email_accounts', 'email_forwards', 'email_lists'], true)) return false;
        return (bool)Database::query(
            "SELECT 1 FROM $table WHERE domain_id=? AND localpart=? LIMIT 1", [$domainId, $local]
        )->fetch();
    }
    /** drop the autopilot's "ready to step up" alerts for a domain */
    public static function clearReadyAlerts(int $domainId, array $items): void {
        $items = array_values(array_filter($items, fn($i) => in_array($i, ['quarantine', 'reject', 'enforce'], true)));
        if (!$items) return;
        $in = implode(',', array_fill(0, count($items), '?'));
        Database::query(
            "DELETE FROM mail_alerts WHERE domain_id=? AND kind='ready' AND item IN ($in)",
            array_merge([$domainId], $items)
        );
    }

    // ------------------------------------------------------- ignored sources
    public static function ignoreSource(int $domainId, string $ip): void {
        Database::query("INSERT IGNORE INTO dmarc_ignored(domain_id, source_ip) VALUES(?,?)", [$domainId, $ip]);
    }
    public static function clearIgnored(int $domainId, string $ip): void {
        Database::query("DELETE FROM dmarc_ignored WHERE domain_id=? AND source_ip=?", [$domainId, $ip]);
    }

    // ------------------------------------------------------- report viewer
    public static function totals(int $domainId, int $days): array {
        return Database::query(
            "SELECT COALESCE(SUM(r.msg_count),0) total,
              COALESCE(SUM(CASE WHEN r.dkim_eval='pass' OR r.spf_eval='pass' THEN r.msg_count ELSE 0 END),0) aligned,
              COALESCE(SUM(CASE WHEN r.disposition IN ('quarantine','reject') THEN r.msg_count ELSE 0 END),0) actioned,
              COUNT(DISTINCT p.id) reports
            FROM dmarc_reports p LEFT JOIN dmarc_records r ON r.report_id=p.id
            WHERE p.domain_id=? AND p.received_at >= NOW() - INTERVAL ? DAY",
            [$domainId, $days]
        )->fetch() ?: ['total' => 0, 'aligned' => 0, 'actioned' => 0, 'reports' => 0];
    }
    public static function sources(int $domainId, int $days): array {
        return Database::query(
            "SELECT r.source_ip, SUM(r.msg_count) msgs,
              SUM(CASE WHEN r.dkim_eval='pass' OR r.spf_eval='pass' THEN r.msg_count ELSE 0 END) aligned,
              SUM(CASE WHEN r.dkim_eval='pass' THEN r.msg_count ELSE 0 END) dkim_pass,
              SUM(CASE WHEN r.spf_eval='pass' THEN r.msg_count ELSE 0 END) spf_pass,
              GROUP_CONCAT(DISTINCT r.header_from ORDER BY r.header_from SEPARATOR ', ') froms,
              GROUP_CONCAT(DISTINCT p.org_name ORDER BY p.org_name SEPARATOR ', ') orgs
            FROM dmarc_records r JOIN dmarc_reports p ON p.id=r.report_id
            WHERE p.domain_id=? AND p.received_at >= NOW() - INTERVAL ? DAY
            GROUP BY r.source_ip ORDER BY msgs DESC LIMIT 50",
            [$domainId, $days]
        )->fetchAll();
    }
    public static function recent(int $domainId): array {
        return Database::query(
            "SELECT p.org_name, p.report_id, p.policy_p, p.date_begin, p.date_end, p.received_at,
              COALESCE(SUM(r.msg_count),0) msgs
            FROM dmarc_reports p LEFT JOIN dmarc_records r ON r.report_id=p.id
            WHERE p.domain_id=? GROUP BY p.id ORDER BY p.received_at DESC LIMIT 20",
            [$domainId]
        )->fetchAll();
    }
    public static function tlsReports(int $domainId, int $days): array {
        return Database::query(
            "SELECT org_name, policy_type, date_begin, date_end, success_count, failure_count, failures
            FROM tlsrpt_reports WHERE domain_id=? AND received_at >= NOW() - INTERVAL ? DAY ORDER BY received_at DESC LIMIT 50",
            [$domainId, $days]
        )->fetchAll();
    }
}
