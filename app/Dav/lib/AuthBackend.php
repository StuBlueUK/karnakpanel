<?php
// Basic auth against the mailbox passwords (same hashes Dovecot checks:
// bcrypt from the panel, or an imported crypt() hash, optionally prefixed
// with a Dovecot {SCHEME}). Stricter than IMAP on purpose: a suspended
// account, or a domain whose mail is handled elsewhere, gets no DAV login.
// Every wrong password is written to auth.log for the fail2ban [karnak-dav]
// jail (a missing Authorization header is the normal first round-trip of
// every client and is NOT logged).
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
        if (Store::validAddress($a) && $password !== '' && strlen((string)$password) <= 1024) {
            $mb = Store::mailbox($a);
            $hash = $mb ? (string)preg_replace('/^\{[A-Z0-9-]+\}/', '', (string)$mb['password_hash']) : '';
            if ($mb && $hash !== '' && password_verify((string)$password, $hash)
                && $mb['status'] === 'active' && $mb['mail_handling'] === 'local') {
                $ok = true;
                $this->address = $a;
                Store::bind($a, (int)$mb['id']);
            } elseif (!$mb) {
                // same bcrypt cost for unknown addresses: no timing oracle for which mailboxes exist
                // nosemgrep: generic.secrets.security.detected-bcrypt-hash.detected-bcrypt-hash -- dummy hash for constant-time verify, not a credential
                password_verify((string)$password, '$2y$10$nR/pvggZBtbNY4lRmJMCb.v1hc24KR.yKp9sYVnJJZzPRz1cbOF1W');
            }
        }
        if (!$ok) self::logFail($a);
        return $ok;
    }

    private static function logFail(string $user): void
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return;
        $user = preg_replace('/[^a-z0-9@._-]/', '?', substr($user, 0, 80));
        @file_put_contents(self::LOG, date('c') . " dav auth failed user=$user ip=$ip\n", FILE_APPEND | LOCK_EX);
    }
}
