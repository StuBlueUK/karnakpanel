<h2>🔌 API tokens <small style="color:var(--muted)">reseller+ API</small></h2>
<div class="card" style="max-width:720px"><h3>New token</h3>
<form class="inline" method="post" action="/api-tokens/mint">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<input name="label" placeholder="label, e.g. billing" required>
<select name="days" title="Token lifetime"><option value="0">never expires</option><option value="30">30 days</option><option value="90">90 days</option><option value="365">1 year</option></select>
<button class="btn">Mint</button></form>
<small>Use: <code>curl -H "X-API-KEY: &lt;token&gt;" <?=Helpers::e(Config::get('app.url',''))?>/api/accounts</code> · scope follows your role (resellers see own clients).</small></div>
<div class="card" style="max-width:720px"><h3>Your tokens (<?=count($tokens)?>)</h3>
<table><tr><th>Token</th><th>Access</th><th></th></tr>
<?php foreach($tokens as $t):?><tr><td><b><?=Helpers::e($t['label'])?></b><br><small style="color:var(--muted)">created <?=Helpers::e($t['created_at'])?> · last used <?=!empty($t['last_used_at']) ? Helpers::e($t['last_used_at']).' from '.Helpers::e($t['last_used_ip'] ?? '?') : 'never'?><?php if(!empty($t['expires_at'])):?> · <?=strtotime($t['expires_at']) < time() ? '<b style="color:var(--bad,#c33)">expired</b>' : 'expires '.Helpers::e($t['expires_at'])?><?php endif;?></small></td>
<td><?=!empty($t['allowed_ip']) ? '<span class="pill">📍 '.Helpers::e($t['allowed_ip']).'</span>' : '<span class="pill">any host</span>'?></td>
<td style="white-space:nowrap"><button class="btn small" data-pin="<?=$t['id']?>" data-ip="<?=Helpers::e($t['allowed_ip'] ?? '')?>" title="Pin to one IP (blank = any host)">Pin</button>
<form method="post" action="/api-tokens/revoke" style="display:inline" onsubmit="return confirm('Revoke token <?=Helpers::e($t['label'])?>? Calls using it fail immediately.')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=$t['id']?>"><button class="btn small danger">Revoke</button></form></td></tr><?php endforeach;?>
<?php if(empty($tokens)):?><tr><td colspan="3" class="empty">No tokens yet — mint one above.</td></tr><?php endif;?></table>
<?php $pagerBase = '/api-tokens?'; $pagerLabel = 'tokens'; require __DIR__.'/_pager.php'; ?></div>
<form id="pinform" method="post" action="/api-tokens/pin" style="display:none"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" id="pinid"><input type="hidden" name="ip" id="pinip"></form>
<script>
document.querySelectorAll('[data-pin]').forEach(function(b){
  b.addEventListener('click', function(){
    var cur = b.getAttribute('data-ip') || '';
    var n = prompt('Pin this token to one IP (blank = any host):', cur);
    if (n === null) return;
    document.getElementById('pinid').value = b.getAttribute('data-pin');
    document.getElementById('pinip').value = n.trim();
    document.getElementById('pinform').submit();
  });
});
</script>
<div class="card" style="max-width:720px"><h3>🧾 HostBMS billing <small style="color:var(--muted)">free billing system</small></h3>
<p style="margin:6px 0;line-height:1.6"><a href="https://hostbms.com" target="_blank" rel="noopener"><b>HostBMS.com</b></a> is a free billing system for hosting providers. A full working module for managing KarnakPanel is available — connect it with an API token minted above.</p>
<a class="btn small" href="https://hostbms.com" target="_blank" rel="noopener">Visit HostBMS.com ↗</a></div>
<?php if(($user['role'] ?? '') === 'admin'):?><div class="card" style="max-width:720px"><h3>💳 WHMCS billing <small style="color:var(--muted)">auto create / suspend / terminate</small></h3>
<details><summary style="cursor:pointer;color:var(--muted)">Setup guide (3 steps)</summary>
<ol style="margin:8px 0;padding-left:20px;line-height:1.9">
<li>Mint a token above labelled <code>whmcs</code> (admin tokens only).</li>
<li>Copy <code>whmcs/modules/servers/karnakpanel/</code> from the release zip into your WHMCS <code>modules/servers/</code>.</li>
<li>WHMCS → Servers → Add: type KarnakPanel, hostname = this panel, password = the token. Product → Module Settings → Panel package = exact package name (use the <b>List packages</b> admin button in WHMCS to copy it verbatim).</li>
</ol>
<small style="color:var(--muted)">Terminate deletes sites, mail, DBs and DNS (file backups kept). Bad tokens get 401 + fail2ban. Pin the <code>whmcs</code> token to your WHMCS server's IP above — a stolen token then works nowhere else.</small></details></div><?php endif;?>
