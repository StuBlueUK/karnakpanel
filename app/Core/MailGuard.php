<?php
// MailGuard — compromised-mailbox detector core (pure: no DB, no IO, so
// tests/run.php can feed it log lines). cli/mail-guard.php reads the Exim
// mainlog window, asks verdict() per authenticated sender, and holds.
//
// Signals per authenticated mailbox inside the window (only activity after
// the mailbox's last release counts — the caller passes that fence):
//   nets    distinct client networks it sent from (/24 v4, /48 v6; loopback
//           = webmail, ignored). Stolen passwords get used from botnets.
//   caphits RCPTs refused by the hourly sending cap (the cap holds the line,
//           repeated hammering on it is the tell).
//   bounce  hard bounces (**) among its messages' recipients: spam runs go to
//           scraped lists full of dead addresses.
// Hold = two signals over threshold, or any one far over (STRONG). Mobile
// users roam networks and newsletters hit the cap, so one mild signal alone
// never holds.
final class MailGuard {
    public const NETS = 4,  NETS_STRONG = 12;
    public const CAP = 20,  CAP_STRONG = 60;
    public const BOUNCE_MIN = 10, BOUNCE_RATIO = 0.3;
    public const BOUNCE_STRONG_MIN = 30, BOUNCE_STRONG_RATIO = 0.5;

    /** Client network key for an IP, or null for loopback/garbage. */
    public static function net(string $ip): ?string {
        $bin = @inet_pton($ip);
        if ($bin === false) return null;
        if (strlen($bin) === 4) {
            if ($bin[0] === "\x7f") return null;
            return implode('.', array_slice(explode('.', $ip), 0, 3)).'.0/24';
        }
        if ($bin === inet_pton('::1')) return null;
        if (str_starts_with($bin, "\0\0\0\0\0\0\0\0\0\0\xff\xff")) return self::net((string)inet_ntop(substr($bin, 12)));
        return bin2hex(substr($bin, 0, 6)).'::/48';
    }

    /**
     * Parse mainlog lines (any order of files is fine; ids join them).
     * Returns events with their timestamps so the caller can fence per
     * mailbox: ['sends' => [addr => [[ts, net|null, msgid], ...]],
     *           'caps'  => [addr => [ts, ...]],
     *           'out'   => [msgid => ['bounced' => n, 'delivered' => n]]].
     * $since: 'Y-m-d H:i:s' lower bound (Exim logs local time, same format).
     */
    public static function parse(iterable $lines, string $since): array {
        $r = ['sends' => [], 'caps' => [], 'out' => []];
        foreach ($lines as $l) {
            if (strlen($l) < 20 || strcmp(substr($l, 0, 19), $since) < 0) continue;
            $ts = substr($l, 0, 19);
            if (preg_match('/^\S+ \S+ (\S+) <= \S+ .*?\[([0-9a-fA-F.:]+)\](?::\d+)? .*?\bA=dovecot_(?:plain|login):(\S+)/', $l, $m)) {
                $a = strtolower($m[3]);
                if (!self::addr($a)) continue;
                $r['sends'][$a][] = [$ts, self::net($m[2]), $m[1]];
            } elseif (str_contains($l, 'rejected RCPT') && str_contains($l, 'sending rate exceeded')
                && preg_match('/\bA=dovecot_(?:plain|login):(\S+)/', $l, $m)) {
                $a = strtolower($m[1]);
                if (self::addr($a)) $r['caps'][$a][] = $ts;
            } elseif (preg_match('/^\S+ \S+ (\S+) (\*\*|=>|->) /', $l, $m)) {
                $k = $m[2] === '**' ? 'bounced' : 'delivered';
                $r['out'][$m[1]][$k] = ($r['out'][$m[1]][$k] ?? 0) + 1;
            }
        }
        return $r;
    }

    public static function addr(string $a): bool {
        return (bool)preg_match('/^[a-z0-9._%+-]{1,64}@[a-z0-9.-]+\.[a-z]{2,}$/', $a);
    }

    /** Signal counts for one mailbox, counting only events at/after $fence. */
    public static function stats(array $p, string $addr, string $fence): array {
        $nets = []; $ids = []; $caps = 0; $b = 0; $d = 0;
        foreach ($p['sends'][$addr] ?? [] as [$ts, $net, $id]) {
            if (strcmp($ts, $fence) < 0) continue;
            if ($net !== null) $nets[$net] = true;
            $ids[$id] = true;
        }
        foreach ($p['caps'][$addr] ?? [] as $ts) if (strcmp($ts, $fence) >= 0) $caps++;
        foreach (array_keys($ids) as $id) {
            $b += $p['out'][$id]['bounced'] ?? 0;
            $d += $p['out'][$id]['delivered'] ?? 0;
        }
        return ['nets' => count($nets), 'caphits' => $caps, 'bounced' => $b, 'delivered' => $d, 'msgs' => count($ids)];
    }

    /** Hold reason (human text) or null to leave the mailbox alone. */
    public static function verdict(array $s): ?string {
        $tot = $s['bounced'] + $s['delivered'];
        $ratio = $tot > 0 ? $s['bounced'] / $tot : 0.0;
        $why = []; $strong = false;
        if ($s['nets'] >= self::NETS) { $why[] = "sent from {$s['nets']} networks"; $strong = $strong || $s['nets'] >= self::NETS_STRONG; }
        if ($s['caphits'] >= self::CAP) { $why[] = "{$s['caphits']} sends refused by the hourly cap"; $strong = $strong || $s['caphits'] >= self::CAP_STRONG; }
        if ($s['bounced'] >= self::BOUNCE_MIN && $ratio >= self::BOUNCE_RATIO) {
            $why[] = sprintf('%d of %d recipients bounced (%d%%)', $s['bounced'], $tot, (int)round($ratio * 100));
            $strong = $strong || ($s['bounced'] >= self::BOUNCE_STRONG_MIN && $ratio >= self::BOUNCE_STRONG_RATIO);
        }
        if (count($why) >= 2 || $strong) return 'in the last hour: '.implode('; ', $why);
        return null;
    }

    // ---------------------------------------------------------------- PHP mail
    // Local submissions by hosting accounts (PHP mail()/sendmail), logged as
    //   <ts> <id> <= <sender|<>> U=<account> P=local[-esmtp] S=...
    //   <ts> <id> F=<...> rejected by non-SMTP ACL: php-mail cap: <account>
    // plus the usual => / -> / ** / == delivery lines keyed by message id.
    // Tier A (hourly window): an account flooding past its cap gets PHP mail
    // switched off. Tier B (24 h, advisory): a site form being fired by bots
    // at other people's addresses (the "no CAPTCHA" pattern).
    public const PHP_CAP = 20, PHP_CAP_STRONG = 60, PHP_RCPTS = 50;
    public const FORM_MIN_MSGS = 5, FORM_MIN_IPS = 3, FORM_RCPT_RATIO = 0.8;
    public const FORM_BADUA_RATIO = 0.5, FORM_FAIL_RATIO = 0.3, FORM_LAG = 10;

    /**
     * ['subs' => [acct => [[ts, msgid, sender], ...]], 'caps' => [acct => [ts, ...]],
     *  'rcpt' => [msgid => [addr => 'delivered'|'bounced'|'deferred']]].
     * $accts: account name => anything (only those count; system idents such
     * as root, Debian-exim, vmail are never in it).
     */
    public static function parseLocal(iterable $lines, string $since, array $accts): array {
        $r = ['subs' => [], 'caps' => [], 'rcpt' => []];
        $rank = ['deferred' => 0, 'bounced' => 1, 'delivered' => 2];
        foreach ($lines as $l) {
            if (strlen($l) < 20 || strcmp(substr($l, 0, 19), $since) < 0) continue;
            $ts = substr($l, 0, 19);
            if (preg_match('/^\S+ \S+ (\S+) <= (<>|\S+) U=([a-z][a-z0-9_-]{0,31}) P=local\S* /', $l, $m)) {
                if (isset($accts[$m[3]])) $r['subs'][$m[3]][] = [$ts, $m[1], $m[2] === '<>' ? '' : strtolower($m[2])];
            } elseif (preg_match('/ rejected by non-SMTP ACL: php-mail cap: ([a-z][a-z0-9_-]{0,31})$/', $l, $m)) {
                if (isset($accts[$m[1]])) $r['caps'][$m[1]][] = $ts;
            } elseif (preg_match('/^\S+ \S+ (\S+) (=>|->|\*\*|==) (\S+)/', $l, $m)) {
                $a = strtolower(trim($m[3], '<>'));
                if ($a === '' || $a[0] === ':' || !str_contains($a, '@')) continue;
                $st = $m[2] === '**' ? 'bounced' : ($m[2] === '==' ? 'deferred' : 'delivered');
                $cur = $r['rcpt'][$m[1]][$a] ?? null;
                if ($cur === null || $rank[$st] > $rank[$cur]) $r['rcpt'][$m[1]][$a] = $st;
            }
        }
        return $r;
    }

    /** Tier A counts for one account, only events at/after $fence. */
    public static function localStats(array $p, string $acct, string $fence): array {
        $ids = []; $rc = []; $b = 0; $d = 0; $f = 0; $caps = 0;
        foreach ($p['subs'][$acct] ?? [] as [$ts, $id]) if (strcmp($ts, $fence) >= 0) $ids[$id] = true;
        foreach ($p['caps'][$acct] ?? [] as $ts) if (strcmp($ts, $fence) >= 0) $caps++;
        foreach (array_keys($ids) as $id) foreach ($p['rcpt'][$id] ?? [] as $a => $st) {
            $rc[$a] = true;
            if ($st === 'bounced') $b++; elseif ($st === 'delivered') $d++; else $f++;
        }
        return ['msgs' => count($ids), 'caphits' => $caps, 'rcpts' => count($rc), 'bounced' => $b, 'delivered' => $d, 'deferred' => $f];
    }

    /** Switch-off reason or null. A cap hit alone (a newsletter plugin) is mild: it needs a second signal unless sustained. */
    public static function phpVerdict(array $s): ?string {
        if ($s['caphits'] < self::PHP_CAP) return null;
        $why = ["{$s['caphits']} PHP mail sends refused by the hourly cap"];
        $tot = $s['bounced'] + $s['delivered'];
        $second = false;
        if ($s['bounced'] >= self::BOUNCE_MIN && $tot > 0 && $s['bounced'] / $tot >= self::BOUNCE_RATIO) {
            $why[] = sprintf('%d of %d recipients bounced', $s['bounced'], $tot); $second = true;
        }
        if ($s['rcpts'] >= self::PHP_RCPTS) { $why[] = "{$s['rcpts']} different recipients"; $second = true; }
        if (!$second && $s['caphits'] < self::PHP_CAP_STRONG) return null;
        return 'in the last hour: '.implode('; ', $why);
    }

    /** One POST from an OLS access log line (lines are wrapped in '…' by our logFormat), or null. */
    public static function accessPost(string $l): ?array {
        if (!preg_match('/^\'?(\S+) \S+ \S+ \[([^\]]+)\] "POST (\S+) [^"]*" (\d{3}) \S+ "(?:[^"\\\\]|\\\\.)*" "((?:[^"\\\\]|\\\\.)*)"/', $l, $m)) return null;
        $t = \DateTime::createFromFormat('d/M/Y:H:i:s O', $m[2]);
        if (!$t) return null;
        $path = strtok($m[3], '?') ?: '/';
        return [$t->getTimestamp(), $m[1], substr($path, 0, 190), $m[5]];
    }

    /** Bot, script or long-outdated browser — what form-spam runs present as. */
    public static function badUa(string $ua): bool {
        if ($ua === '' || $ua === '-') return true;
        if (preg_match('/bot\b|crawl|spider|curl|wget|python|go-http|java\/|libwww|httpclient|okhttp|scrapy|headless|phantomjs/i', $ua)) return true;
        if (preg_match('/MSIE |Trident\/|Android [1-6]\./', $ua)) return true;
        if (preg_match('/(?:Chrome|Firefox)\/(\d+)/', $ua, $m) && (int)$m[1] < 100) return true;
        return false;
    }

    /**
     * Tier B. $subs: [[epoch, msgid], ...] for one account; $posts: [domain =>
     * [[epoch, ip, path, ua], ...]] from its sites. Each message is pinned to
     * the latest POST on its sites in the FORM_LAG seconds before it; groups
     * per domain+path that look like bots firing a form at other people's
     * addresses are returned (a contact form mailing its owner has ONE
     * recipient, real sign-ups come from current browsers and valid inboxes).
     */
    public static function formAbuse(array $subs, array $posts, array $rcpt): array {
        $all = [];
        foreach ($posts as $dom => $list) foreach ($list as $q) {
            if (str_ends_with($q[2], '/wp-cron.php')) continue;   // WP's own loopback
            $all[] = [$q[0], $dom, $q[1], $q[2], $q[3]];
        }
        usort($all, fn($a, $b) => $a[0] <=> $b[0]);
        $g = [];
        foreach ($subs as [$t, $id]) {
            $hit = null;
            // latest POST with epoch in [t - lag, t]
            $lo = 0; $hi = count($all) - 1;
            while ($lo <= $hi) { $mid = intdiv($lo + $hi, 2); if ($all[$mid][0] <= $t) { $hit = $mid; $lo = $mid + 1; } else $hi = $mid - 1; }
            if ($hit === null || $all[$hit][0] < $t - self::FORM_LAG) continue;
            [, $dom, $ip, $path, $ua] = $all[$hit];
            $k = "$dom $path";
            $g[$k] ??= ['domain' => $dom, 'path' => $path, 'msgs' => 0, 'ips' => [], 'bad' => 0, 'rc' => [], 'n' => 0, 'fail' => 0];
            $g[$k]['msgs']++;
            $g[$k]['ips'][$ip] = true;
            if (self::badUa($ua)) $g[$k]['bad']++;
            foreach ($rcpt[$id] ?? [] as $a => $st) { $g[$k]['rc'][$a] = true; $g[$k]['n']++; if ($st !== 'delivered') $g[$k]['fail']++; }
        }
        $out = [];
        foreach ($g as $x) {
            $ips = count($x['ips']); $rcs = count($x['rc']);
            if ($x['msgs'] < self::FORM_MIN_MSGS || $ips < self::FORM_MIN_IPS || $x['n'] < self::FORM_MIN_MSGS) continue;
            if ($rcs / $x['n'] < self::FORM_RCPT_RATIO) continue;
            $badR = $x['bad'] / $x['msgs']; $failR = $x['fail'] / $x['n'];
            if ($badR < self::FORM_BADUA_RATIO && $failR < self::FORM_FAIL_RATIO) continue;
            $out[] = ['domain' => $x['domain'], 'path' => $x['path'], 'msgs' => $x['msgs'], 'rcpts' => $rcs, 'ips' => $ips,
                      'bad_pct' => (int)round($badR * 100), 'fail_pct' => (int)round($failR * 100)];
        }
        return $out;
    }
}
