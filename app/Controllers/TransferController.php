<?php
declare(strict_types=1);

// Account Transfer (Migrate → KarnakPanel server): pairing, account picker,
// start, live status. Admin only; every POST is CSRF-checked; every input is
// regex-checked here AND again by the scripts. The private key never touches
// PHP: transfer-pair.sh makes and keeps it under /root.
class TransferController extends Controller {
    private const ID_RE = '/^[0-9a-f]{16}$/';
    private const CODE_RE = '/^KT1-(TARGET|SOURCE)-[A-Za-z0-9+\/]{20,1200}\.[0-9a-f]{8}$/';
    private const USER_RE = '/^[a-z][a-z0-9_]{2,31}$/';

    // JSON out with the house HEX flags: details carry remote-server text
    private function out(array $d, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag, php.lang.security.injection.echoed-request.echoed-request -- JSON_HEX_* keep every HTML-significant byte inert; JSON response, no HTML context
        echo json_encode($d, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
        exit;
    }
    private function post(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) $this->out(['error' => 'Your session expired: reload the page'], 403);
        set_time_limit(150);
    }
    // the scripts' "refused: …" lines are written for people: pass them on
    private function fail(array $r): void {
        $o = trim((string)$r['out']);
        if (preg_match('/^refused: (.+)$/m', $o, $m)) $msg = $m[1];
        elseif ((int)$r['code'] === 124) $msg = 'timed out: the other server did not answer in time';
        else $msg = 'failed: '.substr($o, -300);
        $this->out(['error' => $msg], 400);
    }
    // "KEY value" lines → array
    private static function kv(string $out): array {
        $k = [];
        foreach (explode("\n", $out) as $l) if (preg_match('/^([A-Z_]+) (.+)$/', trim($l), $m)) $k[$m[1]] = $m[2];
        return $k;
    }
    // every pairing on this server, both roles (tab lines from transfer-pair.sh list)
    public static function pairings(): array {
        $r = ServiceManager::run('transfer-pair.sh', ['list'], 20);
        $p = [];
        if ((int)$r['code'] !== 0) return $p;
        foreach (explode("\n", (string)$r['out']) as $l) {
            $f = explode("\t", trim($l));
            if (count($f) < 7 || !in_array($f[0], ['target', 'source'], true) || !preg_match(self::ID_RE, $f[1])) continue;
            $p[] = ['role' => $f[0], 'id' => $f[1], 'state' => $f[2], 'ip' => $f[3],
                    'exp' => self::when($f[4]), 'words' => $f[5], 'created' => self::when($f[6])];
        }
        return $p;
    }
    private static function when(string $t): string {   // 202611010651 (UTC) → 2026-11-01 06:51 UTC
        return preg_match('/^(\d{4})(\d\d)(\d\d)(\d\d)(\d\d)$/', $t, $m) ? "$m[1]-$m[2]-$m[3] $m[4]:$m[5] UTC" : '';
    }

    // TARGET (new server), step 1: a one-time key pair here → a code to paste on the old server
    public function pairNew(): void {
        $this->post();
        $h = (int)($_POST['hours'] ?? 24);
        if ($h < 1 || $h > 72) $this->out(['error' => 'Choose 1 to 72 hours'], 400);
        $r = ServiceManager::run('transfer-pair.sh', ['target-new', (string)$h], 30);
        if ((int)$r['code'] !== 0) $this->fail($r);
        $k = self::kv((string)$r['out']);
        if (!preg_match(self::ID_RE, $k['ID'] ?? '') || !preg_match(self::CODE_RE, $k['CODE'] ?? '')) $this->out(['error' => 'odd answer from transfer-pair.sh'], 500);
        $this->out(['id' => $k['ID'], 'code' => $k['CODE'], 'exp' => self::when($k['EXPIRES'] ?? '')]);
    }
    // SOURCE (old server): accept the new server's code → a code to paste back
    public function allow(): void {
        $this->post();
        $code = preg_replace('/\s+/', '', (string)($_POST['code'] ?? ''));
        if (!preg_match(self::CODE_RE, $code)) $this->out(['error' => 'That is not a transfer code: paste the whole KT1-TARGET-… code from the new server'], 400);
        if (!str_starts_with($code, 'KT1-TARGET-')) $this->out(['error' => 'That is a SOURCE code: it goes in the new server, not here'], 400);
        $r = ServiceManager::run('transfer-pair.sh', ['source-allow', $code], 60);
        if ((int)$r['code'] !== 0) $this->fail($r);
        $k = self::kv((string)$r['out']);
        if (!preg_match(self::CODE_RE, $k['CODE'] ?? '')) $this->out(['error' => 'odd answer from transfer-pair.sh'], 500);
        $this->out(['id' => $k['ID'] ?? '', 'code' => $k['CODE'], 'words' => $k['WORDS'] ?? '',
                    'target' => $k['TARGET'] ?? '', 'exp' => self::when($k['EXPIRES'] ?? '')]);
    }
    // TARGET, step 2: the old server's code → pin its host key, handshake
    public function link(): void {
        $this->post();
        $id = (string)($_POST['id'] ?? '');
        $code = preg_replace('/\s+/', '', (string)($_POST['code'] ?? ''));
        if (!preg_match(self::ID_RE, $id)) $this->out(['error' => 'bad pairing'], 400);
        if (!preg_match(self::CODE_RE, $code)) $this->out(['error' => 'That is not a transfer code: paste the whole KT1-SOURCE-… code from the old server'], 400);
        if (!str_starts_with($code, 'KT1-SOURCE-')) $this->out(['error' => 'That is this server’s own TARGET code: paste the code the OLD server gave back'], 400);
        $r = ServiceManager::run('transfer-pair.sh', ['target-link', $id, $code], 90);
        if ((int)$r['code'] !== 0) $this->fail($r);
        $k = self::kv((string)$r['out']);
        $v = explode(' ', $k['VERSION'] ?? '');
        $this->out(['id' => $id, 'words' => $k['WORDS'] ?? '', 'source' => $k['SOURCE'] ?? '', 'seen_as' => $k['SEEN_AS'] ?? '',
                    'src_ver' => $v[0] ?? '', 'ver' => $v[1] ?? '', 'warn' => $k['WARN'] ?? '']);
    }
    // either side: end a pairing (the target also closes the source's side)
    public function revoke(): void {
        $this->post();
        $id = (string)($_POST['id'] ?? '');
        if (!preg_match(self::ID_RE, $id)) $this->out(['error' => 'bad pairing'], 400);
        $busy = Transfer::busyForPairing($id);
        if ($busy) $this->out(['error' => 'A transfer is still running over this pairing: wait for it to finish'], 409);
        $cut = Transfer::recentCuts();
        if ($cut) $this->out(['error' => 'A cutover is running: wait for it to finish'], 409);
        $r = ServiceManager::run('transfer-pair.sh', ['revoke', $id], 60);
        if ((int)$r['code'] !== 0) $this->fail($r);
        $this->out(['ok' => true]);
    }
    // TARGET: the old server's movable accounts, with clash hints from here
    private function sourceAccounts(string $pairing): array {
        $r = ServiceManager::run('transfer-pull.sh', ['accounts', $pairing], 90);
        if ((int)$r['code'] !== 0) $this->fail($r);
        $list = json_decode(trim((string)$r['out']), true);
        if (!is_array($list)) $this->out(['error' => 'odd answer from the old server'], 502);
        return array_values(array_filter($list, fn($a) => is_array($a) && preg_match(self::USER_RE, (string)($a['username'] ?? ''))));
    }
    public function accounts(): void {
        Auth::requireRole(['admin']);
        set_time_limit(150);
        $id = (string)($_GET['pairing'] ?? '');
        if (!preg_match(self::ID_RE, $id)) $this->out(['error' => 'bad pairing'], 400);
        $list = $this->sourceAccounts($id);
        $here = array_flip(Transfer::allUsernames());
        $doms = array_flip(Transfer::allDomains());
        foreach ($list as &$a) {
            $a['clash'] = [];
            if (isset($here[$a['username']])) $a['clash'][] = 'user '.$a['username'].' exists here';
            foreach ((array)($a['domains'] ?? []) as $d) if (isset($doms[$d])) $a['clash'][] = "$d exists here";
        }
        unset($a);
        $this->out(['accounts' => $list]);
    }
    public function start(): void {
        $this->post();
        $id = (string)($_POST['pairing'] ?? '');
        if (!preg_match(self::ID_RE, $id)) $this->out(['error' => 'bad pairing'], 400);
        $want = array_values(array_unique(array_map('strval', (array)($_POST['users'] ?? []))));
        if (!$want) $this->out(['error' => 'Tick at least one account'], 400);
        if (count($want) > 200) $this->out(['error' => 'At most 200 accounts per run'], 400);
        foreach ($want as $u) if (!preg_match(self::USER_RE, $u)) $this->out(['error' => 'bad account name'], 400);
        // only what the old server actually offers right now
        $offer = array_column($this->sourceAccounts($id), 'username');
        $missing = array_diff($want, $offer);
        if ($missing) $this->out(['error' => 'Not on the old server (any more): '.implode(', ', $missing)], 400);
        $ip = '';
        foreach (self::pairings() as $p) if ($p['role'] === 'target' && $p['id'] === $id) $ip = $p['ip'];
        $me = Auth::user();
        $pdo = Database::conn();
        $pdo->beginTransaction();
        $job = Transfer::createJob($id, $ip, (int)($me['id'] ?? 0) ?: null);
        foreach ($want as $u) Transfer::addItem($job, $u);
        $pdo->commit();
        $r = ServiceManager::run('transfer-pull.sh', ['start', (string)$job], 30);
        if ((int)$r['code'] !== 0) {
            Transfer::failJob($job);
            $this->fail($r);
        }
        $this->out(['job' => $job]);
    }
    // TARGET: run the failed accounts of a finished job again (a new job:
    // each failed move was rolled back, so they start clean)
    public function retry(): void {
        $this->post();
        $job = (int)($_POST['job'] ?? 0);
        $j = Transfer::finishedJob($job);
        if (!$j) $this->out(['error' => 'That run is not finished'], 400);
        // not the ones a later run already moved (they exist here now)
        $users = Transfer::retryableUsernames($job);
        if (!$users) $this->out(['error' => 'Nothing left to retry from that run: its failed accounts are here now'], 400);
        $_POST['pairing'] = $j['pairing'];
        $_POST['users'] = $users;
        $this->start();
    }
    // TARGET: switch one copied account over to this server (detached:
    // transfer-pull.sh cutover; the bar follows it like a copy)
    public function cutover(): void {
        $this->post();
        $item = (int)($_POST['item'] ?? 0);
        $it = Transfer::cutItem($item);
        if (!$it) $this->out(['error' => 'That account is not on this server any more'], 400);
        if ($it['stage'] !== 'ready') $this->out(['error' => 'Only a copied (ready) account can be cut over'], 400);
        if ((string)($_POST['confirm'] ?? '') !== $it['username']) $this->out(['error' => 'Confirm with the account name'], 400);
        $r = ServiceManager::run('transfer-pull.sh', ['cutover', (string)$item], 60);
        if ((int)$r['code'] !== 0) $this->fail($r);
        $this->out(['ok' => true]);
    }
    // TARGET: a cutover whose worker died before mail moved: put the old
    // server back (the script checks no worker holds the lock; the old
    // server refuses once mail is passed on)
    public function cutundo(): void {
        $this->post();
        $item = (int)($_POST['item'] ?? 0);
        $r = ServiceManager::run('transfer-pull.sh', ['cutundo', (string)$item], 60);
        if ((int)$r['code'] !== 0) $this->fail($r);
        $this->out(['ok' => true]);
    }
    // TARGET, after a cutover: what public resolvers answer for the account's
    // names right now (and where its nameservers live). The page asks while
    // its "DNS check" is open; the script asks the resolvers, never local BIND.
    public function dnscheck(): void {
        $this->post();
        $item = (int)($_POST['item'] ?? 0);
        $r = ServiceManager::run('transfer-dns.sh', [(string)$item], 50);
        if ((int)$r['code'] !== 0) $this->fail($r);
        $d = ['me' => [], 'old' => '', 'resolvers' => [], 'note' => '', 'names' => [], 'zones' => []];
        $st = '/^(here|old|other|none|unknown|elsewhere|mixed)$/';
        foreach (explode("\n", (string)$r['out']) as $l) {
            $f = explode("\t", rtrim($l, "\r"));
            if (preg_match('/^(me|old|resolvers|note) (.*)$/', $l, $m)) {
                if ($m[1] === 'me' || $m[1] === 'resolvers') $d[$m[1]] = array_values(array_filter(explode(',', $m[2]), fn($x) => (bool)filter_var($x, FILTER_VALIDATE_IP)));
                elseif ($m[1] === 'old') $d['old'] = filter_var($m[2], FILTER_VALIDATE_IP) ? $m[2] : '';
                else $d['note'] = substr($m[2], 0, 120);
            } elseif ($f[0] === 'a' && count($f) === 6 && preg_match($st, $f[3])) {
                $d['names'][$f[1]]['name'] = $f[1];
                $d['names'][$f[1]]['a'][$f[2]] = ['s' => $f[3], 'ips' => $f[4] === '-' ? '' : $f[4], 'ttl' => ctype_digit($f[5]) ? (int)$f[5] : null];
            } elseif ($f[0] === 'aaaa' && count($f) === 4 && preg_match($st, $f[2])) {
                $d['names'][$f[1]]['name'] = $f[1];
                $d['names'][$f[1]]['aaaa'] = ['s' => $f[2], 'ips' => $f[3]];
            } elseif ($f[0] === 'ns' && count($f) === 4 && preg_match($st, $f[2])) {
                $d['zones'][] = ['zone' => $f[1], 's' => $f[2], 'ns' => $f[3] === '-' ? '' : $f[3]];
            }
        }
        $d['names'] = array_values($d['names']);
        $this->out($d);
    }
    // live bars: the last 14 days of runs here. A worker that stopped
    // writing shows as stalled (updated_at), never "running" forever.
    public function status(): void {
        Auth::requireRole(['admin']);
        $jobs = Transfer::recentJobs();
        foreach ($jobs as &$j) {
            // here = the account this run made still exists (a later run or a delete may have replaced it)
            $items = Transfer::itemsFor((int)$j['id']);
            foreach ($items as &$it) {
                foreach (['id', 'pct', 'bytes_done', 'bytes_total', 'rate_bps', 'idle', 'here'] as $n) $it[$n] = (int)$it[$n];
                if (!in_array($it['stage'], ['ready', 'failed', 'live'], true)) $it['report'] = null;
            }
            unset($it);
            $j['id'] = (int)$j['id']; $j['idle'] = (int)$j['idle'];
            $j['items'] = $items;
        }
        unset($j);
        $this->out(['jobs' => $jobs]);
    }
}
