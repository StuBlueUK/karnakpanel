<?php
declare(strict_types=1);
class EmailForwardController extends Controller {
    public function createForward(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $u = Auth::user();
        $local = strtolower(trim($_POST['flocal'] ?? ''));
        $domainId = (int)($_POST['domain_id'] ?? 0);
        $targets = trim((string)($_POST['targets'] ?? ''));
        if ((!Helpers::localpartOk($local) && $local !== '*') || $targets === '') { Helpers::flash('err','Bad forwarder'); Helpers::redirect('/email'); }
        $d = EmailModel::domain($domainId, (int)$u['id']);
        if (!$d) { Helpers::flash('err','Domain not found'); Helpers::redirect('/email'); }
        if (MailPolicyController::reserved((int)$d['id'], $local)) { Helpers::flash('err', "$local@{$d['domain']} receives DMARC/TLS reports here — turn report intake off first to use it"); Helpers::redirect('/email'); }
        // "keep a copy": the source listed in its own targets — Exim skips
        // this redirect for that child, so it lands in the mailbox (and any
        // autoreply/filters still run). Needs a real mailbox at the address.
        $self = $local.'@'.$d['domain'];
        $keep = !empty($_POST['keep']);
        if ($keep && $local === '*') { Helpers::flash('err','A catch-all cannot keep a copy (there is no mailbox called *)'); Helpers::redirect('/email'); }
        // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string -- prepared query; the interpolated string is flash text
        if ($keep && !EmailModel::mailboxExists($domainId, $local)) { Helpers::flash('err',"Keep a copy needs a mailbox $self — create it first"); Helpers::redirect('/email'); }
        $clean = [];
        foreach (preg_split('/[\s,;]+/', $targets, -1, PREG_SPLIT_NO_EMPTY) as $t) {
            $t = strtolower(trim($t));
            if (!filter_var($t, FILTER_VALIDATE_EMAIL)) { Helpers::flash('err',"Bad target: $t"); Helpers::redirect('/email'); }
            if (str_contains($t, '|') || str_contains($t, '/')) { Helpers::flash('err','Pipes/paths refused'); Helpers::redirect('/email'); }
            if ($t === $self) continue; // only via "keep a copy"
            $clean[] = $t;
        }
        $clean = array_values(array_unique($clean));
        if (!$clean) { Helpers::flash('err','Add at least one address other than the forwarder itself (tick "keep a copy" to also deliver here)'); Helpers::redirect('/email'); }
        if (count($clean) > 50) { Helpers::flash('err','At most 50 targets — use a mailing list for more'); Helpers::redirect('/email'); }
        if ($keep) $clean[] = $self;
        $joined = implode(', ', $clean);
        // an edit that the script refuses must leave the old forwarder intact
        $prev = EmailModel::forwardTargetsAt($domainId, $local);
        EmailModel::upsertForward((int)$u['id'], $domainId, $local, $joined);
        $r = ServiceManager::run('forward-add.sh', [$self, $joined]);
        if (($r['code'] ?? 1) !== 0) {
            if ($prev !== null) EmailModel::restoreForwardTargets($domainId, $local, $prev);
            else EmailModel::deleteForwardAt($domainId, $local);
            Helpers::flash('err','Forwarder refused: '.trim($r['out']));
        }
        else Helpers::flash('ok', "Forwarder $self → ".implode(', ', array_diff($clean, [$self])).($keep ? ' (copy kept here)' : '').'.'.($prev !== null ? '' : MailDns::ensureInbound($d)));
        Helpers::redirect('/email');
    }
    public function deleteForward(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $u = Auth::user();
        $id = (int)($_POST['id'] ?? 0);
        $row = EmailModel::forward($id, (int)$u['id']);
        if (!$row) { Helpers::flash('err','Forwarder not found'); Helpers::redirect('/email'); }
        EmailModel::deleteForward($id);
        ServiceManager::run('forward-remove.sh', [$row['localpart'].'@'.$row['domain']]);
        Helpers::flash('ok', "Forwarder {$row['localpart']}@{$row['domain']} removed");
        Helpers::redirect('/email');
    }
}
