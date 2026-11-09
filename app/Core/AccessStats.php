<?php
declare(strict_types=1);
// Pure access-log analyser for the Stats page (no I/O: the controller reads
// the allowlisted log and hands the lines in, so this stays unit-testable).
// Every field in a log line is visitor-controlled — callers must escape.
class AccessStats {
    private const ASSET = '/\.(css|js|mjs|map|png|jpe?g|gif|webp|avif|svg|ico|bmp|woff2?|ttf|eot|otf|mp4|webm|mp3|ogg|txt|xml|json)$/';
    private const PROBE = '#(^|/)\.(env|git|svn|aws|ssh|ds_store|htpasswd)|wp-login\.php|xmlrpc\.php|wp-config|/wp-admin/(install|setup-config)\.php|phpmyadmin|/pma/|/cgi-bin/|phpunit|eval-stdin|/boaform|/actuator|/solr/|/owa/|/autodiscover|\.\./|/shell|/(backup|db|dump)\.(sql|zip|tar)|(^|/)\.(hg|bzr)/|(^|/)(php)?info\.php|server-status|___proxy_subdomain|wlwmanifest\.xml|/vendor/|/\.well-known/security\.txt\.bak#i';
    private const BOTS = [
        'Googlebot' => 'googlebot|google-inspectiontool|adsbot-google|mediapartners-google',
        'Bingbot' => 'bingbot|bingpreview|msnbot', 'Yandex' => 'yandex', 'Baidu' => 'baiduspider',
        'DuckDuckGo' => 'duckduckbot', 'Applebot' => 'applebot', 'GPTBot / OpenAI' => 'gptbot|chatgpt-user|oai-searchbot',
        'ClaudeBot' => 'claudebot|claude-web|anthropic', 'Perplexity' => 'perplexity', 'CCBot' => 'ccbot',
        'Bytespider' => 'bytespider', 'Ahrefs' => 'ahrefs', 'Semrush' => 'semrush', 'MJ12bot' => 'mj12bot',
        'DotBot' => 'dotbot', 'PetalBot' => 'petalbot', 'Meta / Facebook' => 'facebookexternalhit|meta-externalagent|facebookcatalog',
        'X / Twitter' => 'twitterbot', 'Uptime monitor' => 'uptime|pingdom|statuscake|betteruptime|monitor',
        'curl / wget' => '^curl|^wget', 'Scripts (Python, Go, …)' => 'python|go-http-client|okhttp|java/|libwww|httpclient|axios|node-fetch|scrapy|zgrab|masscan|nmap',
    ];
    private const BOTRX = '/bot|crawl|spider|slurp|scan|fetch|preview|headless/i';

    // Browser / OS / device of a human UA, or the bot name ('' = not a bot).
    public static function agent(string $ua): array {
        $ua = trim(str_replace('\\"', '"', $ua), '" ');
        if ($ua === '' || $ua === '-') return ['bot' => 'No user agent'];
        foreach (self::BOTS as $name => $rx) if (preg_match("~$rx~i", $ua)) return ['bot' => $name];
        if (preg_match(self::BOTRX, $ua) || !preg_match('#^Mozilla/|^Opera#', $ua)) return ['bot' => 'Other bots & tools'];
        $b = preg_match('#Edg(e|A|iOS)?/#', $ua) ? 'Edge' : (preg_match('#OPR/|Opera#', $ua) ? 'Opera'
            : (preg_match('#SamsungBrowser#', $ua) ? 'Samsung Internet' : (preg_match('#Firefox/|FxiOS#', $ua) ? 'Firefox'
            : (preg_match('#Chrome/|CriOS#', $ua) ? 'Chrome' : (preg_match('#Safari/#', $ua) ? 'Safari' : 'Other')))));
        $o = preg_match('#iPhone|iPad|iPod#', $ua) ? 'iOS' : (preg_match('#Android#', $ua) ? 'Android'
            : (preg_match('#Windows#', $ua) ? 'Windows' : (preg_match('#Mac OS X|Macintosh#', $ua) ? 'macOS'
            : (preg_match('#CrOS#', $ua) ? 'ChromeOS' : (preg_match('#Linux|X11#', $ua) ? 'Linux' : 'Other')))));
        $d = preg_match('#iPad|Tablet#', $ua) || ($o === 'Android' && !preg_match('#Mobile#', $ua)) ? 'Tablet'
            : (preg_match('#Mobi|iPhone|iPod#', $ua) ? 'Mobile' : 'Desktop');
        return ['bot' => '', 'browser' => $b, 'os' => $o, 'device' => $d];
    }

    // $days calendar days ending today (server time), plus the same elapsed
    // span before it for the "vs previous" deltas (only trusted when the log
    // actually reaches back that far — see 'prevOk').
    public static function parse(array $lines, int $days, int $now, string $domain = ''): array {
        $start = (int)strtotime(date('Y-m-d 00:00:00', $now).' -'.($days - 1).' days');
        $pstart = (int)strtotime(date('Y-m-d 00:00:00', $start).' -'.$days.' days');
        $pend = $pstart + ($now - $start); // same elapsed span, so a half-finished today isn't compared with a whole day
        $s = ['hits' => 0, 'uniq' => 0, 'bytes' => 0, 'bots' => 0, 'humans' => 0, 'assets' => 0, 'probes' => 0,
            'status' => ['2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0], 'hours' => array_fill(0, 24, 0),
            'wdays' => array_fill(0, 7, 0), 'daily' => [], 'pages' => [], 'refs' => [], 'botnames' => [],
            'browsers' => [], 'os' => [], 'devices' => [], 'notfound' => [], 'errors' => [], 'probepaths' => [],
            'topips' => [], 'first' => null, 'last' => null, 'firstAny' => null, 'lines' => 0,
            'prev' => ['hits' => 0, 'uniq' => 0, 'bytes' => 0], 'prevOk' => false, 'start' => $start, 'days' => $days];
        for ($t = $start, $i = 0; $i < $days; $i++, $t = (int)strtotime('+1 day', $t))
            $s['daily'][date('Y-m-d', $t)] = ['hits' => 0, 'bots' => 0, 'uniq' => 0, 'bytes' => 0];
        $ips = []; $dayIps = []; $prevIps = []; $ipProbe = []; $ipErr = []; $tcache = [];
        $self = preg_replace('/^www\./', '', strtolower($domain));
        foreach ($lines as $line) {
            // referer + UA allow backslash-escaped quotes inside (OLS writes \" for them)
            if (!preg_match('/^\x27?(\S+) \S+ \S+ \[([^\]]+)\] "([A-Z]+) (\S+)[^"]*" (\d{3}) (\S+)(?: "((?:[^"\\\\]|\\\\.)*)" "((?:[^"\\\\]|\\\\.)*)")?/', $line, $m)) continue;
            $key = $m[2];
            if (!isset($tcache[$key])) {
                if (count($tcache) > 5000) $tcache = [];
                $dt = DateTime::createFromFormat('d/M/Y:H:i:s O', $key);
                $tcache[$key] = $dt ? $dt->getTimestamp() : 0;
            }
            $t = $tcache[$key];
            if (!$t) continue;
            $s['lines']++;
            if ($s['firstAny'] === null || $t < $s['firstAny']) $s['firstAny'] = $t;
            $ip = $m[1]; $bytes = is_numeric($m[6]) ? (int)$m[6] : 0;
            if ($t < $start) {
                if ($t >= $pstart && $t < $pend) { $s['prev']['hits']++; $s['prev']['bytes'] += $bytes; $prevIps[$ip] = 1; }
                continue;
            }
            if ($t > $now + 86400) continue;
            if ($s['first'] === null || $t < $s['first']) $s['first'] = $t;
            if ($s['last'] === null || $t > $s['last']) $s['last'] = $t;
            $day = date('Y-m-d', $t);
            if (!isset($s['daily'][$day])) continue;
            $code = $m[5]; $cls = $code[0].'xx';
            $path = (string)preg_replace('#/{2,}#', '/', strtolower(explode('?', $m[4], 2)[0]));
            $s['hits']++; $s['bytes'] += $bytes;
            if (isset($s['status'][$cls])) $s['status'][$cls]++;
            $s['hours'][(int)date('G', $t)]++;
            $s['wdays'][(int)date('N', $t) - 1]++;
            $s['daily'][$day]['hits']++; $s['daily'][$day]['bytes'] += $bytes;
            $ips[$ip] = ($ips[$ip] ?? 0) + 1; $dayIps[$day][$ip] = 1;
            $a = self::agent($m[8] ?? '');
            if ($a['bot'] !== '') {
                $s['bots']++; $s['daily'][$day]['bots']++;
                $s['botnames'][$a['bot']] = ($s['botnames'][$a['bot']] ?? 0) + 1;
            } else {
                $s['humans']++;
                foreach (['browser' => 'browsers', 'os' => 'os', 'device' => 'devices'] as $k => $bucket)
                    $s[$bucket][$a[$k]] = ($s[$bucket][$a[$k]] ?? 0) + 1;
            }
            if (preg_match(self::PROBE, $path)) {
                $s['probes']++; $s['probepaths'][$path] = ($s['probepaths'][$path] ?? 0) + 1;
                $ipProbe[$ip] = ($ipProbe[$ip] ?? 0) + 1;
            } else {
                $asset = (bool)preg_match(self::ASSET, $path);
                if ($asset) $s['assets']++;
                elseif ($cls === '2xx' && !str_starts_with($path, '/.well-known/')) $s['pages'][$path] = ($s['pages'][$path] ?? 0) + 1;
                if ($code === '404') $s['notfound'][$path] = ($s['notfound'][$path] ?? 0) + 1;
            }
            if ($cls === '5xx') $s['errors'][$path] = ($s['errors'][$path] ?? 0) + 1;
            if ($cls === '4xx' || $cls === '5xx') $ipErr[$ip] = ($ipErr[$ip] ?? 0) + 1;
            $ref = str_replace('\\"', '"', $m[7] ?? '');
            if ($ref !== '' && $ref !== '-') {
                if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $ref)) $ref = 'http://'.$ref; // some clients send bare hosts
                $host = strtolower((string)(parse_url($ref, PHP_URL_HOST) ?: substr($ref, 7, 80)));
                $h = preg_replace('/^www\./', '', $host);
                if ($self === '' || ($h !== $self && !str_ends_with($h, ".$self")))
                    $s['refs'][$host] = ($s['refs'][$host] ?? 0) + 1;
            }
        }
        foreach ($dayIps as $day => $set) $s['daily'][$day]['uniq'] = count($set);
        $s['uniq'] = count($ips);
        $s['prev']['uniq'] = count($prevIps);
        $s['prevOk'] = $s['firstAny'] !== null && $s['firstAny'] < $pstart + 3600;
        arsort($ips);
        foreach (array_slice($ips, 0, 10, true) as $ip => $c)
            $s['topips'][(string)$ip] = ['hits' => $c, 'probes' => $ipProbe[$ip] ?? 0, 'errors' => $ipErr[$ip] ?? 0];
        foreach (['pages' => 15, 'refs' => 10, 'botnames' => 12, 'browsers' => 8, 'os' => 8, 'devices' => 3,
                  'notfound' => 10, 'errors' => 10, 'probepaths' => 8] as $k => $n) {
            arsort($s[$k]);
            $s[$k] = array_slice($s[$k], 0, $n, true);
        }
        return $s;
    }

    // Percentage change vs the previous period, or null when not comparable.
    public static function delta(int $now, int $prev): ?int {
        if ($prev <= 0) return null;
        return (int)round(($now - $prev) / $prev * 100);
    }
}
