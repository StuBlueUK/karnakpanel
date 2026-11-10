<?php
declare(strict_types=1);
// ApiMailController — inherits the API auth/scoping helpers from ApiController.
class ApiMailController extends ApiController {
    public function mailList(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $in = array_merge($this->apiInput(), $_GET);
        $t = $this->resolveTarget($me, (string)($in['username'] ?? $me['username']));
        if (!empty($in['domain'])) {
            $d = $this->resolveDomain($t, (string)$in['domain']);
            $acc = EmailModel::mailboxesForDomain((int)$d['id']);
            $fwd = EmailModel::forwardsForDomain((int)$d['id']);
        } else {
            $acc = EmailModel::mailboxesForUser((int)$t['id']);
            $fwd = EmailModel::forwardsForUser((int)$t['id']);
        }
        $this->json(['ok' => true, 'username' => $t['username'], 'accounts' => $acc, 'forwards' => $fwd]);
    }
    public function mailCreate(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $in = $this->apiInput();
        $t = $this->resolveTarget($me, (string)($in['username'] ?? $me['username']));
        $local = trim((string)($in['local'] ?? ''));
        $pass = (string)($in['password'] ?? '');
        if (!Helpers::localpartOk($local, true) || strlen($pass) < 8) $this->json(['error' => 'bad email/password (local-part charset, pass 8+ chars)'], 422);
        $d = $this->resolveMailDomain($t, $in['domain_id'] ?? 0);
        if (($d['mail_handling'] ?? 'local') === 'remote') $this->json(['error' => 'mail for '.$d['domain'].' routes externally — switch it to Local first'], 422);
        if ($err = Quota::limitError($t, 'emails')) $this->json(['error' => $err], 422);
        if ($err = Quota::blocked($t)) $this->json(['error' => $err], 422);
        try {
            $hash = password_hash($pass, PASSWORD_BCRYPT);
            EmailModel::createMailbox((int)$t['id'], (int)$d['id'], $local, $hash, 1024);
            ServiceManager::run('email-add.sh', [$local.'@'.$d['domain'], $t['username']]);
            Helpers::log("API mailbox $local@{$d['domain']} created by {$me['username']}");
            $this->json(['ok' => true, 'mailbox' => $local.'@'.$d['domain']]);
        } catch (Throwable $e) { $this->apiFailed('create', 500, $e); }
    }
    public function mailDelete(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $in = $this->apiInput();
        $t = $this->resolveTarget($me, (string)($in['username'] ?? $me['username']));
        $id = (int)($in['id'] ?? 0);
        $row = EmailModel::mailboxForOwner((int)$t['id'], $id);
        if (!$row) $this->json(['error' => 'mailbox not found'], 404);
        try {
            EmailModel::deleteMailbox((int)$row['id']);
            EmailModel::deleteFiltersAt((int)$row['domain_id'], $row['localpart']);
            EmailModel::deleteImportsAt((int)$row['domain_id'], $row['localpart']);
            ServiceManager::run('email-remove.sh', [$row['localpart'].'@'.$row['domain']]);
            Helpers::log("API mailbox {$row['localpart']}@{$row['domain']} deleted by {$me['username']}");
            $this->json(['ok' => true, 'deleted' => $row['localpart'].'@'.$row['domain']]);
        } catch (Throwable $e) { $this->apiFailed('delete', 500, $e); }
    }
    public function mailForward(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $in = $this->apiInput();
        $t = $this->resolveTarget($me, (string)($in['username'] ?? $me['username']));
        $local = strtolower(trim((string)($in['local'] ?? '')));
        $targets = trim((string)($in['targets'] ?? ''));
        if ((!Helpers::localpartOk($local) && $local !== '*') || $targets === '') $this->json(['error' => 'bad forwarder (local charset, non-empty targets)'], 422);
        $d = $this->resolveMailDomain($t, $in['domain_id'] ?? 0);
        $clean = [];
        foreach (explode(',', $targets) as $tg) {
            $tg = strtolower(trim($tg));
            if (!filter_var($tg, FILTER_VALIDATE_EMAIL)) $this->json(['error' => "bad target: $tg"], 422);
            if (str_contains($tg, '|') || str_contains($tg, '/')) $this->json(['error' => 'pipes/paths refused'], 422);
            if ($tg === $local.'@'.$d['domain']) $this->json(['error' => 'self-forward refused (loop)'], 422);
            $clean[] = $tg;
        }
        $joined = implode(', ', array_unique($clean));
        EmailModel::upsertForward((int)$t['id'], (int)$d['id'], $local, $joined);
        $r = ServiceManager::run('forward-add.sh', [$local.'@'.$d['domain'], $joined]);
        if (($r['code'] ?? 1) !== 0) {
            EmailModel::deleteForwardAt((int)$d['id'], $local);
            $this->apiFailed('forwarder', 422, null, $r['out']);
        }
        Helpers::log("API forwarder $local@{$d['domain']} set by {$me['username']}");
        $this->json(['ok' => true, 'forwarder' => $local.'@'.$d['domain'], 'targets' => $joined]);
    }
    public function mailForwardDelete(): void {
        $me = $this->tokenAuth();
        if (!$me || !in_array($me['role'], ['admin','reseller','user'])) $this->json(['error' => 'unauthorized'], 401);
        $in = $this->apiInput();
        $t = $this->resolveTarget($me, (string)($in['username'] ?? $me['username']));
        $id = (int)($in['id'] ?? 0);
        $row = EmailModel::forward($id, (int)$t['id']);
        if (!$row) $this->json(['error' => 'forwarder not found'], 404);
        EmailModel::deleteForward($id);
        ServiceManager::run('forward-remove.sh', [$row['localpart'].'@'.$row['domain']]);
        Helpers::log("API forwarder {$row['localpart']}@{$row['domain']} removed by {$me['username']}");
        $this->json(['ok' => true, 'deleted' => $row['localpart'].'@'.$row['domain']]);
    }
    // ---- databases ----
}
