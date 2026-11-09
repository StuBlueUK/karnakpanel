<?php
declare(strict_types=1);
// RFC 6238 TOTP (SHA1, 30s step, 6 digits). Dependency-free: HMAC + base32 only.
class Totp {
    public static function makeSecret(int $bytes = 20): string {
        return self::b32enc(random_bytes($bytes));
    }
    public static function code(string $secret, ?int $at = null): string {
        $key = self::b32dec($secret);
        $t = intdiv($at ?? time(), 30);
        $msg = pack('N2', 0, $t); // 64-bit big-endian counter
        $h = hash_hmac('sha1', $msg, $key, true);
        $o = ord($h[19]) & 0x0f;
        $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o+1]) << 16) | (ord($h[$o+2]) << 8) | ord($h[$o+3]);
        return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
    }
    // ±$window steps either side (clock skew). Constant-time compare.
    public static function verify(string $secret, string $code, int $window = 1): bool {
        return self::verifyStep($secret, $code, $window) !== null;
    }
    // Same check, returning the matched 30s step (for replay protection:
    // callers persist it and refuse any step <= the last accepted one).
    public static function verifyStep(string $secret, string $code, int $window = 1): ?int {
        if (!preg_match('/^\d{6}$/', $code)) return null;
        $now = time();
        for ($i = -$window; $i <= $window; $i++) {
            $at = $now + $i * 30;
            if (hash_equals(self::code($secret, $at), $code)) return intdiv($at, 30);
        }
        return null;
    }
    public static function otpauthUrl(string $secret, string $account): string {
        return 'otpauth://totp/KarnakPanel:'.rawurlencode($account).'?secret='.$secret.'&issuer=KarnakPanel';
    }
    private static function b32enc(string $bin): string {
        $abc = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $out = ''; $buf = 0; $bits = 0;
        for ($i = 0; $i < strlen($bin); $i++) {
            $buf = ($buf << 8) | ord($bin[$i]); $bits += 8;
            while ($bits >= 5) { $bits -= 5; $out .= $abc[($buf >> $bits) & 31]; }
        }
        if ($bits > 0) $out .= $abc[($buf << (5 - $bits)) & 31];
        return $out;
    }
    private static function b32dec(string $s): string {
        $s = strtoupper(rtrim($s, '='));
        $map = array_flip(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'));
        $out = ''; $buf = 0; $bits = 0;
        for ($i = 0; $i < strlen($s); $i++) {
            if (!isset($map[$s[$i]])) throw new InvalidArgumentException('bad base32');
            $buf = ($buf << 5) | $map[$s[$i]]; $bits += 5;
            if ($bits >= 8) { $bits -= 8; $out .= chr(($buf >> $bits) & 255); }
        }
        return $out;
    }
}
