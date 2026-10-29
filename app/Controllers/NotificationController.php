<?php
declare(strict_types=1);
class NotificationController extends Controller {
    public function list(): void {
        Auth::requireLogin();
        $u = Auth::user();
        if ($u['role'] === 'admin') {
            $p = Helpers::paginate((int)Database::query("SELECT COUNT(*) c FROM notifications")->fetch()['c'], max(1, (int)($_GET['page'] ?? 1)));
            $pg = $p['pg']; $pages = $p['pages']; $total = $p['total'];
            $notes = Database::query("SELECT n.*, u.username AS who FROM notifications n LEFT JOIN users u ON u.id=n.user_id ORDER BY n.id DESC LIMIT ? OFFSET ?", [(int)$p['limit'], (int)$p['offset']])->fetchAll();
        } else {
            $p = Helpers::paginate((int)Database::query("SELECT COUNT(*) c FROM notifications WHERE user_id=?", [$u['id']])->fetch()['c'], max(1, (int)($_GET['page'] ?? 1)));
            $pg = $p['pg']; $pages = $p['pages']; $total = $p['total'];
            $notes = Database::query("SELECT * FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT ? OFFSET ?", [$u['id'], (int)$p['limit'], (int)$p['offset']])->fetchAll();
        }
        $push = [];
        if (($u['role'] ?? '') === 'admin') {
            try {
                foreach (['push_provider','push_token','push_chat','push_quiet_from','push_quiet_to'] as $k) {
                    $push[$k] = (string)(Database::query("SELECT value FROM settings WHERE name=?", [$k])->fetch()['value'] ?? '');
                }
            } catch (Throwable $e) {}
        }
        // watchdog liveness for the stale-watchdog banner (same source as Services)
        $watchdogAge = null;
        try {
            $r = ServiceManager::run('watchdog-status.sh', []);
            if (($r['code'] ?? 1) === 0 && preg_match('/^age=(\d+)$/', trim($r['out'] ?? ''), $m)) $watchdogAge = (int)$m[1];
        } catch (Throwable $e) {}
        $this->view('notifications', compact('notes','pg','pages','total','push','watchdogAge'));
    }
    public function read(): void {        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/notifications'); }
        $u = Auth::user();
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $sql = ($u['role'] === 'admin') ? "UPDATE notifications SET read_at=NOW() WHERE id=?" : "UPDATE notifications SET read_at=NOW() WHERE id=? AND user_id=?";
            Database::query($sql, ($u['role'] === 'admin') ? [$id] : [$id, $u['id']]);
        } else {
            if ($u['role'] === 'admin') Database::query("UPDATE notifications SET read_at=NOW() WHERE read_at IS NULL");
            else Database::query("UPDATE notifications SET read_at=NOW() WHERE read_at IS NULL AND user_id=?", [$u['id']]);
        }
        Helpers::redirect('/notifications');
    }
    public function deleteAll(): void {
        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/notifications'); }
        $u = Auth::user();
        if (($u['role'] ?? '') === 'admin') Database::query("DELETE FROM notifications");
        else Database::query("DELETE FROM notifications WHERE user_id=?", [$u['id']]);
        Helpers::redirect('/notifications');
    }
    public function delete(): void {        Auth::requireLogin();
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/notifications'); }
        $u = Auth::user();
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $sql = ($u['role'] === 'admin') ? "DELETE FROM notifications WHERE id=?" : "DELETE FROM notifications WHERE id=? AND user_id=?";
            Database::query($sql, ($u['role'] === 'admin') ? [$id] : [$id, $u['id']]);
        }
        Helpers::redirect('/notifications');
    }
    // Phone push (admin, default off): Telegram bot for box-level urgents
    // (service, integrity, egress, offsite, expiry, malware, quota). Server
    // sends only short status text — never IPs, secrets or links. Quiet hours
    // hold non-critical types for daytime; service/integrity always buzz.
    // Setup: @BotFather -> /newbot -> paste token -> message the bot ->
    // Find chat -> Save -> Test. Lost phone = revoke via @BotFather.
    public function push(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/notifications'); }
        // Find-chat shares this form (separate forms can't see the typed
        // token). It also SAVES provider+token first — otherwise the redirect
        // reloads from DB and the page appears to "reset" your entries.
        if ((string)($_POST['do'] ?? 'save') === 'find') {
            $token = trim((string)($_POST['token'] ?? ''));
            if (!preg_match('/^[0-9]{5,20}:[A-Za-z0-9_-]{20,80}$/', $token)) { Helpers::flash('err','Paste the bot token first, then Find chat'); Helpers::redirect('/notifications'); }
            try {
                Database::query("INSERT INTO settings(name,value) VALUES('push_provider','telegram'),('push_token',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [$token]);
                // preserve the rest of the typed form too (chat + quiet hours)
                $chat = trim((string)($_POST['chat'] ?? ''));
                if (preg_match('/^-?[0-9]{1,20}$/', $chat) && $chat !== '') {
                    Database::query("INSERT INTO settings(name,value) VALUES('push_chat',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [$chat]);
                }
                $qf = trim((string)($_POST['quiet_from'] ?? '')); $qt = trim((string)($_POST['quiet_to'] ?? ''));
                if (preg_match('/^[0-9]{1,2}$/', $qf) && (int)$qf <= 23 && preg_match('/^[0-9]{1,2}$/', $qt) && (int)$qt <= 23) {
                    Database::query("INSERT INTO settings(name,value) VALUES('push_quiet_from',?),('push_quiet_to',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [$qf, $qt]);
                }
            } catch (Throwable $e) {}
            $r = ServiceManager::run('push-chat.sh', [new Secret($token)]);
            if (($r['code'] ?? 1) !== 0) Helpers::flash('err','No chats: '.trim($r['out']));
            else {
                // single chat = the obvious one: fill Chat ID automatically,
                // multiples = list them for the admin to pick
                $found = [];
                foreach (explode("\n", trim($r['out'] ?? '')) as $line) {
                    if (preg_match('/^(-?[0-9]{1,20})\t(.*)$/', trim($line), $m)) $found[] = [$m[1], $m[2]];
                }
                if (count($found) === 1) {
                    try { Database::query("INSERT INTO settings(name,value) VALUES('push_chat',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [$found[0][0]]); } catch (Throwable $e2) {}
                    Helpers::flash('ok', "Chat ID filled in ({$found[0][0]} {$found[0][1]}) — press Save, then Test");
                } else {
                    Helpers::flash('ok', 'Chats that messaged the bot (copy the ID into Chat ID, then Save): '.trim($r['out']));
                }
            }
            Helpers::redirect('/notifications');
        }
        $prov = (string)($_POST['provider'] ?? 'off');
        if (!in_array($prov, ['off','telegram'], true)) { Helpers::flash('err','Bad provider'); Helpers::redirect('/notifications'); }
        $vals = ['push_provider' => $prov];
        if ($prov === 'telegram') {
            $token = trim((string)($_POST['token'] ?? ''));
            $chat = trim((string)($_POST['chat'] ?? ''));
            if (!preg_match('/^[0-9]{5,20}:[A-Za-z0-9_-]{20,80}$/', $token)) { Helpers::flash('err','Bad bot token (digits:string from @BotFather)'); Helpers::redirect('/notifications'); }
            if (!preg_match('/^-?[0-9]{1,20}$/', $chat)) { Helpers::flash('err','Bad chat ID (message the bot, then Find chat)'); Helpers::redirect('/notifications'); }
            $vals['push_token'] = $token; $vals['push_chat'] = $chat;
        }
        $qf = trim((string)($_POST['quiet_from'] ?? '22')); $qt = trim((string)($_POST['quiet_to'] ?? '8'));
        if (!preg_match('/^[0-9]{1,2}$/', $qf) || (int)$qf > 23 || !preg_match('/^[0-9]{1,2}$/', $qt) || (int)$qt > 23) { Helpers::flash('err','Bad quiet hours'); Helpers::redirect('/notifications'); }
        $vals['push_quiet_from'] = $qf; $vals['push_quiet_to'] = $qt;
        try {
            foreach ($vals as $k => $v) {
                Database::query("INSERT INTO settings(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [$k, $v]);
            }
            Helpers::log("push $prov configured by ".Auth::user()['username']);
            Helpers::flash('ok', $prov === 'off' ? 'Phone alerts off' : "Phone alerts on ($prov) — press Test to verify delivery");
        } catch (Throwable $e) { Helpers::flash('err','Save failed: '.$e->getMessage()); }
        Helpers::redirect('/notifications');
    }
    public function pushChat(): void {
        Auth::requireRole(['admin']);
        // legacy separate-button route: forwards into push() which owns the form
        $_POST['do'] = 'find';
        $this->push();
    }
    public function pushTest(): void {
        Auth::requireRole(['admin']);
        if (!Helpers::checkCsrf()) { Helpers::flash('err','Bad CSRF'); Helpers::redirect('/notifications'); }
        $r = ServiceManager::run('push-digest.sh', ['--test']);
        if (($r['code'] ?? 1) !== 0) Helpers::flash('err','Test failed: '.trim($r['out']));
        else Helpers::flash('ok', trim($r['out']).' — check the phone');
        Helpers::redirect('/notifications');
    }
}
