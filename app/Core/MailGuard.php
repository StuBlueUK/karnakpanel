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
}
