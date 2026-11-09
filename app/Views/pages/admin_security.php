<?php
$csrf = Helpers::csrf();
// ok = the plain pill: only what needs action is coloured
$pill = ['ok' => ['', '✓ ok'], 'warn' => ['warn', '! improve'], 'bad' => ['err', '✗ fix'], 'unknown' => ['', '? unknown']];
$tone = $score >= 85 ? 'ok' : ($score >= 60 ? 'warn' : 'err');
?>
<div class="fwhead"><h2>🩺 Security Advisor</h2>
<span class="pill <?= $tone ?>" title="ok = full points, improve = half, fix and unknown = none. Weighted: SSH root login, firewall, fail2ban and admin 2FA count double.">Score <?= (int)$score ?> / 100</span></div>

<div class="fwtiles">
<div class="fwtile"><small>Fix</small><b><?= (int)($counts['bad'] ?? 0) ?></b></div>
<div class="fwtile"><small>Improve</small><b><?= (int)($counts['warn'] ?? 0) ?></b></div>
<div class="fwtile"><small>Unknown</small><b><?= (int)($counts['unknown'] ?? 0) ?></b></div>
<div class="fwtile"><small>OK</small><b><?= (int)($counts['ok'] ?? 0) ?></b></div>
</div>

<div class="card"><h3>Checks <small class="muted">read live on every page load. Unknown means the check couldn't run, and it scores no points.</small></h3>
<table class="list"><thead><tr><th></th><th>Check</th><th>Finding</th><th></th></tr></thead><tbody>
<?php foreach ($checks as $c): [$cls, $lab] = $pill[$c['status']]; ?><tr>
<td style="white-space:nowrap"><span class="pill <?= $cls ?>"><?= $lab ?></span></td>
<td><b><?= Helpers::e($c['title']) ?></b></td>
<td><?= Helpers::e($c['detail']) ?></td>
<td style="white-space:nowrap"><?php if ($c['key'] === 'noexec' && $noexecOff > 0): ?><form method="post" action="/admin/security/noexec-all" style="display:inline" data-wait="Updating <?= (int)$noexecOff ?> site(s)…" data-wait-note="Adding the upload block to each site, then one web server reload." onsubmit="return confirm('Block PHP in the upload folders of <?= (int)$noexecOff ?> site(s)? A plugin that runs its own PHP from an upload folder would stop working. Each site can switch it off again under Domains.')"><input type="hidden" name="csrf" value="<?= $csrf ?>"><button class="btn small">Turn on for all</button></form> <?php endif; ?><?php if ($c['key'] === 'headers' && $headersOff > 0): ?><form method="post" action="/admin/security/headers-all" style="display:inline" data-wait="Updating <?= (int)$headersOff ?> site(s)…" data-wait-note="Adding the safe headers to each site, then one web server reload." onsubmit="return confirm('Send the safe security headers on <?= (int)$headersOff ?> site(s), domains and subdomains? (nosniff, Referrer-Policy, and X-Frame-Options SAMEORIGIN, which stops other sites showing yours in a frame.) Sites with a trusted certificate also get the HTTPS redirect. HSTS and CSP are not touched. Each site can switch them off again under Domains.')"><input type="hidden" name="csrf" value="<?= $csrf ?>"><button class="btn small">Turn on for all</button></form> <?php endif; ?><?php if ($c['status'] !== 'ok'): ?><a class="btn small ghost" href="<?= Helpers::e($c['fix']) ?>">Fix →</a><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div>

<div class="card"><details><summary style="cursor:pointer"><b>Known limits</b> <small class="muted">by design. No setting changes these, so they are not scored. Click to show.</small></summary>
<ul>
<li>The panel's own worker (<code>karnak-panel</code>) can read every account's home: it manages their files.</li>
<li>The web server (<code>nobody</code>) can read every site's docroot, including config files such as <code>wp-config.php</code>.</li>
<li>An account's cron jobs and background apps are not yet inside its PHP CPU/RAM caps.</li>
<li>PHP's <code>open_basedir</code> doesn't bind native code (extensions, <code>exec</code>'d programs). Account separation comes from Unix users and file permissions.</li>
<li>PHP-FPM runs in a systemd sandbox. The web server and the panel do not, because the panel's privileged scripts would inherit it.</li>
<li>Blocking PHP in upload folders and outbound SMTP limit what a webshell can do. They don't remove one: keep WordPress and plugins updated.</li>
</ul></details></div>
