<?php
/**
 * KarnakPanel WHMCS provisioning module v1
 * Install: copy the `karnakpanel/` folder to WHMCS/modules/servers/, then
 * Setup > Products > Servers > Add New Server (type KarnakPanel):
 *   hostname = panel FQDN, username = anything, password = panel API token
 *   (mint at panel: API > New token, admin user). Access hash: leave blank.
 * Product > Module Settings: server group + "Panel package" (exact panel
 * package name, e.g. Bronze). WHMCS username field becomes panel username.
 *
 * Security: HTTPS with FULL certificate verification (no insecure fallback),
 * 15s timeouts, token sent as X-API-KEY header (sha256-stored server-side),
 * all mutating calls are POST, usernames re-validated panel-side, every call
 * audit-logged in the panel log. Failed auth returns 401 and counts toward
 * the panel's fail2ban jail like any other bad login.
 */
function karnakpanel_ConfigOptions() {
    return [
        'package' => [
            'FriendlyName' => 'Panel package',
            'Type' => 'text',
            'Size' => '30',
            'Description' => 'Exact package name from panel Packages page (Bronze, Silver…). Empty = panel defaults.',
        ],
    ];
}

function karnakpanel_api(array $params, string $method, array $data = []) {
    $host = trim($params['serverhostname'] ?? '');
    $token = (string)($params['serverpassword'] ?? '');
    if ($host === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return ['error' => 'Server not configured: panel hostname + 64-hex API token required'];
    }
    $isPost = ($params['_method'] ?? 'GET') === 'POST';
    $url = 'https://' . rtrim($host, '/') . ':8090/api/provision/' . $method
        . ($isPost || !$data ? '' : '?' . http_build_query($data));
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['X-API-KEY: ' . $token, 'Accept: application/json'],
    ]);
    if ($isPost) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $err !== '') return ['error' => 'Panel unreachable: ' . $err, '_http' => $code];
    $j = json_decode((string)$body, true);
    if (!is_array($j)) return ['error' => 'Panel returned non-JSON (HTTP ' . $code . ')', '_http' => $code];
    $j['_http'] = $code;
    return $j;
}

function karnakpanel_TestConnection(array $params) {
    $r = karnakpanel_api($params, 'usage', ['username' => '']);
    if (isset($r['error']) && ($r['_http'] ?? 0) === 401) {
        return ['success' => false, 'error' => 'Rejected (401): check the API token + that it belongs to an admin'];
    }
    if (($r['_http'] ?? 0) === 404) {
        return ['success' => true]; // reachable + authed; blank probe user simply not found
    }
    return isset($r['error'])
        ? ['success' => false, 'error' => $r['error']]
        : ['success' => true];
}

function karnakpanel_CreateAccount(array $params) {
    $username = strtolower(trim($params['username'] ?? ''));
    $r = karnakpanel_api($params + ['_method' => 'POST'], 'create', [
        'username' => $username,
        'password' => (string)($params['password'] ?? ''),
        'email' => (string)($params['clientsdetails']['email'] ?? ''),
        'package' => (string)($params['configoption1'] ?? ''),
    ]);
    return isset($r['ok']) ? 'success' : ($r['error'] ?? 'Create failed (HTTP ' . ($r['_http'] ?? '?') . ')');
}

function karnakpanel_SuspendAccount(array $params) {
    $r = karnakpanel_api($params + ['_method' => 'POST'], 'suspend', ['username' => strtolower(trim($params['username'] ?? ''))]);
    return isset($r['ok']) ? 'success' : ($r['error'] ?? 'Suspend failed');
}

function karnakpanel_UnsuspendAccount(array $params) {
    $r = karnakpanel_api($params + ['_method' => 'POST'], 'unsuspend', ['username' => strtolower(trim($params['username'] ?? ''))]);
    return isset($r['ok']) ? 'success' : ($r['error'] ?? 'Unsuspend failed');
}

function karnakpanel_TerminateAccount(array $params) {
    $r = karnakpanel_api($params + ['_method' => 'POST'], 'terminate', ['username' => strtolower(trim($params['username'] ?? ''))]);
    return isset($r['ok']) ? 'success' : ($r['error'] ?? 'Terminate failed');
}

function karnakpanel_ChangePassword(array $params) {
    $r = karnakpanel_api($params + ['_method' => 'POST'], 'password', [
        'username' => strtolower(trim($params['username'] ?? '')),
        'password' => (string)($params['password'] ?? ''),
    ]);
    return isset($r['ok']) ? 'success' : ($r['error'] ?? 'Password change failed');
}

function karnakpanel_UsageUpdate(array $params) {
    $r = karnakpanel_api($params, 'usage', ['username' => strtolower(trim($params['username'] ?? ''))]);
    if (!isset($r['ok'])) return [];
    return [
        'diskusage' => (float)($r['disk_used_mb'] ?? 0),
        'disklimit' => (float)($r['disk_quota_mb'] ?? 0),
        'bwusage' => (float)($r['bw_month_mb'] ?? 0),
        'bwlimit' => (float)(($r['bw_quota_gb'] ?? 0) * 1024),
    ];
}

function karnakpanel_AdminCustomButtonArray() {
    return ['Usage & status' => 'usageStatus', 'List packages' => 'listPackages'];
}

function karnakpanel_listPackages(array $params) {
    $r = karnakpanel_api($params, 'packages');
    if (!isset($r['ok'])) return ['error' => $r['error'] ?? 'Lookup failed'];
    $lines = [];
    foreach ((array)($r['packages'] ?? []) as $p) {
        $lines[] = "{$p['name']}: {$p['disk_quota_mb']}MB disk, {$p['bandwidth_quota_gb']}GB BW, {$p['max_domains']} domains, {$p['max_emails']} mail, {$p['max_dbs']} DBs, {$p['max_ftp']} FTP, PHP {$p['php_version']}";
    }
    return ['success' => $lines ? implode("\n", $lines) : 'No packages defined in panel'];
}

function karnakpanel_usageStatus(array $params) {
    $r = karnakpanel_api($params, 'usage', ['username' => strtolower(trim($params['username'] ?? ''))]);
    return isset($r['ok'])
        ? ['success' => "Status: {$r['status']} · Disk: {$r['disk_used_mb']}/{$r['disk_quota_mb']} MB · BW: {$r['bw_month_mb']} MB / {$r['bw_quota_gb']} GB"]
        : ['error' => $r['error'] ?? 'Lookup failed'];
}
