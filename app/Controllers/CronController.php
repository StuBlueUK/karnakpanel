<?php
declare(strict_types=1);
class CronController extends Controller {
    // What each shipped /etc/cron.d/skypanel line does, for the read-only
    // System schedule card: script => [group, name, one-line explanation].
    private const SYSJOBS = [
        'service-watchdog'    => ['Monitoring & alerts', 'Service watchdog', 'Restarts any critical service (web, mail, DNS, database, FTP…) that has stopped, and logs the recovery.'],
        'cron-monitor'        => ['Monitoring & alerts', 'Cron job watch', 'Emails the owner when a watched cron job above stops finishing on time, and again when it recovers.'],
        'push-digest'         => ['Monitoring & alerts', 'Phone alerts', 'Sends urgent server alerts to Telegram when a bot is configured (off by default).'],
        'user-abuse'          => ['Monitoring & alerts', 'Resource abuse board', 'Records CPU, memory and process use per account for the Abuse board; alerts admins about accounts that stay heavy.'],
        'expiry-check'        => ['Monitoring & alerts', 'Expiry watch', 'Warns domain owners when a domain or SSL certificate is 30, 14, 7 or 1 day from expiring.'],
        'dns-glue-check'      => ['Monitoring & alerts', 'Nameserver glue check', 'Checks the panel domain\'s nameserver glue at the registry, for the dashboard checklist.'],
        'dns-delegation-check' => ['Monitoring & alerts', 'Where DNS is served', 'Asks public DNS whether each domain\'s nameservers point here or at the registrar, so the DNS and Email pages can say where records must go.'],
        'blacklist-check'     => ['Monitoring & alerts', 'Blacklist monitor', 'Checks the server\'s mail IPs and hosted domains against Spamhaus, SpamCop, Barracuda and other blacklists, and alerts the admin when a listing appears or clears.'],
        'update-check'        => ['Monitoring & alerts', 'Update check', 'Looks for a newer KarnakPanel release for the dashboard banner (security releases show red).'],
        'fleet-scan'          => ['Monitoring & alerts', 'WordPress fleet scan', 'Records each WordPress install\'s version and whether an update is out, for Fleet Updates. Read-only.'],
        'backup-all'          => ['Backups', 'Nightly backups', 'Backs up every account (sites, mail, databases) and prunes old sets.'],
        'offsite-sync'        => ['Backups', 'Offsite copy', 'Mirrors the night\'s backups to the encrypted offsite store, when one is set up.'],
        'restic-offsite'      => ['Backups', 'Offsite copy (snapshots)', 'Mirrors the incremental snapshot store offsite, when that engine is on.'],
        'restic-forget'       => ['Backups', 'Snapshot retention', 'Weekly: drops snapshots past the retention rules and frees their space.'],
        'restic-check'        => ['Backups', 'Snapshot health check', 'Weekly: checks the snapshot store is intact; a failure alerts like an outage.'],
        'restic-verify'       => ['Backups', 'Restore test', 'Monthly: proves the latest database snapshots really restore (no live data touched).'],
        'malware-scan'        => ['Security', 'Malware scan', 'Antivirus sweep of website files once a day, in the hour chosen on the Services page; quarantines and mails the owner.'],
        'egress-watch'        => ['Security', 'Outbound connection watch', 'Alerts once per new outbound destination an account connects to — catches web shells phoning home.'],
        'integrity-check'     => ['Security', 'File integrity watch', 'Alerts if panel code, its scripts or key mail/firewall/cron config change outside a panel update.'],
        'fpm-harden'          => ['Security', 'PHP pool hardening', 'Weekly: re-applies the locked-down PHP settings to every account\'s PHP pool.'],
        'perms-fix'           => ['Security', 'Panel permissions', 'Keeps the panel\'s code and credentials owned by root and unwritable by anyone else.'],
        'home-perms'          => ['Security', 'Account isolation', 'Closes each hosting home to other accounts (fixes files left readable by everyone).'],
        'app-isolate'         => ['Security', 'Webmail / phpMyAdmin isolation', 'Keeps Roundcube and phpMyAdmin running as their own users, apart from the panel.'],
        'mail-guard'          => ['Email', 'Mail guard', 'Spots mailboxes sending like they have been hacked and holds their outgoing mail.'],
        'mail-reports-ingest' => ['Email', 'DMARC / TLS reports', 'Reads incoming DMARC and TLS aggregate reports into the panel, for the reports viewer and autopilot.'],
        'autoreply-sync'      => ['Email', 'Auto-reply dates', 'Turns autoresponders on and off on their start and end dates.'],
        'roundcube-gc'        => ['Email', 'Webmail clean-up', 'Clears expired webmail sessions and cache; purges deleted rows once a day.'],
        'bandwidth-collect'   => ['Usage & quotas', 'Bandwidth count', 'Adds up each domain\'s web traffic from the access logs for the usage graphs.'],
        'quota-enforce'       => ['Usage & quotas', 'Disk quota enforcement', 'Measures disk use; an account over quota loses FTP and its site goes offline until it is back under.'],
        'bandwidth-enforce'   => ['Usage & quotas', 'Bandwidth limit', 'Suspends accounts that have used their monthly bandwidth.'],
        'bandwidth-reset'     => ['Usage & quotas', 'Monthly bandwidth reset', 'On the 1st: lifts automatic bandwidth suspensions (manual suspensions stay).'],
        'certbot'             => ['Housekeeping', 'SSL renewal', 'Twice a day: renews Let\'s Encrypt certificates near expiry and reloads web, FTP and mail.'],
        'file-trash-purge'    => ['Housekeeping', 'File-manager trash', 'Permanently deletes file-manager trash older than 30 days.'],
    ];
    public function list(): void {
        Auth::requireLogin();
        $u = Auth::user();
        $p = Helpers::paginate((int)Database::query("SELECT COUNT(*) c FROM cron_jobs WHERE user_id=?", [$u['id']])->fetch()['c'], max(1, (int)($_GET['page'] ?? 1)));
        $pg = $p['pg']; $pages = $p['pages']; $total = $p['total'];
        $crons = Database::query("SELECT * FROM cron_jobs WHERE user_id=? ORDER BY id DESC LIMIT ? OFFSET ?", [$u['id'], (int)$p['limit'], (int)$p['offset']])->fetchAll();
        // last-run state from wrapper logs in the user's home (panel-readable via ACL)
        foreach ($crons as &$c) {
            $c['last'] = null;
            $log = "/home/{$u['username']}/.skypanel-cron/{$c['id']}.log";
            if (preg_match('/^[a-z][a-z0-9_]{2,31}$/', $u['username']) && is_readable($log)) {
                $tail = file_get_contents($log, false, null, max(0, (filesize($log) ?: 0) - 4096));
                $lines = array_filter(array_map('trim', explode("\n", (string)$tail)));
                $last = end($lines);
                $start = '';
                foreach (array_reverse($lines) as $l) {
                    if (str_starts_with($l, '=== START ')) { $start = $l; break; }
                }
                $c['last'] = ['start' => $start, 'end' => $last, 'out' => implode("\n", array_slice($lines, -8, 7))];
            }
            // dead-man status for the Watch column (monitor mails on overdue)
            $c['watch'] = null;
            if (!empty($c['expect_min'])) {
                $c['watch'] = 'never';
                if (!empty($c['last']['end']) && preg_match('/(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})/', $c['last']['end'], $m) && ($t = strtotime($m[1]))) {
                    $c['watch'] = (time() - $t > ((int)$c['expect_min'] + (int)($c['grace_min'] ?? 15)) * 60) ? 'overdue' : 'ok';
                }
            }
        }
        unset($c);
        // admins also see the box-level schedule (read-only — installer-owned,
        // so users can tell "the system runs X" apart from their own jobs)
        $syscrons = [];
        if (($u['role'] ?? '') === 'admin' && is_readable('/etc/cron.d/skypanel')) {
            foreach (file('/etc/cron.d/skypanel', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) continue;
                if (preg_match('/^(\S+\s+\S+\s+\S+\s+\S+\s+\S+)\s+root\s+(.+)$/', $line, $m)) {
                    $key = str_starts_with($m[2], 'certbot ') ? 'certbot' : (preg_match('#/scripts/([a-z0-9-]+)\.sh#', $m[2], $k) ? $k[1] : '');
                    [$group, $name, $about] = self::SYSJOBS[$key] ?? ['Other', $key !== '' ? $key : 'custom job', 'Not a stock KarnakPanel job — added on this server.'];
                    $syscrons[] = ['schedule' => $m[1], 'command' => $m[2], 'group' => $group, 'name' => $name, 'about' => $about];
                }
            }
        }
        $this->view('cron', compact('crons','pg','pages','total','syscrons'));
    }
    // Dead-man expectation: job must finish at least every N min (+grace).
    // Empty expectation = unwatched. Checked by cron-monitor.sh every 5 min.
    public function expect(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/cron'); }
        $u = Auth::user();
        $id = (int)($_POST['id'] ?? 0);
        $row = Database::query("SELECT * FROM cron_jobs WHERE id=? AND user_id=?", [$id, $u['id']])->fetch();
        if (!$row) { Helpers::flash('err','Job not found'); Helpers::redirect('/cron'); }
        $raw = trim((string)($_POST['expect_min'] ?? ''));
        if ($raw === '') {
            Database::query("UPDATE cron_jobs SET expect_min=NULL WHERE id=?", [$id]);
            Helpers::flash('ok','Watch removed'); Helpers::redirect('/cron');
        }
        if (!preg_match('/^[0-9]{1,6}$/', $raw) || (int)$raw < 5 || (int)$raw > 525600) { Helpers::flash('err','Expect every 5..525600 min (or blank to unwatch)'); Helpers::redirect('/cron'); }
        $grace = (int)($_POST['grace_min'] ?? 15);
        if ($grace < 0 || $grace > 1440) { Helpers::flash('err','Grace 0..1440 min'); Helpers::redirect('/cron'); }
        Database::query("UPDATE cron_jobs SET expect_min=?, grace_min=? WHERE id=?", [(int)$raw, $grace, $id]);
        Helpers::flash('ok',"Job watched: must finish every $raw min (+$grace grace)");
        Helpers::redirect('/cron');
    }
    public function create(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/cron'); }
        $u = Auth::user();
        $schedule = trim($_POST['schedule']??''); $cmd = trim($_POST['command']??'');
        if (!preg_match('/^([\*\d,\-\/]+)\s+([\*\d,\-\/]+)\s+([\*\d,\-\/]+)\s+([\*\d,\-\/]+)\s+([\*\d,\-\/]+)$/',$schedule) || $cmd==='') {
            Helpers::flash('err','Bad cron expression or empty command'); Helpers::redirect('/cron');
        }
        Database::query("INSERT INTO cron_jobs(user_id,schedule,command) VALUES(?,?,?)", [$u['id'],$schedule,$cmd]);
        ServiceManager::run('cron-sync.sh', [$u['username']]);
        // bare `php` is the box's CLI default (pinned to PhpVersions::DEFAULT
        // by host-converge, but an admin may move it) — nudge toward a pinned
        // version, but don't block (some scripts genuinely don't care).
        if (preg_match('/(^|[;\s&|]+)php(\s|$)/', $cmd) && !preg_match('/php8\.[0-9]/', $cmd)) {
            Helpers::flash('ok','Cron added & synced — note: bare `php` follows the server\'s default CLI version. Pin one (php'.PhpVersions::DEFAULT.', php'.implode(', php', array_slice(PhpVersions::selectable(), 1)).') if the app cares.');
        } else Helpers::flash('ok','Cron added & synced');
        Helpers::redirect('/cron');
    }
    public function delete(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/cron'); }
        $u = Auth::user();
        Database::query("DELETE FROM cron_jobs WHERE id=? AND user_id=?", [(int)($_POST['id']??0), $u['id']]);
        ServiceManager::run('cron-sync.sh', [$u['username']]);
        Helpers::flash('ok','Cron deleted'); Helpers::redirect('/cron');
    }
}
