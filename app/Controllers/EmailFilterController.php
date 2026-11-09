<?php
declare(strict_types=1);
class EmailFilterController extends Controller {
    private function ownList(array $u, int $id): ?array {
        return EmailModel::listRow($u, $id);
    }
    // Filters: field subject/from/to/cc matched contains|is|starts, or
    // spam = spamd score of at least N (the X-Spam-Score bar, so negative
    // scores never match). Posting an id edits that filter in place.
    public function filterCreate(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $u = Auth::user();
        $box = EmailModel::box($u, (int)($_POST['mailbox_id'] ?? 0));
        if (!$box) { Helpers::flash('err','Mailbox not found'); Helpers::redirect('/email'); }
        $editId = (int)($_POST['id'] ?? 0);
        $old = null;
        if ($editId > 0) {
            $old = EmailModel::filter($editId);
            if (!$old || ($u['role'] !== 'admin' && (int)$old['user_id'] !== (int)$u['id'])) { Helpers::flash('err','Filter not found'); Helpers::redirect('/email'); }
        }
        $field = strtolower(trim((string)($_POST['field'] ?? '')));
        $mtype = strtolower(trim((string)($_POST['match_type'] ?? 'contains')));
        $match = trim((string)($_POST['match'] ?? ''));
        $action = strtolower(trim((string)($_POST['action'] ?? '')));
        $target = trim((string)($_POST['target'] ?? ''));
        if (!in_array($field, ['subject','from','to','cc','spam'], true)) { Helpers::flash('err','Bad field'); Helpers::redirect('/email'); }
        if ($field === 'spam') {
            if (!preg_match('/^[0-9]{1,2}$/', $match) || (int)$match < 1 || (int)$match > 30) { Helpers::flash('err','Spam score: a whole number 1..30'); Helpers::redirect('/email'); }
            $match = (string)(int)$match; $mtype = 'ge';
        } else {
            if (!in_array($mtype, ['contains','is','starts'], true)) { Helpers::flash('err','Bad match type'); Helpers::redirect('/email'); }
            if ($match === '' || mb_strlen($match) > 160 || preg_match('/[\r\n]/', $match)) { Helpers::flash('err','Match text 1..160 chars, one line'); Helpers::redirect('/email'); }
        }
        if (!in_array($action, ['folder','discard','redirect'], true)) { Helpers::flash('err','Bad action'); Helpers::redirect('/email'); }
        if ($action === 'folder' && !preg_match('/^[A-Za-z0-9_-]{1,64}(\.[A-Za-z0-9_-]{1,64})*$/', $target)) { Helpers::flash('err','Folder: letters/digits/._-, dot-separated nesting ok'); Helpers::redirect('/email'); }
        if ($action === 'redirect' && (!filter_var($target, FILTER_VALIDATE_EMAIL) || str_contains($target, '|') || str_contains($target, '/'))) { Helpers::flash('err','Bad redirect target'); Helpers::redirect('/email'); }
        if ($action === 'discard') $target = '';
        if ($old) {
            EmailModel::updateFilter((int)$old['id'], (int)$box['domain_id'], $box['localpart'], $field, $mtype, $match, $action, $target);
        } else {
            EmailModel::insertFilter((int)$u['id'], (int)$box['domain_id'], $box['localpart'], $field, $mtype, $match, $action, $target);
        }
        $r = ServiceManager::run('email-filters-sync.sh', []);
        $desc = $field === 'spam' ? "spam score ≥ $match" : "$field $mtype \"$match\"";
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Saved but sync failed: '.trim($r['out']));
        else Helpers::flash('ok', ($old ? 'Filter updated' : 'Filter added')." on {$box['localpart']}@{$box['domain']}: $desc → $action");
        Helpers::redirect('/email');
    }
    public function filterToggle(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $u = Auth::user();
        $row = EmailModel::filter((int)($_POST['id'] ?? 0));
        if (!$row || ($u['role'] !== 'admin' && (int)$row['user_id'] !== (int)$u['id'])) { Helpers::flash('err','Filter not found'); Helpers::redirect('/email'); }
        $on = (int)$row['active'] === 1 ? 0 : 1;
        EmailModel::setFilterActive((int)$row['id'], $on);
        $r = ServiceManager::run('email-filters-sync.sh', []);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Saved but sync failed: '.trim($r['out']));
        else Helpers::flash('ok', $on ? 'Filter enabled' : 'Filter paused (kept, not applied)');
        Helpers::redirect('/email');
    }
    public function filterDelete(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $u = Auth::user();
        $id = (int)($_POST['id'] ?? 0);
        $row = EmailModel::filterWithDomain($id);
        if (!$row || ($u['role'] !== 'admin' && (int)$row['user_id'] !== (int)$u['id'])) {
            Helpers::flash('err','Filter not found'); Helpers::redirect('/email');
        }
        EmailModel::deleteFilter($id);
        ServiceManager::run('email-filters-sync.sh', []);
        Helpers::flash('ok','Filter deleted'); Helpers::redirect('/email');
    }
    public function listCreate(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $u = Auth::user();
        $local = strtolower(trim($_POST['llocal'] ?? ''));
        $domainId = (int)($_POST['domain_id'] ?? 0);
        $d = EmailModel::domain($domainId, (int)$u['id']);
        if (!$d) { Helpers::flash('err','Domain not found'); Helpers::redirect('/email'); }
        if (!Helpers::localpartOk($local)) { Helpers::flash('err','Bad list name'); Helpers::redirect('/email'); }
        if (MailPolicyController::reserved((int)$d['id'], $local)) { Helpers::flash('err', "$local@{$d['domain']} receives DMARC/TLS reports here — turn report intake off first to use it"); Helpers::redirect('/email'); }
        // a list address must be free everywhere (mailbox/forwarder/alias/list)
        if (EmailModel::addressTaken($domainId, $local, $local.'@'.$d['domain'])) { Helpers::flash('err','That address is already used'); Helpers::redirect('/email'); }
        EmailModel::createList((int)$u['id'], $domainId, $local);
        ServiceManager::run('list-sync.sh', []);
        Helpers::flash('ok', "List $local@{$d['domain']} created — add subscribers (only they can post).".MailDns::ensureInbound($d));
        Helpers::redirect('/email');
    }
    public function listDelete(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $u = Auth::user();
        $row = $this->ownList($u, (int)($_POST['id'] ?? 0));
        if (!$row) { Helpers::flash('err','List not found'); Helpers::redirect('/email'); }
        EmailModel::deleteListMembers((int)$row['id']);
        EmailModel::deleteList((int)$row['id']);
        ServiceManager::run('list-sync.sh', []);
        Helpers::flash('ok','List deleted'); Helpers::redirect('/email');
    }
    public function memberAdd(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $u = Auth::user();
        $row = $this->ownList($u, (int)($_POST['id'] ?? 0));
        if (!$row) { Helpers::flash('err','List not found'); Helpers::redirect('/email'); }
        // one or many: paste a column, a comma list, or "Name <a@b>" lines
        $raw = (string)($_POST['address'] ?? '');
        if (strlen($raw) > 20000) { Helpers::flash('err','Too much text (paste at most 200 addresses)'); Helpers::redirect('/email'); }
        $listAddr = $row['localpart'].'@'.$row['domain'];
        $cands = [];
        foreach (preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $tok) {
            $tok = strtolower(trim($tok, " \t<>\"'()"));
            if ($tok !== '' && str_contains($tok, '@')) $cands[$tok] = true;
        }
        if (!$cands) { Helpers::flash('err','No addresses found'); Helpers::redirect('/email'); }
        $have = [];
        foreach (EmailModel::memberAddresses((int)$row['id']) as $m) $have[$m['address']] = true;
        $added = 0; $skip = ['bad' => 0, 'loop' => 0, 'dupe' => 0, 'full' => 0]; $firstBad = '';
        foreach (array_keys($cands) as $addr) {
            if (!filter_var($addr, FILTER_VALIDATE_EMAIL) || str_contains($addr, '|') || str_contains($addr, '/')) { $skip['bad']++; if ($firstBad === '') $firstBad = $addr; continue; }
            if (isset($have[$addr])) { $skip['dupe']++; continue; }
            if ($addr === $listAddr || EmailModel::listAddressExists($addr)) { $skip['loop']++; continue; }
            if (count($have) >= 200) { $skip['full']++; continue; }
            EmailModel::addMember((int)$row['id'], $addr);
            $have[$addr] = true; $added++;
        }
        if ($added > 0) ServiceManager::run('list-sync.sh', []);
        $why = array_filter(['already in' => $skip['dupe'], 'invalid' => $skip['bad'], 'lists (loop)' => $skip['loop'], 'over the 200 cap' => $skip['full']]);
        $msg = "$added subscribed to $listAddr";
        if ($why) $msg .= ' · skipped '.implode(', ', array_map(fn($k, $v) => "$v $k", array_keys($why), $why)).($firstBad !== '' ? " (e.g. $firstBad)" : '');
        Helpers::flash($added > 0 ? 'ok' : 'err', $msg); Helpers::redirect('/email');
    }
    // List posting cap (list_posts_default, 10/hr): one post fans out per
    // member, so it draws from the list's own bucket, not the sender's.
    public function listDefault(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $raw = trim((string)($_POST['default'] ?? ''));
        if (!preg_match('/^[0-9]{1,6}$/', $raw) || (int)$raw < 1 || (int)$raw > 100000) { Helpers::flash('err','Default 1..100000/hr'); Helpers::redirect('/email'); }
        try {
            EmailModel::putSetting('list_posts_default', $raw);
            Helpers::flash('ok',"List posting default: $raw/hr per list");
        } catch (Throwable $e) {
            try {
                EmailModel::createSettingsTable();
                EmailModel::putSetting('list_posts_default', $raw);
                Helpers::flash('ok',"List posting default: $raw/hr per list");
            } catch (Throwable $e2) { Helpers::flash('err','Save failed: '.$e2->getMessage()); }
        }
        Helpers::redirect('/email');
    }
    public function memberDelete(): void {        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/email'); }
        $u = Auth::user();
        $mid = (int)($_POST['mid'] ?? 0);
        $m = EmailModel::memberWithOwner($mid);
        if (!$m || ($u['role'] !== 'admin' && (int)$m['user_id'] !== (int)$u['id'])) {
            Helpers::flash('err','Member not found'); Helpers::redirect('/email');
        }
        EmailModel::deleteMember($mid);
        ServiceManager::run('list-sync.sh', []);
        Helpers::flash('ok','Member removed'); Helpers::redirect('/email');
    }
}
