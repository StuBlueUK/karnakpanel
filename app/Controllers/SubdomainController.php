<?php
declare(strict_types=1);
// Subdomains: a domain row whose name ends in ".<another panel domain of the
// same owner>". Site side = its own docroot + vhost (subdomain-add.sh). DNS
// side = one A record in the PARENT zone — never a zone of its own.
class SubdomainController extends Controller {
    public function index(): void {
        // folded into the Domains page (Subdomains tab) — keep the route so
        // old links / bookmarks land in the right place.
        Helpers::redirect('/domains?tab=subdomains');
    }
    public function create(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/domains?tab=subdomains'); }
        $u = Auth::user();
        $label = strtolower(trim((string)($_POST['subdomain'] ?? '')));
        $parentName = strtolower(trim((string)($_POST['parent_domain'] ?? '')));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $label)) {
            Helpers::flash('err','Subdomain must be lowercase letters, digits or hyphens (1-63 chars)'); Helpers::redirect('/domains?tab=subdomains');
        }
        $parent = Database::query("SELECT * FROM domains WHERE domain=?", [$parentName])->fetch();
        if (!$parent || ($u['role'] !== 'admin' && (int)$parent['user_id'] !== (int)$u['id'])) {
            Helpers::flash('err','Parent domain not found'); Helpers::redirect('/domains?tab=subdomains');
        }
        $full = $label.'.'.$parent['domain'];
        if (Database::query("SELECT id FROM domains WHERE domain=?", [$full])->fetch()) {
            Helpers::flash('err',"$full already exists"); Helpers::redirect('/domains?tab=subdomains');
        }
        $owner = Database::query("SELECT * FROM users WHERE id=?", [$parent['user_id']])->fetch();
        if ($err = Helpers::limitError($owner, 'domains')) { Helpers::flash('err', $err); Helpers::redirect('/domains?tab=subdomains'); }
        $php = PhpVersions::orDefault($parent['php_version'] ?? null);
        try {
            [$domId, $ip, $out] = DomainProvisioner::createSub($owner, $parent, $label, $php);
            $msg = "Subdomain $full added (A $label → $ip in $parentName). ".$out;
            // parent already on LE = its DNS reaches this box, and the new A
            // record lives in that zone — try the cert now. Non-fatal: the
            // row keeps an Issue SSL button for when DNS settles.
            if (!empty($parent['ssl_enabled']) && ($email = SslController::acmeEmail((int)$owner['id'])) !== null) {
                $sr = ServiceManager::run('ssl-issue.sh', [$full, $email]);
                if (($sr['code'] ?? 1) === 0) {
                    Database::query("UPDATE domains SET ssl_enabled=1 WHERE id=?", [$domId]);
                    try { ServiceManager::run('expiry-check.sh', [$full]); } catch (Throwable $e) {}
                    $msg .= ' SSL issued.';
                } else {
                    $msg .= ' SSL not issued yet (DNS may still be settling) — use 🔒 Issue SSL on its row.';
                    Helpers::log("subdomain ssl for $full: ".trim($sr['out'] ?? ''));
                }
            }
            Helpers::flash('ok', $msg);
        } catch (Throwable $e) {
            Helpers::flash('err','Failed: '.$e->getMessage());
        }
        Helpers::redirect('/domains?tab=subdomains');
    }
    public function delete(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/domains?tab=subdomains'); }
        $u = Auth::user();
        $id = (int)($_POST['id'] ?? 0);
        $d = Database::query("SELECT d.*, u.username FROM domains d JOIN users u ON u.id=d.user_id WHERE d.id=?", [$id])->fetch();
        if (!$d || ($u['role'] !== 'admin' && (int)$d['user_id'] !== (int)$u['id'])) {
            Helpers::flash('err','Subdomain not found'); Helpers::redirect('/domains?tab=subdomains');
        }
        $parent = DomainModel::parentOf($d['domain'], (int)$d['user_id']);
        if (!$parent) { Helpers::flash('err','Not a subdomain'); Helpers::redirect('/domains?tab=subdomains'); }
        if (($_POST['confirm'] ?? '') !== 'DELETE') { Helpers::flash('err','Type DELETE to confirm'); Helpers::redirect('/domains?tab=subdomains'); }
        // mail first: its MX/SPF/DKIM live in the parent zone and the
        // mailboxes on disk would be orphaned — refused before anything is
        // touched, so the site stays whole
        $mail = [];
        foreach (['email_accounts' => 'mailbox', 'email_forwards' => 'forwarder', 'email_lists' => 'list'] as $tb => $what) {
            $n = (int)Database::query("SELECT COUNT(*) c FROM $tb WHERE domain_id=?", [$d['id']])->fetch()['c'];
            if ($n) $mail[] = "$n $what".($n > 1 ? ($what === 'mailbox' ? 'es' : 's') : '');
        }
        if ($mail) { Helpers::flash('err', "{$d['domain']} still has ".implode(', ', $mail)." — remove them on the Email page first."); Helpers::redirect('/domains?tab=subdomains'); }
        $r = ServiceManager::run('subdomain-remove.sh', [$d['domain'], $d['username']]);
        if (($r['code'] ?? 1) !== 0) { Helpers::flash('err', trim($r['out'] ?? 'remove refused')); Helpers::redirect('/domains?tab=subdomains'); }
        try {
            // every record at the label and under it in the parent zone (A,
            // www, mail, MX/SPF, _dmarc, DKIM, mta-sts — not just the A),
            // except names of other subdomains below this one; applies
            $label = substr($d['domain'], 0, -strlen('.'.$parent['domain']));
            Database::query("DELETE FROM dns_records WHERE domain_id=? AND host=? AND type='A'", [$parent['id'], $label]);
            $n = MailDns::purgeChild($d);
            Database::query("DELETE FROM dns_records WHERE domain_id=?", [$d['id']]);
            MailAutopilot::purgeDomain((int)$d['id']);
            DomainModel::purgeRows((int)$d['id']);
            Database::query("DELETE FROM domains WHERE id=?", [$d['id']]);
            // purgeChild re-renders only when it removed something
            if (!$n) (new DnsController())->apply((int)$parent['id'], $parent['domain']);
            // a sub added as a domain before its parent existed has its own
            // zone; drop it or it lingers orphaned (no-op when there is none)
            ServiceManager::run('dns-remove-zone.sh', [$d['domain']]);
            // after the row is gone (ssl-remove refuses while it exists):
            // an orphaned cert fails renewal nightly against dead DNS
            if (!empty($d['ssl_enabled'])) ServiceManager::run('ssl-remove.sh', [$d['domain']]);
            Helpers::flash('ok',"Subdomain {$d['domain']} removed.");
        } catch (Throwable $e) { Helpers::flash('err','Removed site, DNS cleanup failed: '.$e->getMessage()); }
        Helpers::redirect('/domains?tab=subdomains');
    }
}
