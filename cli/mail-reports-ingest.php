#!/usr/bin/env php
<?php
// mail-reports-ingest.php — parse DMARC (RFC 7489) and TLS-RPT (RFC 8460)
// aggregate reports from the report spool (/var/spool/karnak-reports/<domain>,
// filled by Exim's virtual_reports router) into the panel DB. Run as root by
// scripts/mail-reports-ingest.sh (cron, flock-guarded).
//
// Input is HOSTILE: anyone can mail a rua address. So:
//   - the tenant is the RECEIVING domain (spool dir = Exim map lookup), and a
//     report is kept only when its own policy domain is that domain or one of
//     its subdomains owned by the same account (no forging another tenant's
//     dashboard through your own rua);
//   - hard caps on message size, decompressed size, zip entries, MIME depth,
//     parts and records; XML with a DOCTYPE is refused outright (no entity
//     tricks), libxml never touches the network;
//   - every processed message is deleted, accepted or not (no retry loops).
// Reporters resend: (domain, org, report_id) is unique, duplicates are no-ops.
declare(strict_types=1);
ini_set('memory_limit', '256M');
$root = dirname(__DIR__);
// Controllers + Models too: MailAutopilot resolves zones through MailDns,
// which needs DnsController/DomainModel
spl_autoload_register(function ($c) use ($root) {
    if (!preg_match('/^[A-Za-z]+$/', $c)) return;
    foreach (['Core', 'Controllers', 'Models'] as $dir) {
        $f = "$root/app/$dir/$c.php";
        if (file_exists($f)) { require_once $f; return; }
    }
});
Config::load($root.'/config/config.php');

const SPOOL = '/var/spool/karnak-reports';
const MAX_MSG = 10 * 1024 * 1024;       // raw message
const MAX_DOC = 32 * 1024 * 1024;       // one decompressed report
const MAX_PARTS = 20;
const MAX_ZIP_ENTRIES = 3;
const MAX_RECORDS = 5000;
const KEEP_DAYS = 180;

$verbose = in_array('-v', $argv, true);
function say(string $m): void { global $verbose; if ($verbose) echo $m, "\n"; }

// ---- MIME: minimal walker (reporters send one attachment in multipart/mixed,
// or the compressed report as the whole body) -------------------------------
function mimeParts(string $raw, int $depth, array &$out): void {
    if ($depth > 5 || count($out) >= MAX_PARTS) return;
    $sp = preg_split('/\r?\n\r?\n/', $raw, 2);
    $head = (string)($sp[0] ?? ''); $body = (string)($sp[1] ?? '');
    $head = (string)preg_replace('/\r?\n[ \t]+/', ' ', $head);
    $h = [];
    foreach (preg_split('/\r?\n/', $head) as $line) {
        if (preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/', $line, $m)) {
            $k = strtolower($m[1]);
            if (!isset($h[$k])) $h[$k] = $m[2];
        }
    }
    $ct = strtolower($h['content-type'] ?? 'text/plain');
    if (str_starts_with($ct, 'multipart/')
        && preg_match('/boundary\s*=\s*"?([^";]+)"?/i', $h['content-type'] ?? '', $bm)) {
        $b = '--'.trim($bm[1]);
        $chunks = explode($b, $body);
        array_shift($chunks); // preamble
        foreach ($chunks as $c) {
            if (str_starts_with($c, '--')) break; // closing delimiter
            mimeParts(ltrim($c, "\r\n"), $depth + 1, $out);
            if (count($out) >= MAX_PARTS) return;
        }
        return;
    }
    $cte = strtolower(trim($h['content-transfer-encoding'] ?? ''));
    if ($cte === 'base64') $data = base64_decode((string)preg_replace('/\s+/', '', $body), false);
    elseif ($cte === 'quoted-printable') $data = quoted_printable_decode($body);
    else $data = $body;
    if (!is_string($data) || $data === '') return;
    $name = '';
    foreach (['content-disposition', 'content-type'] as $k) {
        if (preg_match('/(?:file)?name\s*=\s*"?([^";]+)"?/i', $h[$k] ?? '', $nm)) { $name = strtolower(trim($nm[1])); break; }
    }
    $out[] = ['ct' => $ct, 'name' => $name, 'data' => $data];
}

// ---- decompression with hard caps ----------------------------------------
function unpackDocs(string $data): array {
    if (strncmp($data, "\x1f\x8b", 2) === 0) {
        $d = @gzdecode($data, MAX_DOC);
        return is_string($d) ? [$d] : [];
    }
    if (strncmp($data, "PK\x03\x04", 4) === 0) {
        if (!class_exists('ZipArchive')) return [];
        $tmp = tempnam(sys_get_temp_dir(), 'kpr');
        if ($tmp === false) return [];
        chmod($tmp, 0600);
        file_put_contents($tmp, $data);
        $docs = [];
        $z = new ZipArchive();
        if ($z->open($tmp) === true) {
            for ($i = 0; $i < min($z->numFiles, MAX_ZIP_ENTRIES); $i++) {
                $n = $z->getNameIndex($i);
                if ($n === false || str_ends_with($n, '/')) continue;
                // declared sizes lie in bombs: read through a capped stream
                $s = $z->getStream($n);
                if (!$s) continue;
                $buf = '';
                while (!feof($s) && strlen($buf) <= MAX_DOC) {
                    $r = fread($s, 65536);
                    if ($r === false || $r === '') break;
                    $buf .= $r;
                }
                fclose($s);
                if (strlen($buf) <= MAX_DOC && $buf !== '') $docs[] = $buf;
            }
            $z->close();
        }
        @unlink($tmp);
        return $docs;
    }
    return [$data];
}

function cut(?string $s, int $n): string { return mb_substr(trim((string)$s), 0, $n); }
function dt(int $ts): ?string { return ($ts > 946684800 && $ts < 4102444800) ? gmdate('Y-m-d H:i:s', $ts) : null; }

// a report's policy domain must be the receiving domain, or a subdomain of it
// that isn't a separate domain row owned by somebody else
function domainOk(string $pd, array $dom): bool {
    $pd = strtolower(rtrim(trim($pd), '.'));
    $d = $dom['domain'];
    if ($pd === $d) return true;
    if (!str_ends_with($pd, '.'.$d)) return false;
    $row = Database::query("SELECT user_id FROM domains WHERE domain=?", [$pd])->fetch();
    return !$row || (int)$row['user_id'] === (int)$dom['user_id'];
}

function ingestDmarc(string $doc, array $dom): string {
    if (stripos($doc, '<!DOCTYPE') !== false || stripos($doc, '<!ENTITY') !== false) return 'refused: DOCTYPE in XML';
    libxml_use_internal_errors(true);
    $x = simplexml_load_string($doc, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT);
    libxml_clear_errors();
    if ($x === false || !isset($x->report_metadata, $x->policy_published)) return 'refused: not a DMARC aggregate report';
    $pd = (string)$x->policy_published->domain;
    if (!domainOk($pd, $dom)) return "refused: report is for '".cut($pd, 80)."', not {$dom['domain']}";
    $org = cut((string)$x->report_metadata->org_name, 190);
    $rid = cut((string)$x->report_metadata->report_id, 190);
    if ($org === '' || $rid === '') return 'refused: missing org_name/report_id';
    $pdo = Database::conn();
    $pdo->beginTransaction();
    try {
        $st = Database::query("INSERT IGNORE INTO dmarc_reports(domain_id,org_name,report_id,policy_domain,policy_p,date_begin,date_end) VALUES(?,?,?,?,?,?,?)",
            [(int)$dom['id'], $org, $rid, cut(strtolower($pd), 190), cut((string)$x->policy_published->p, 16),
             dt((int)$x->report_metadata->date_range->begin), dt((int)$x->report_metadata->date_range->end)]);
        if ($st->rowCount() !== 1) { $pdo->rollBack(); return "duplicate ($org $rid)"; }
        $repId = (int)$pdo->lastInsertId();
        $n = 0;
        foreach ($x->record as $rec) {
            if (++$n > MAX_RECORDS) break;
            $ip = trim((string)$rec->row->source_ip);
            if (!filter_var($ip, FILTER_VALIDATE_IP)) continue;
            $pe = $rec->row->policy_evaluated;
            $dk = $rec->auth_results->dkim[0] ?? null;
            $sp = $rec->auth_results->spf[0] ?? null;
            Database::query("INSERT INTO dmarc_records(report_id,source_ip,msg_count,disposition,dkim_eval,spf_eval,header_from,dkim_domain,dkim_result,spf_domain,spf_result) VALUES(?,?,?,?,?,?,?,?,?,?,?)",
                [$repId, $ip, max(0, min(2147483647, (int)$rec->row->count)), cut((string)$pe->disposition, 16),
                 cut((string)$pe->dkim, 8), cut((string)$pe->spf, 8), cut(strtolower((string)$rec->identifiers->header_from), 190),
                 cut(strtolower((string)($dk->domain ?? '')), 190), cut((string)($dk->result ?? ''), 16),
                 cut(strtolower((string)($sp->domain ?? '')), 190), cut((string)($sp->result ?? ''), 16)]);
        }
        $pdo->commit();
        $GLOBALS['touched'][(int)$dom['id']] = true;
        return "dmarc ok ($org, ".min($n, MAX_RECORDS)." rows)";
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); return 'error: '.$e->getMessage(); }
}

function ingestTls(string $doc, array $dom): string {
    $j = json_decode($doc, true, 32);
    if (!is_array($j) || !isset($j['policies']) || !is_array($j['policies'])) return 'refused: not a TLS-RPT report';
    $org = cut((string)($j['organization-name'] ?? ''), 190);
    $rid = cut((string)($j['report-id'] ?? ''), 190);
    if ($org === '' || $rid === '') return 'refused: missing organization-name/report-id';
    $ok = 0; $bad = 0; $type = ''; $fails = []; $matched = false;
    foreach (array_slice($j['policies'], 0, 50) as $p) {
        if (!is_array($p) || !is_array($p['policy'] ?? null)) continue;
        if (!domainOk((string)($p['policy']['policy-domain'] ?? ''), $dom)) continue;
        $matched = true;
        if ($type === '') $type = cut((string)($p['policy']['policy-type'] ?? ''), 16);
        $ok += max(0, (int)($p['summary']['total-successful-session-count'] ?? 0));
        $bad += max(0, (int)($p['summary']['total-failure-session-count'] ?? 0));
        foreach (array_slice(is_array($p['failure-details'] ?? null) ? $p['failure-details'] : [], 0, 20) as $f) {
            if (!is_array($f) || count($fails) >= 20) continue;
            $fails[] = cut((string)($f['result-type'] ?? '?'), 40).' '.cut((string)($f['receiving-mx-hostname'] ?? ''), 120)
                .' ×'.max(0, (int)($f['failed-session-count'] ?? 0));
        }
    }
    if (!$matched) return "refused: no policy in the report is for {$dom['domain']}";
    $b = strtotime((string)($j['date-range']['start-datetime'] ?? '')) ?: 0;
    $e = strtotime((string)($j['date-range']['end-datetime'] ?? '')) ?: 0;
    try {
        $st = Database::query("INSERT IGNORE INTO tlsrpt_reports(domain_id,org_name,report_id,policy_type,date_begin,date_end,success_count,failure_count,failures) VALUES(?,?,?,?,?,?,?,?,?)",
            [(int)$dom['id'], $org, $rid, $type, dt($b), dt($e), min($ok, 2147483647), min($bad, 2147483647), implode("\n", $fails)]);
        if ($st->rowCount() !== 1) return "duplicate ($org $rid)";
        if ($bad > 0) $GLOBALS['tlsfail'][(int)$dom['id']][] = "$org: $bad failed session(s)".($fails ? ' ('.implode('; ', array_slice($fails, 0, 3)).')' : '');
        return "tls ok ($org, $ok ok / $bad failed)";
    } catch (Throwable $ex) { return 'error: '.$ex->getMessage(); }
}

function handleMessage(string $raw, array $dom): string {
    $parts = [];
    mimeParts($raw, 0, $parts);
    $res = [];
    foreach ($parts as $p) {
        $want = preg_match('/(zip|gzip|xml|json|octet-stream|tlsrpt)/', $p['ct'])
            || preg_match('/\.(zip|gz|xml|json)$/', $p['name']);
        if (!$want) continue;
        foreach (unpackDocs($p['data']) as $doc) {
            $t = ltrim($doc, "\xEF\xBB\xBF \t\r\n");
            if (str_starts_with($t, '<')) $res[] = ingestDmarc($t, $dom);
            elseif (str_starts_with($t, '{')) $res[] = ingestTls($t, $dom);
        }
    }
    return $res ? implode('; ', $res) : 'no report attachment';
}

// ---- autopilot alerts (MailAutopilot judges; owners get notified + mailed) ---
// true (and recorded) when no alert of this kind/item went out in $days
function alertDue(int $did, string $kind, string $item, int $days): bool {
    if (Database::query("SELECT 1 FROM mail_alerts WHERE domain_id=? AND kind=? AND item=? AND sent_at > UTC_TIMESTAMP() - INTERVAL ? DAY",
            [$did, $kind, $item, $days])->fetch()) return false;
    Database::query("REPLACE INTO mail_alerts(domain_id, kind, item, sent_at) VALUES(?,?,?,UTC_TIMESTAMP())", [$did, $kind, $item]);
    return true;
}
function tell(array $dom, string $title, bool $mail, string $body): void {
    say("alert {$dom['domain']}: $title".($mail ? '' : ' (panel only, mail already sent today)'));
    Helpers::notify((int)$dom['user_id'], 'mail-autopilot', $title, $body);
    if (!$mail) return;
    $to = (string)(Database::query("SELECT email FROM users WHERE id=?", [$dom['user_id']])->fetch()['email'] ?? '');
    // FILTER_VALIDATE_EMAIL also rules out CR/LF, so the To: header is safe;
    // titles are built from validated domains/IPs, stripped again regardless
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return;
    $host = trim((string)shell_exec('hostname -f 2>/dev/null')) ?: (gethostname() ?: 'localhost');
    if (!preg_match('/^[a-z0-9.-]+$/i', $host)) $host = 'localhost';
    $msg = "To: $to\nFrom: KarnakPanel <noreply@$host>\nAuto-Submitted: auto-generated\n"
        ."Subject: ".str_replace(["\r", "\n"], ' ', $title)."\nContent-Type: text/plain; charset=UTF-8\n\n"
        .$body."\n\nPanel: Email > Transport security & reports > View\n";
    $p = proc_open(['/usr/sbin/sendmail', '-t', '-i'], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (is_resource($p)) { fwrite($pipes[0], $msg); fclose($pipes[0]); proc_close($p); }
}
function alerts(): void {
    global $touched, $tlsfail;
    $ip = trim((string)shell_exec('hostname -I 2>/dev/null'));
    $ip = explode(' ', $ip)[0];
    foreach (Database::query("SELECT * FROM domains WHERE mail_reports=1")->fetchAll() as $d) {
        $did = (int)$d['id'];
        // 1. senders newly failing DMARC (only after fresh reports; once per IP a month)
        if (isset($touched[$did])) {
            $rows = Database::query("SELECT r.source_ip, SUM(r.msg_count - CASE WHEN r.dkim_eval='pass' OR r.spf_eval='pass' THEN r.msg_count ELSE 0 END) fails,
                  GROUP_CONCAT(DISTINCT p.org_name ORDER BY p.org_name SEPARATOR ', ') orgs
                FROM dmarc_records r JOIN dmarc_reports p ON p.id=r.report_id
                LEFT JOIN dmarc_ignored i ON i.domain_id=p.domain_id AND i.source_ip=r.source_ip
                WHERE p.domain_id=? AND i.source_ip IS NULL AND COALESCE(p.date_end, p.received_at) >= UTC_TIMESTAMP() - INTERVAL 7 DAY
                GROUP BY r.source_ip HAVING fails >= 10 ORDER BY fails DESC LIMIT 20", [$did])->fetchAll();
            $new = []; $ips = [];
            foreach ($rows as $r) {
                if (alertDue($did, 'src', (string)$r['source_ip'], 30)) {
                    $ips[] = (string)$r['source_ip'];
                    $new[] = "  {$r['source_ip']}: {$r['fails']} failing message(s), reported by ".mb_strimwidth((string)$r['orgs'], 0, 80, '...')
                        .($r['source_ip'] === $ip ? '  <- THIS SERVER: its DKIM/SPF is broken' : '');
                }
            }
            // reports are unauthenticated: forged ones with fresh IPs every run
            // must not become a mail storm, so the EMAIL is at most daily per
            // domain (the per-IP rows above still record every source). The
            // title names the IPs: notify() drops same-title repeats within an
            // hour, and each IP alerts once a month, so a run that finds a
            // NEW source must not reuse the previous run's title.
            if ($new) tell($d, "DMARC: {$ips[0]}".(count($ips) > 1 ? ' and '.(count($ips) - 1).' more' : '')." failing for {$d['domain']}", alertDue($did, 'srcmail', '', 1),
                "Receivers report mail claiming to be from @{$d['domain']} that fails DMARC (no aligned SPF or DKIM):\n\n".implode("\n", $new)
                ."\n\nIf a sender is a service you use (newsletter, CRM, helpdesk), add it to SPF or give it a DKIM key. If you don't recognise it, someone may be spoofing you: mark it Ignored in the panel and keep stepping your policy towards reject.");
        }
        // 2. TLS delivery failures (at most daily)
        if (!empty($tlsfail[$did]) && alertDue($did, 'tls', 'fail', 1)) {
            $enf = ($d['mta_sts_mode'] ?? '') === 'enforce';
            tell($d, ($enf ? 'URGENT: ' : '')."TLS delivery failures to {$d['domain']}", true,
                "Senders reported failed TLS sessions to your mail server:\n\n  ".implode("\n  ", array_slice($tlsfail[$did], 0, 10))."\n\n"
                .($enf ? "MTA-STS is ENFORCING, so these senders refuse to deliver. Check the certificate on your MX now (SSL page: re-issue for the domain), or step MTA-STS down to none."
                       : "MTA-STS is not enforcing, so this mail was still delivered. Fix it before enforcing (usually: re-issue SSL so mail.{$d['domain']} is on the certificate)."));
        }
        // 3. a policy step became safe (once per step; a step change re-arms it)
        $j = MailAutopilot::dmarc($d);
        if ($j['ok'] && $j['next'] !== '' && alertDue($did, 'ready', $j['next'], 3650)) {
            tell($d, "{$d['domain']} is ready for DMARC p={$j['next']}", true,
                "Reports from receivers show all of your mail passing DMARC for ".MailAutopilot::DWELL_DAYS." days at p={$j['p']}.\n\n"
                .implode("\n", array_map(fn($c) => '  ok: '.$c[1], $j['checks']))
                ."\n\nMoving to p={$j['next']} ".($j['next'] === 'reject' ? 'makes receivers refuse spoofed mail outright.' : 'sends spoofed mail to spam.')
                ." Nothing changes until you click the step in the panel.");
        }
        if (MailAutopilot::mtaSts($d)['ok'] && alertDue($did, 'ready', 'enforce', 3650)) {
            tell($d, "{$d['domain']} is ready to enforce MTA-STS", true,
                "TLS reports show every sender delivering to your mail server over verified TLS for ".MailAutopilot::STS_DAYS." days of testing.\n\nEnforcing makes senders refuse unencrypted or unverified delivery, which blocks downgrade attacks. The panel re-runs the live certificate checks when you click Enforce.");
        }
    }
}

// ---- main -------------------------------------------------------------------
$touched = []; $tlsfail = [];
if (!is_dir(SPOOL)) { say('no spool'); exit(0); }
$total = 0;
foreach (scandir(SPOOL) ?: [] as $dn) {
    if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $dn)) continue;
    $dir = SPOOL.'/'.$dn;
    if (is_link($dir) || !is_dir($dir)) continue;
    $dom = Database::query("SELECT id, user_id, domain, mail_reports FROM domains WHERE domain=?", [$dn])->fetch() ?: null;
    foreach (['new', 'cur'] as $sub) {
        // D/ is vmail-owned: a vmail-planted symlink must never steer root's
        // reads/unlinks (the parent spool is root:vmail 750, so this suffices)
        if (is_link("$dir/$sub") || realpath("$dir/$sub") !== SPOOL."/$dn/$sub") continue;
        foreach (scandir("$dir/$sub") ?: [] as $fn) {
            $f = "$dir/$sub/$fn";
            if ($fn[0] === '.' || is_link($f) || !is_file($f)) continue;
            $total++;
            if (!$dom || (int)$dom['mail_reports'] !== 1) { say("$dn/$fn: intake off — dropped"); @unlink($f); continue; }
            if (filesize($f) > MAX_MSG) { say("$dn/$fn: too large — dropped"); @unlink($f); continue; }
            $raw = (string)file_get_contents($f);
            say("$dn/$fn: ".handleMessage($raw, $dom));
            @unlink($f);
        }
    }
}
try { alerts(); } catch (Throwable $e) { say('alerts failed: '.$e->getMessage()); }
Database::query("DELETE FROM mail_alerts WHERE kind<>'ready' AND sent_at < UTC_TIMESTAMP() - INTERVAL ".KEEP_DAYS." DAY");
Database::query("DELETE FROM dmarc_reports WHERE received_at < NOW() - INTERVAL ".KEEP_DAYS." DAY");
Database::query("DELETE FROM tlsrpt_reports WHERE received_at < NOW() - INTERVAL ".KEEP_DAYS." DAY");
say("processed $total message(s)");
