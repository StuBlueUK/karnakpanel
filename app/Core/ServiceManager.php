<?php
declare(strict_types=1);
// Maps panel actions -> privileged bash scripts (runs via sudo, no direct root from php)
class ServiceManager {
    // $timeout: seconds before the call is killed (15 default; long file-manager
    // ops — copy, extract, pack, upload scan — pass up to 300, inside the
    // panel vhost's 330s backend limit).
    public static function run(string $script, array $args = [], int $timeout = 15): array {        $base = Config::get('paths.scripts', '/usr/local/skypanel/scripts');
        $name = basename($script);
        // fail closed with a clear message: a missing script fails downstream
        // as sudo 'command not found' (seen live after a mode-dropping sync).
        // Deliberately existence-only (not executable): the panel user can't
        // meaningfully test root-execution bits, and sudo is the enforcer.
        if (!is_file($base.'/'.$name)) {
            return ['code' => 127, 'out' => "refused: unknown privileged script: $name"];
        }
        // Secret args travel on stdin (see Secret.php), never in argv/logs
        $stdin = null;
        foreach ($args as $i => $a) {
            if (!$a instanceof Secret) continue;
            if (str_contains($a->value, "\0")) return ['code' => 400, 'out' => 'refused: NUL in secret'];
            $stdin = ($stdin ?? '').$a->value."\0";
            $args[$i] = '@stdin';
        }
        $safe = array_map('escapeshellarg', $args);
        // timeout guard: never let a hung sudo/script hang a web request (dev boxes lack NOPASSWD sudo)
        $t = (is_executable('/usr/bin/timeout') || is_executable('/bin/timeout')) ? 'timeout '.max(1, min(300, $timeout)).' ' : '';
        $cmd = $t . 'sudo ' . $base . '/' . $name . ' ' . implode(' ', $safe);
        return Helpers::sysExec($cmd, $stdin);
    }
    // Stream a privileged script's stdout straight to the client (downloads).
    // Never returns: sends headers + passthru, then exits. Filename is
    // validated here AND in the script (defence in depth); callers must still
    // check ownership before calling.
    public static function streamDownload(string $script, string $file, string $dlName): void {
        // nosemgrep: php.lang.security.exec-use.exec-use -- filename allowlist gate itself, not a sink
        if (!preg_match('/^[a-zA-Z0-9_.-]+\.(tar\.gz|sql\.gz)$/', $file) || str_contains($file, '/')) {
            http_response_code(400); echo 'Bad filename'; exit;
        }
        $base = Config::get('paths.scripts', '/usr/local/skypanel/scripts');
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="'.str_replace('"', '', $dlName).'"');
        header('X-Content-Type-Options: nosniff');
        // long timeout: downloads aren't hung scripts; passthru avoids buffering whole file
        // nosemgrep: php.lang.security.exec-use.exec-use -- gateway: script basename'd, file regex-validated + slash-rejected
        passthru('timeout 300 sudo '.$base.'/'.basename($script).' '.escapeshellarg($file), $code);
        if ($code !== 0) { error_log("streamDownload failed: $script $file code=$code"); }
        exit;
    }
    public static function status(): array {
        static $cache = null;
        static $at = 0;
        if ($cache !== null && (time() - $at) < 60) return $cache; // 60s cache: 10x systemctl is slow
        if (!is_executable('/usr/bin/systemctl') && !is_executable('/bin/systemctl')) {
            return $cache = []; // dev box without systemd: hide section
        }
        $svcs = self::units();
        // nosemgrep: php.lang.security.exec-use.exec-use -- rule misfire: empty array literal; real execs below use escapeshellarg
        $out = [];
        foreach ($svcs as $label => $unit) {
            // nosemgrep: php.lang.security.exec-use.exec-use -- unit from hardcoded map + escapeshellarg'd
            exec("timeout 2 systemctl is-active ".escapeshellarg($unit)." 2>&1", $o, $c);
            $state = ($c === 0) ? 'active' : 'down';
            unset($o);
            $up = '—';
            if ($state === 'active') {
                $ts = [];
                // nosemgrep: php.lang.security.exec-use.exec-use -- unit from hardcoded map + escapeshellarg'd
                exec("timeout 2 systemctl show -p ActiveEnterTimestamp --value ".escapeshellarg($unit)." 2>&1", $ts);
                $t = strtotime(trim(implode('', $ts)));
                unset($ts);
                if ($t) $up = self::ago(max(0, time() - $t));
            }
            $out[$label] = ['state' => $state, 'uptime' => $up];
        }
        $at = time();
        return $cache = $out;
    }
    public static function ago(int $s): string {
        $d = (int)($s / 86400); $h = (int)(($s % 86400) / 3600); $m = (int)(($s % 3600) / 60);
        return $d > 0 ? "{$d}d {$h}h" : ($h > 0 ? "{$h}h {$m}m" : "{$m}m");
    }
    // Services page: one `systemctl show` for every unit (blocks come back in
    // argument order) — state, since, main PID, memory, tasks, auto-restarts.
    public static function details(): array {
        if (!is_executable('/usr/bin/systemctl') && !is_executable('/bin/systemctl')) return [];
        $svcs = self::units();
        $o = [];
        // nosemgrep: php.lang.security.exec-use.exec-use -- units from the hardcoded map, each escapeshellarg'd
        exec("timeout 4 systemctl show -p ActiveState,SubState,MainPID,MemoryCurrent,TasksCurrent,NRestarts,ActiveEnterTimestamp,CanReload ".implode(' ', array_map('escapeshellarg', array_values($svcs)))." 2>/dev/null", $o);
        $blocks = [[]];
        foreach ($o as $l) {
            if (trim($l) === '') { if ($blocks[count($blocks) - 1]) $blocks[] = []; continue; }
            [$k, $v] = array_pad(explode('=', $l, 2), 2, '');
            $blocks[count($blocks) - 1][$k] = $v;
        }
        $out = []; $i = 0; $now = time();
        foreach ($svcs as $label => $unit) {
            $b = $blocks[$i++] ?? [];
            $act = ($b['ActiveState'] ?? '') === 'active';
            $t = $act ? strtotime((string)($b['ActiveEnterTimestamp'] ?? '')) : false;
            $num = fn($k) => ctype_digit((string)($b[$k] ?? '')) ? (int)$b[$k] : null;
            $out[$label] = [
                'state'   => $act ? 'active' : 'down',
                'sub'     => (string)($b['SubState'] ?? ($b ? '' : 'unknown')),
                'since'   => $t ?: null,
                'uptime'  => $t ? self::ago(max(0, $now - $t)) : '—',
                'pid'     => $act ? $num('MainPID') : null,
                'mem'     => $act ? $num('MemoryCurrent') : null,
                'tasks'   => $act ? $num('TasksCurrent') : null,
                'restarts'=> $num('NRestarts') ?? 0,
                'reload'  => ($b['CanReload'] ?? '') === 'yes',
            ];
        }
        return $out;
    }
    // label => systemd unit for every service this box runs
    public static function units(): array {
        // nosemgrep: php.lang.security.exec-use.exec-use -- hardcoded service map literal, not input (exec sites below escape their arg)
        $svcs = ['openlitespeed'=>'lshttpd','mariadb'=>'mariadb','bind9'=>'named','dovecot'=>'dovecot','exim4'=>'exim4','vsftpd'=>'vsftpd','fail2ban'=>'fail2ban','spamassassin'=>'spamd','cron'=>'cron','ssh'=>'ssh'];
        // one row per php-fpm actually installed (PhpVersions: glob of fixed
        // /usr/sbin/php-fpm8.N paths, so the unit names stay ^php8\.[0-9]-fpm$)
        foreach (PhpVersions::installed() as $v) $svcs["php-fpm $v"] = "php$v-fpm";
        // clamav only exists on boxes that opted in (installer) — no phantom "down"
        $hasClam = [];
        // nosemgrep: php.lang.security.exec-use.exec-use -- constant command, zero interpolation
        exec("timeout 2 systemctl cat clamav-daemon 2>/dev/null", $hasClam, $hc);
        unset($hasClam);
        if ($hc === 0) $svcs['clamav'] = 'clamav-daemon';
        // spamd unit renamed across releases (spamassassin.service on 22.04,
        // spamd.service on 24.04+) — probe like clamav, never assume
        // nosemgrep: php.lang.security.exec-use.exec-use -- constant command, zero interpolation
        $hasSpamd = [];
        exec("timeout 2 systemctl cat spamd 2>/dev/null", $hasSpamd, $hs);
        unset($hasSpamd);
        if ($hs !== 0) $svcs['spamassassin'] = 'spamassassin';
        return $svcs;
    }
}
