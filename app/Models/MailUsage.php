<?php
declare(strict_types=1);
// Mailbox usage (MB) for many addresses in ONE privileged call: mail-usage.sh
// batch mode bounds each Dovecot lookup and the whole run, so a stuck
// Dovecot costs the page ~10s once instead of 15s per mailbox. Addresses
// missing from the result (unreadable / budget hit) render as —.
class MailUsage {
    private const RE = '/^[a-z0-9._-]+@[a-z0-9.-]+\.[a-z]{2,}$/';
    /** @param string[] $addrs  @return array<string,float> */
    public static function many(array $addrs): array {
        $addrs = array_values(array_unique(array_filter($addrs, fn($a) => is_string($a) && preg_match(self::RE, $a))));
        if (!$addrs) return [];
        $out = [];
        try {
            $r = ServiceManager::run('mail-usage.sh', array_merge(['--batch'], $addrs));
            if (($r['code'] ?? 1) !== 0) return [];
            foreach (explode("\n", (string)($r['out'] ?? '')) as $line) {
                $p = explode("\t", trim($line));
                if (count($p) === 2 && preg_match(self::RE, $p[0]) && preg_match('/^[\d.]+$/', $p[1])) $out[$p[0]] = (float)$p[1];
            }
        } catch (Throwable $e) {}
        return $out;
    }
}
