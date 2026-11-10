<?php
// Basic auth against the mailbox passwords (same hashes Dovecot checks:
// bcrypt from the panel, or an imported crypt() hash, optionally prefixed
// with a Dovecot {SCHEME}). Stricter than IMAP on purpose: a suspended
// account, or a domain whose mail is handled elsewhere, gets no DAV login.
// Every wrong password is written to auth.log for the fail2ban [karnak-dav]
// jail (a missing Authorization header is the normal first round-trip of
// every client and is NOT logged). Failures against one address also feed a
// per-mailbox counter (Store::noteAuthFail) that adds an escalating delay, so
// guessing from many IPs at once — invisible to a per-IP jail — still costs.
namespace KarnakDav;

use Sabre\DAV\Auth\Backend\AbstractBasic;
use Sabre\HTTP;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

final class AuthBackend extends AbstractBasic
{
    public const LOG = '/var/log/karnak-dav/auth.log';
    private string $address = '';

    public function __construct()
    {
        $this->realm = 'KarnakPanel calendar and contacts';
        $this->principalPrefix = 'principals/';
    }

    // AbstractBasic::check builds the principal from the username exactly as
    // typed; normalise to lower case so "Bob@Example.com" is one principal.
    public function check(RequestInterface $request, ResponseInterface $response)
    {
        $auth = new HTTP\Auth\Basic($this->realm, $request, $response);
        $up = $auth->getCredentials();
        if (!$up) {
            return [false, "No 'Authorization: Basic' header found"];
        }
        if (!$this->validateUserPass($up[0], $up[1])) {
            return [false, 'Username or password was incorrect'];
        }
        return [true, Store::principalUri($this->address)];
    }

    protected function validateUserPass($username, $password)
    {
        $a = strtolower(trim((string)$username));
        $ok = false;
        $mb = null;
        if (Store::validAddress($a) && $password !== '' && strlen((string)$password) <= 1024) {
            $mb = Store::mailbox($a);
            $hash = $mb ? (string)preg_replace('/^\{[A-Z0-9-]+\}/', '', (string)$mb['password_hash']) : '';
            // Per-mailbox backoff (sweep M8): fail2ban only bans one IP at a
            // time, so distributed guessing against one mailbox would never
            // trip it. Delay only — a lockout would let an attacker take the
            // victim's calendar offline. Bounded to 2 s so a phone retrying
            // with a stale password cannot pin DAV workers.
            $wait = Store::throttleMicros(Store::authFails($a));
            if ($wait > 0) usleep($wait);
            if ($mb && $hash !== '' && password_verify((string)$password, $hash)
                && $mb['status'] === 'active' && $mb['mail_handling'] === 'local') {
                $ok = true;
                $this->address = $a;
                Store::bind($a, (int)$mb['id']);
                Store::clearAuthFails($a);
            } elseif (!$mb) {
                // same bcrypt cost for unknown addresses: no timing oracle for which mailboxes exist
                // nosemgrep: generic.secrets.security.detected-bcrypt-hash.detected-bcrypt-hash -- dummy hash for constant-time verify, not a credential
                password_verify((string)$password, '$2y$10$nR/pvggZBtbNY4lRmJMCb.v1hc24KR.yKp9sYVnJJZzPRz1cbOF1W');
            }
        }
        if (!$ok) {
            self::logFail($a);
            // Count only addresses that exist (bounded rows; random noise is
            // already covered by the dummy verify path and fail2ban).
            if ($mb) {
                $n = Store::noteAuthFail($a);
                if ($n === 10) self::logThrottled($a, $n); // one line per window: visibility for distributed attacks
            }
        }
        return $ok;
    }

    private static function logThrottled(string $user, int $fails): void
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if (!filter_var($ip, FILTER_VALIDATE_IP)) $ip = '?';
        @file_put_contents(self::LOG, date('c') . " dav auth throttled user=$user fails=$fails ip=$ip\n", FILE_APPEND | LOCK_EX);
    }

    private static function logFail(string $user): void
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return;
        $user = preg_replace('/[^a-z0-9@._-]/', '?', substr($user, 0, 80));
        @file_put_contents(self::LOG, date('c') . " dav auth failed user=$user ip=$ip\n", FILE_APPEND | LOCK_EX);
    }
}
