<?php
declare(strict_types=1);

class AdminServiceController extends Controller {

    // services that may be stopped from the page: nothing the panel, sites,
    // mail delivery, DNS or remote access depend on
    public const STOPPABLE = ['vsftpd', 'spamassassin', 'clamav'];
    public function services(): void {
        Auth::requireRole(['admin']);
        $services = ServiceManager::details();
        $up = (float)(explode(' ', (string)@file_get_contents('/proc/uptime'))[0] ?? 0);
        $uptime = ServiceManager::ago((int)$up);
        $alertEmail = '';
        try { $alertEmail = (string)(Settings::get('alert_email') ?? ''); } catch (Throwable $e) {}
        $alertDead = $alertEmail !== '' && self::alertUnroutable($alertEmail);
        $panelDomain = '';
        try { $panelDomain = (string)(Settings::get('panel_domain') ?? ''); } catch (Throwable $e) {}
        // passkeys are bound to the panel host: changing it strands them
        $pkOnHost = 0;
        try { $pkOnHost = WebAuthn::countForHost($panelDomain); } catch (Throwable $e) {}
        // the cert :8090 really serves (one local TLS handshake, no privileges):
        // the dropdown shows THAT, and a panel host it doesn't cover is flagged
        // (browsers refuse passkeys on a certificate error)
        $svcCert = self::servedCert($panelDomain);
        $hostCovered = $panelDomain === '' || $svcCert === null || self::certCovers($svcCert['names'], strtolower($panelDomain));
        // watchdog liveness: stale heartbeat = cron (or the box) is in trouble
        $watchdogAge = null; $heartbeat = '';
        try {
            $r = ServiceManager::run('watchdog-status.sh', []);
            if (($r['code'] ?? 1) === 0 && preg_match('/^age=(\d+)$/', trim($r['out'] ?? ''), $m)) $watchdogAge = (int)$m[1];
        } catch (Throwable $e) {}
        try { $heartbeat = (string)(Settings::get('watchdog_heartbeat') ?? ''); } catch (Throwable $e) {}
        $malwareOn = '1'; $malwareHour = '3';
        try {
            $v = Settings::get('malware_enabled');
            if ($v !== null) $malwareOn = $v;
            $h = Settings::get('malware_hour');
            if ($h !== null && preg_match('/^[0-9]{1,2}$/', $h)) $malwareHour = (string)(int)$h;
        } catch (Throwable $e) {}
        $this->view('admin_services', compact('services','uptime','alertEmail','alertDead','panelDomain','pkOnHost','malwareOn','malwareHour','watchdogAge','heartbeat','svcCert','hostCovered') + ['ssldoms' => DomainModel::sslEnabledList()]);
    }

    // Panel host (this box's own domain, e.g. panel.example.com). Powers the
    // setup-checklist glue step: the nightly dns-glue-check probes glue for
    // the panel's nameservers only once it knows which domain is this box's.
    // names on the certificate the panel listener serves; null when unreadable
    private static function servedCert(string $sni): ?array {
        $port = (int)($_SERVER['SERVER_PORT'] ?? 8090);
        if ($port < 1 || $port > 65535) $port = 8090;
        $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false,
            'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $sni !== '' ? $sni : 'localhost']]);
        $c = @stream_socket_client("ssl://127.0.0.1:$port", $en, $es, 2, STREAM_CLIENT_CONNECT, $ctx);
        if (!$c) return null;
        $cert = stream_context_get_params($c)['options']['ssl']['peer_certificate'] ?? null;
        fclose($c);
        $x = $cert ? openssl_x509_parse($cert) : false;
        if (!$x) return null;
        $names = [];
        foreach (explode(',', (string)($x['extensions']['subjectAltName'] ?? '')) as $n) {
            $n = strtolower(trim($n));
            if (str_starts_with($n, 'dns:')) $names[] = substr($n, 4);
        }
        $cn = strtolower((string)($x['subject']['CN'] ?? ''));
        if (!$names && $cn !== '') $names[] = $cn;
        return ['cn' => $cn, 'names' => $names, 'self' => ($x['subject'] ?? []) == ($x['issuer'] ?? [])];
    }

    private static function certCovers(array $names, string $host): bool {
        foreach ($names as $n) {
            if ($n === $host) return true;
            if (str_starts_with($n, '*.') && substr_count($host, '.') >= 2 && substr($host, strpos($host, '.')) === substr($n, 1)) return true;
        }
        return false;
    }

    public function panelDomain(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/services'); }
        $dom = strtolower(trim((string)($_POST['panel_domain'] ?? '')));
        try {
            if ($dom === '') {
                Settings::drop('panel_domain');
                Helpers::flash('ok','Panel host cleared — the checklist glue step stays TODO until you set it');
            } else {
                if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $dom)) {
                    Helpers::flash('err','Give a real domain (letters, digits, dots, hyphens)'); Helpers::redirect('/admin/services');
                }
                $was = strtolower(trim((string)(Settings::get('panel_domain') ?? '')));
                $stranded = 0;
                if ($was !== '' && $was !== $dom) {
                    try { $stranded = WebAuthn::countForHost($was); } catch (Throwable $e2) {}
                }
                Settings::put('panel_domain', $dom);
                try { ServiceManager::run('cron-glue.sh', ['on']); } catch (Throwable $e2) {}
                if ($stranded > 0) Helpers::log("panel host $was -> $dom: $stranded passkey(s) no longer usable");
                Helpers::flash('ok',"Panel host set to $dom — glue is re-checked nightly".($stranded > 0 ? ". $stranded passkey(s) made for $was stop working: owners use their app code or need a 2FA reset, then add new ones" : ''));
            }
        } catch (Throwable $e) {
            try {
                Settings::ensureTable();
                if ($dom === '') Settings::drop('panel_domain');
                else Settings::put('panel_domain', $dom);
                Helpers::flash('ok','Panel host saved');
            } catch (Throwable $e2) { Helpers::flash('err','Save failed: '.$e2->getMessage()); }
        }
        Helpers::redirect('/admin/services');
    }

    public function restart(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/services'); }
        $units = ServiceManager::units();
        $act = (string)($_POST['action'] ?? 'restart');
        if (!in_array($act, ['restart', 'reload', 'start', 'stop'], true)) $act = 'restart';
        $svcs = [];
        if (($_POST['service'] ?? '') === '*down') {
            foreach (ServiceManager::details() as $k => $v) if ($v['state'] === 'down') $svcs[] = $k;   // never "no answer"
            $act = 'restart';
            if (!$svcs) { Helpers::flash('ok','Everything is already running'); Helpers::redirect('/admin/services'); }
        } else {
            $svc = (string)($_POST['service'] ?? '');
            if (!isset($units[$svc])) { Helpers::flash('err','Unknown service'); Helpers::redirect('/admin/services'); }
            if ($act === 'stop' && !in_array($svc, self::STOPPABLE, true)) { Helpers::flash('err',"$svc can't be stopped from here — restart it instead"); Helpers::redirect('/admin/services'); }
            $svcs[] = $svc;
        }
        $msg = []; $bad = false;
        foreach ($svcs as $svc) {
            $r = ServiceManager::run('service-control.sh', [$act, $svc], 60);
            $state = trim((string)preg_replace('/\s+/', ' ', (string)($r['out'] ?? '')));
            $ok = $act === 'stop' ? true : (($r['code'] ?? 1) === 0 && str_ends_with($state, 'active') && !str_ends_with($state, 'inactive'));
            $bad = $bad || !$ok;
            $msg[] = "$svc: ".($ok ? ['restart' => 'restarted', 'reload' => 'reloaded', 'start' => 'started', 'stop' => 'stopped'][$act] : 'failed — '.($state !== '' ? $state : 'no output'));
            Helpers::log("service $act $svc by ".Auth::user()['username'].($ok ? '' : ' (failed)'));
        }
        Helpers::flash($bad ? 'err' : 'ok', implode(' · ', $msg));
        Helpers::redirect('/admin/services');
    }

    public function servicesLive(): void {
        Auth::requireRole(['admin']);
        $this->json(['at' => time(), 'services' => ServiceManager::details()]);
    }

    // Watchdog heartbeat URL (external dead-man monitor). Blank = off.
    public function watchdogHeartbeat(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/services'); }
        $url = trim((string)($_POST['url'] ?? ''));
        if ($url !== '' && !preg_match('#^https://[a-zA-Z0-9.-]+(:[0-9]{1,5})?(/[a-zA-Z0-9_./?=&%-]*)?$#', $url)) {
            Helpers::flash('err','Bad URL (https only)'); Helpers::redirect('/admin/services');
        }
        try {
            if ($url === '') Settings::drop('watchdog_heartbeat');
            else Settings::put('watchdog_heartbeat', $url);
            Helpers::log("watchdog heartbeat ".($url === '' ? 'cleared' : 'set')." by ".Auth::user()['username']);
            Helpers::flash('ok', $url === '' ? 'External heartbeat off' : 'Heartbeat on — the monitor should see pings within 5 minutes');
        } catch (Throwable $e) { Helpers::flash('err','Save failed: '.$e->getMessage()); }
        Helpers::redirect('/admin/services');
    }

    // An address on a domain this box hosts (mail handled here) with no
    // mailbox, forwarder or list: Exim refuses it, so the watchdog's alerts
    // and root's forwarded system mail bounce and freeze in the queue.
    private static function alertUnroutable(string $email): bool {
        $at = strrpos($email, '@');
        if ($at === false) return false;
        $lp = substr($email, 0, $at); $dn = substr($email, $at + 1);
        try {
            $d = DomainModel::findByName($dn);
            if (!$d || $d['mail_handling'] !== 'local') return false;
            foreach (['email_accounts', 'email_forwards', 'email_lists'] as $t) {
                if (MailPolicyModel::localpartTaken((int)$d['id'], $lp, $t)) return false;
            }
            return true;
        } catch (Throwable $e) { return false; }
    }

    public function alertEmail(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/admin/services'); }
        $email = strtolower(trim((string)($_POST['alert_email'] ?? '')));
        try {
            if ($email === '') {
                Settings::drop('alert_email');
                Helpers::flash('ok','Alert email cleared — watchdog falls back to admin account emails');
            } else {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/@[^@]+\.[a-z]{2,}$/i', $email) || str_ends_with($email, '@localhost')) {
                    Helpers::flash('err','Give a real routable email (not admin@localhost)'); Helpers::redirect('/admin/services');
                }
                Settings::put('alert_email', $email);
                // system mail (root@, cron failures) must reach a human: point
                // the box's root forwarder at the alert address (proven: bare
                // root otherwise dies in local_delivery as uid 0).
                try {
                    $mn = trim(ServiceManager::run('mail-name.sh', [])['out'] ?? '');
                    if ($mn !== '') ServiceManager::run('forward-add.sh', ["root@$mn", $email]);
                } catch (Throwable $e2) {}
                if (self::alertUnroutable($email)) Helpers::flash('err',"Saved, but $email has no mailbox or forwarder on this server — alerts and root's system mail will bounce. Add it under Email, or use an outside address.");
                else Helpers::flash('ok',"Downtime alerts will go to $email");
            }
        } catch (Throwable $e) {
            // old installs may lack the settings table — create it on demand
            try {
                Settings::ensureTable();
                if ($email === '') Settings::drop('alert_email');
                else Settings::put('alert_email', $email);
                Helpers::flash('ok','Alert email saved');
            } catch (Throwable $e2) { Helpers::flash('err','Save failed: '.$e2->getMessage()); }
        }
        Helpers::redirect('/admin/services');
    }
}
