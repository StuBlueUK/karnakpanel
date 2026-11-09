<?php
declare(strict_types=1);
class EmailImportController extends Controller {
    public function importPage(): void {
        Auth::requireLogin();
        $box = EmailModel::box(Auth::user(), (int)($_GET['id'] ?? 0));
        if (!$box) { Helpers::flash('err','Mailbox not found'); Helpers::redirect('/email'); }
        try { EmailModel::purgeOldImports(); } catch (Throwable $e) {}
        $sup = ServiceManager::run('mail-import.sh', ['supported']);
        $jobs = EmailModel::importsFor((int)$box['domain_id'], $box['localpart']);
        $this->view('email_import', ['box' => $box, 'jobs' => $jobs, 'supported' => ($sup['code'] ?? 1) === 0, 'why' => trim((string)($sup['out'] ?? ''))]);
    }
    public function importStart(): void {
        Auth::requireLogin();
        $id = (int)($_POST['id'] ?? 0); $back = '/email/import?id='.$id;
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect($back); }
        $u = Auth::user();
        $box = EmailModel::box($u, $id);
        if (!$box) { Helpers::flash('err','Mailbox not found'); Helpers::redirect('/email'); }
        $host = strtolower(trim((string)($_POST['host'] ?? '')));
        $port = (int)($_POST['port'] ?? 993);
        $ruser = trim((string)($_POST['remote_user'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');
        if ($port !== 993 && $port !== 143) { Helpers::flash('err','Port must be 993 (TLS) or 143 (STARTTLS)'); Helpers::redirect($back); }
        if (!MailImport::validRemoteUser($ruser)) { Helpers::flash('err','Old-server username: letters, digits and . _ + = @ - only'); Helpers::redirect($back); }
        if ($pass === '' || strlen($pass) > 256 || preg_match('/[\r\n\0]/', $pass)) { Helpers::flash('err','Password: 1-256 characters on one line'); Helpers::redirect($back); }
        if (($e = MailImport::hostError($host)) !== '') { Helpers::flash('err', $e); Helpers::redirect($back); }
        // rows belong to the box owner (purged with that account; an admin
        // importing on their behalf spends the owner's hourly allowance)
        $owner = EmailModel::user((int)$box['user_id']) ?: $u;
        if ($err = Quota::blocked($owner)) { Helpers::flash('err', $err); Helpers::redirect($back); }
        $n = EmailModel::importCount((int)$box['user_id']);
        if ($n >= MailImport::PER_HOUR) { Helpers::flash('err','At most '.MailImport::PER_HOUR.' import attempts per hour — try again later'); Helpers::redirect($back); }
        $job = bin2hex(random_bytes(8));
        $addr = $box['localpart'].'@'.$box['domain'];
        EmailModel::addImport($job, (int)$box['user_id'], (int)$box['domain_id'], $box['localpart'], $host, $port, $ruser);
        $r = ServiceManager::run('mail-import.sh', ['start', $job, $addr, $host, (string)$port, $ruser, new Secret($pass)], 120);
        $out = trim((string)($r['out'] ?? ''));
        if (($r['code'] ?? 1) !== 0) {
            // the script records its own refusals; one before it could (sudo, timeout) must not sit at 'checking'
            EmailModel::failImport($job, $out);
            Helpers::flash('err', 'Import not started: '.$out);
        } else Helpers::flash('ok', $out);
        Helpers::log("mail import into $addr from $host requested by {$u['username']}: ".(($r['code'] ?? 1) === 0 ? 'started' : 'refused'));
        Helpers::redirect($back);
    }
    public function importCancel(): void {
        Auth::requireLogin();
        $id = (int)($_POST['id'] ?? 0); $back = '/email/import?id='.$id;
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect($back); }
        $box = EmailModel::box(Auth::user(), $id);
        $job = (string)($_POST['job'] ?? '');
        if (!$box || !preg_match('/^[a-f0-9]{16}$/', $job)) { Helpers::flash('err','Import not found'); Helpers::redirect($back); }
        $row = EmailModel::importJob($job, (int)$box['domain_id'], $box['localpart']);
        if (!$row) { Helpers::flash('err','Import not found'); Helpers::redirect($back); }
        ServiceManager::run('mail-import.sh', ['cancel', $job], 20);
        // no process left (reboot, crash): close the row; a live job writes every 2 s, never matches
        EmailModel::closeImport($job);
        Helpers::flash('ok','Import stopped. Mail copied so far is kept.');
        Helpers::redirect($back);
    }
    public function importStatus(): void {
        Auth::requireLogin();
        header('Content-Type: application/json');
        $box = EmailModel::box(Auth::user(), (int)($_GET['id'] ?? 0));
        $job = (string)($_GET['job'] ?? '');
        $r = ($box && preg_match('/^[a-f0-9]{16}$/', $job))
            ? EmailModel::importJob($job, (int)$box['domain_id'], $box['localpart'])
            : null;
        if (!$r) { http_response_code(404); echo json_encode(['error' => 'not found']); exit; }
        // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag, php.lang.security.injection.echoed-request.echoed-request -- JSON_HEX_* flags keep all HTML-significant bytes inert (detail carries remote-server text by design; no HTML context)
        echo json_encode(['stage' => MailImport::stage($r), 'pct' => MailImport::pct($r), 'copied' => (int)$r['copied'], 'total' => (int)$r['total'], 'detail' => $r['detail']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        exit;
    }
}
