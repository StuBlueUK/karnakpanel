<?php
declare(strict_types=1);
/**
 * ApiAuth — X-API-Key authentication for the REST/provisioning API
 * (ApiController + the ApiDomain/Dns/Mail/Data/Token splits inherit the
 * `tokenAuth()` wrapper).
 *
 * Split out of ApiController in the 2026-11-08 security sweep (M1): token
 * failures used to return null silently, so the [panel] fail2ban jail could
 * never see a guessing client. The logging lives here so it has room to
 * explain itself without growing a frozen controller.
 */
class ApiAuth {
    /**
     * Resolve the calling user, or null after logging why the token failed.
     * @return array<string,mixed>|null
     */
    public static function user(): ?array {
        $h = (string)($_SERVER['HTTP_X_API_KEY'] ?? '');
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if ($h === '') { self::failed($ip, 'no token'); return null; }
        if (!preg_match('/^[a-f0-9]{64}$/', $h)) { self::failed($ip, 'malformed token'); return null; }
        // tokens are stored sha256-hashed (a table leak alone grants nothing)
        $u = ApiModel::token($h);
        if (!$u) { self::failed($ip, 'unknown or expired token'); return null; }
        // IP pin: a stolen token is useless off the pinned host (WHMCS IP or
        // same-box server IP). NULL/empty = any IP (previous behaviour).
        if (!empty($u['allowed_ip']) && $u['allowed_ip'] !== $ip) { self::failed($ip, 'token IP mismatch'); return null; }
        // last-use audit (at most one write a minute per token; never fatal)
        try { ApiModel::touchToken((int)$u['token_id'], $ip); } catch (Throwable $e) {}
        unset($u['password_hash'], $u['totp_secret']);
        return $u;
    }
    /**
     * One line per failed auth for the [panel] fail2ban jail (filter
     * skypanel-panel: `API auth failed from <HOST>`). Only the caller IP and a
     * fixed reason are written — never the token, the request path or any
     * other client input. fail2ban's `.*` before <HOST> is greedy, so a
     * crafted path containing "… from <ip>" could otherwise frame another
     * address into a ban. Anonymous /api/* hits count too: they are probes.
     */
    private static function failed(string $ip, string $reason): void {
        Helpers::log("API auth failed from $ip ($reason)");
    }
}
