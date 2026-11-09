<?php
declare(strict_types=1);
// WebAuthn (passkeys) as a SECOND factor, no libraries and no CBOR:
// attestation 'none', and the browser hands us the public key as SPKI DER
// (AuthenticatorAttestationResponse.getPublicKey(), every current browser)
// plus the raw authenticatorData. Signatures check with openssl_verify over
// authData || SHA-256(clientDataJSON); ES256 signatures arrive DER-encoded.
// Algorithms: ES256 (-7) and RS256 (-257) only.
//
// Relying party = the panel_domain setting, and ONLY when the request came
// in over HTTPS on exactly that host: passkeys refuse IPs and self-signed
// certs anyway, and pinning the RP to a setting (not the Host header) means
// nothing a client sends can choose which RP we verify against.
class WebAuthn {
    public const ALGS = [-7, -257];
    private const TTL = 180; // seconds a challenge stays valid

    public static function panelDomain(): string {
        try {
            $pd = strtolower(trim((string)(Database::query("SELECT value FROM settings WHERE name='panel_domain'")->fetch()['value'] ?? '')));
        } catch (Throwable $e) { return ''; }
        return (Helpers::validDomain($pd) && !filter_var($pd, FILTER_VALIDATE_IP)) ? $pd : '';
    }
    // the RP id for THIS request, or null when passkeys can't work here
    public static function rpId(): ?string {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $pd = self::panelDomain();
        if (!$https || $pd === '') return null;
        $host = strtolower(trim(explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0]));
        return hash_equals($pd, $host) ? $pd : null;
    }
    // the URL passkeys work at (shown when the user is on another address)
    public static function homeUrl(): string {
        $pd = self::panelDomain();
        if ($pd === '') return '';
        $p = (int)($_SERVER['SERVER_PORT'] ?? 8090);
        return 'https://'.$pd.($p === 443 ? '' : ':'.$p);
    }
    private static function origin(string $rp): string {
        $p = (int)($_SERVER['SERVER_PORT'] ?? 443);
        return 'https://'.$rp.($p === 443 ? '' : ':'.$p);
    }

    public static function b64u(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }
    public static function unb64u(string $s): ?string {
        if (!preg_match('/^[A-Za-z0-9_-]*$/', $s)) return null;
        $r = base64_decode(strtr($s, '-_', '+/'), true);
        return $r === false ? null : $r;
    }

    // one live challenge per session; bound to purpose + user, single use
    public static function challenge(string $purpose, int $uid): string {
        $c = random_bytes(32);
        $_SESSION['webauthn'] = ['p' => $purpose, 'u' => $uid, 'c' => self::b64u($c), 'exp' => time() + self::TTL];
        return self::b64u($c);
    }
    private static function takeChallenge(string $purpose, int $uid): ?string {
        $w = $_SESSION['webauthn'] ?? null;
        unset($_SESSION['webauthn']); // consumed before any check: never replayable
        if (!is_array($w) || ($w['p'] ?? '') !== $purpose || (int)($w['u'] ?? 0) !== $uid || (int)($w['exp'] ?? 0) < time()) return null;
        return (string)$w['c'];
    }

    private static function checkClientData(string $cdj, string $type, string $chal, string $rp): bool {
        $cd = json_decode($cdj, true);
        if (!is_array($cd)) return false;
        return ($cd['type'] ?? '') === $type
            && is_string($cd['challenge'] ?? null) && hash_equals($chal, $cd['challenge'])
            && ($cd['origin'] ?? '') === self::origin($rp)
            && empty($cd['crossOrigin']);
    }
    // rpIdHash + user-present flag; returns [flags, signCount] or null
    private static function checkAuthData(string $ad, string $rp): ?array {
        if (strlen($ad) < 37 || !hash_equals(hash('sha256', $rp, true), substr($ad, 0, 32))) return null;
        $flags = ord($ad[32]);
        if (!($flags & 0x01)) return null; // UP: someone touched the authenticator
        return [$flags, (int)unpack('N', substr($ad, 33, 4))[1]];
    }

    // Registration. $in: id, cdj, ad, pk (base64url), alg. Returns the row
    // values to store, or an error string.
    public static function verifyRegistration(array $in, int $uid, string $rp) {
        $chal = self::takeChallenge('reg', $uid);
        if ($chal === null) return 'That request expired — try again';
        $id = self::unb64u((string)($in['id'] ?? ''));
        $cdj = self::unb64u((string)($in['cdj'] ?? ''));
        $ad = self::unb64u((string)($in['ad'] ?? ''));
        $pk = self::unb64u((string)($in['pk'] ?? ''));
        $alg = (int)($in['alg'] ?? 0);
        if ($id === null || $cdj === null || $ad === null || $pk === null || $id === '' || $pk === '') return 'Incomplete answer from the browser';
        if (strlen($id) > 512 || strlen($cdj) > 4096 || strlen($ad) > 4096 || strlen($pk) > 2048) return 'Answer too large';
        if (!in_array($alg, self::ALGS, true)) return 'This passkey uses an unsupported key type';
        if (!self::checkClientData($cdj, 'webauthn.create', $chal, $rp)) return 'Browser answer did not match this panel';
        $fc = self::checkAuthData($ad, $rp);
        if ($fc === null || !($fc[0] & 0x40) || strlen($ad) < 55) return 'Authenticator data did not match this panel';
        // attested credential data: aaguid(16) | idLen(2) | id
        $len = (int)unpack('n', substr($ad, 53, 2))[1];
        if (!hash_equals(substr($ad, 55, $len), $id)) return 'Credential id mismatch';
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($pk), 64, "\n")."-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        if ($key === false) return 'Unreadable public key';
        $d = openssl_pkey_get_details($key) ?: [];
        $okKey = $alg === -7
            ? (($d['type'] ?? -1) === OPENSSL_KEYTYPE_EC && ($d['ec']['curve_name'] ?? '') === 'prime256v1')
            : (($d['type'] ?? -1) === OPENSSL_KEYTYPE_RSA && (int)($d['bits'] ?? 0) >= 2048);
        if (!$okKey) return 'Key does not match its algorithm';
        return ['cred_id' => self::b64u($id), 'public_key' => $pem, 'alg' => $alg,
                'sign_count' => $fc[1], 'synced' => ($fc[0] & 0x10) ? 1 : 0];
    }

    // Login. $in: id, cdj, ad, sig. The credential must belong to $uid AND
    // to this RP. Returns the passkey row id on success, or an error string.
    public static function verifyLogin(array $in, int $uid, string $rp) {
        $chal = self::takeChallenge('auth', $uid);
        if ($chal === null) return 'That request expired — try again';
        $cdj = self::unb64u((string)($in['cdj'] ?? ''));
        $ad = self::unb64u((string)($in['ad'] ?? ''));
        $sig = self::unb64u((string)($in['sig'] ?? ''));
        $cid = (string)($in['id'] ?? '');
        if ($cdj === null || $ad === null || $sig === null || $sig === '' || self::unb64u($cid) === null) return 'Incomplete answer from the browser';
        if (strlen($cdj) > 4096 || strlen($ad) > 4096 || strlen($sig) > 1024 || strlen($cid) > 700) return 'Answer too large';
        $row = Database::query("SELECT id, public_key, alg, sign_count FROM user_passkeys WHERE cred_id=? AND user_id=? AND rp_id=?", [$cid, $uid, $rp])->fetch();
        if (!$row) return 'Unknown passkey for this account';
        if (!self::checkClientData($cdj, 'webauthn.get', $chal, $rp)) return 'Browser answer did not match this panel';
        $fc = self::checkAuthData($ad, $rp);
        if ($fc === null) return 'Authenticator data did not match this panel';
        $ok = openssl_verify($ad.hash('sha256', $cdj, true), $sig, (string)$row['public_key'], OPENSSL_ALGO_SHA256);
        if ($ok !== 1) return 'Signature check failed';
        // clone detection: counters only ever go up (synced passkeys send 0)
        $old = (int)$row['sign_count']; $new = $fc[1];
        if (($old > 0 || $new > 0) && $new <= $old) return 'Passkey counter went backwards — possible cloned key';
        Database::query("UPDATE user_passkeys SET sign_count=?, last_used_at=NOW() WHERE id=?", [$new, $row['id']]);
        return (int)$row['id'];
    }

    public static function countFor(int $uid, ?string $rp = null): int {
        try {
            return $rp === null
                ? (int)Database::query("SELECT COUNT(*) c FROM user_passkeys WHERE user_id=?", [$uid])->fetch()['c']
                : (int)Database::query("SELECT COUNT(*) c FROM user_passkeys WHERE user_id=? AND rp_id=?", [$uid, $rp])->fetch()['c'];
        } catch (Throwable $e) { return 0; } // table not migrated yet = none
    }

    /** passkeys bound to one panel host (rp_id), across all users */
    public static function countForHost(string $rp): int {
        return (int)Database::query("SELECT COUNT(*) c FROM user_passkeys WHERE rp_id=?", [strtolower($rp)])->fetch()['c'];
    }
}
