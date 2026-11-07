<?php
declare(strict_types=1);
// Mailbox import (IMAP pull, scripts/mail-import.sh — spec in
// docs/mail-import-spec.md). The script re-checks everything here; these
// checks exist so the form can answer before a privileged call.
class MailImport {
    public const PER_HOUR = 6;      // attempts per account per hour (failed logins count)
    public const STALE_MIN = 10;    // a running row silent this long was interrupted
    // app-password providers only: Microsoft (Outlook.com / 365) is OAuth-only
    public const PRESETS = [
        'imap.gmail.com' => 'Gmail', 'imap.mail.yahoo.com' => 'Yahoo', 'imap.mail.me.com' => 'iCloud',
        'imap.aol.com' => 'AOL', 'imap.zoho.com' => 'Zoho', 'imap.zoho.eu' => 'Zoho (EU)',
        'imap.fastmail.com' => 'Fastmail', 'imap.gmx.com' => 'GMX',
    ];
    public static function validHostName(string $h): bool {
        return strlen($h) <= 253 && (bool)preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $h);
    }
    public static function validRemoteUser(string $u): bool {
        return (bool)preg_match('/^[A-Za-z0-9._+=@-]{1,254}$/', $u);
    }
    /** Public unicast only — no loopback, private, CGNAT, link-local, ULA, multicast, reserved. */
    public static function publicIp(string $ip): bool {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        if (str_contains($ip, ':')) {
            $ip = strtolower($ip);
            return (bool)preg_match('/^[23][0-9a-f]{3}:/', $ip) && !preg_match('/^(64:ff9b|2001:0?db8):/', $ip);
        }
        $p = array_map('intval', explode('.', $ip));
        if ($p[0] >= 224 || ($p[0] === 100 && $p[1] >= 64 && $p[1] <= 127)) return false;
        if ($p[0] === 192 && $p[1] === 0 && $p[2] === 0) return false;
        return !($p[0] === 198 && ($p[1] === 18 || $p[1] === 19));
    }
    /** '' when the host may be used, else the reason. */
    public static function hostError(string $h): string {
        if (!self::validHostName($h)) return 'Server must be a name like imap.example.com';
        $ips = [];
        foreach ([DNS_A, DNS_AAAA] as $t) {
            foreach (@dns_get_record($h, $t) ?: [] as $r) { if (!empty($r['ip'])) $ips[] = $r['ip']; if (!empty($r['ipv6'])) $ips[] = $r['ipv6']; }
        }
        if (!$ips) return "$h not found in DNS";
        foreach ($ips as $ip) if (!self::publicIp($ip)) return "$h points at a private or reserved address — not allowed";
        return '';
    }
    /** Display stage: a running row nobody updated lately was interrupted (reboot). */
    public static function stage(array $r): string {
        $s = (string)$r['stage'];
        if (in_array($s, ['checking', 'running'], true) && strtotime((string)($r['updated_at'] ?? $r['created_at'])) < time() - self::STALE_MIN * 60) return 'stalled';
        return $s;
    }
    public static function pct(array $r): int {
        if (in_array($r['stage'], ['done'], true)) return 100;
        $t = (int)$r['total'];
        return $t > 0 ? min(99, intdiv((int)$r['copied'] * 100, $t)) : 0;
    }
}
