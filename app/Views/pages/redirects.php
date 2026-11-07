<h2>🌐 Domains</h2>
<?php $tab = 'redirects'; require __DIR__.'/_domaintabs.php'; ?>
<div class="card"><h3>Create redirect</h3>
<form class="inline" method="post" action="/redirects/create">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<label style="display:flex;flex-direction:column;gap:2px;font-size:12px;color:var(--muted)">Domain<select name="domain_id" required><?php foreach($domains as $d):?><option value="<?=$d['id']?>"><?=Helpers::e($d['domain'])?></option><?php endforeach;?></select></label>
<label style="display:flex;flex-direction:column;gap:2px;font-size:12px;color:var(--muted)">Source path<input name="source" type="text" value="/" placeholder="/old-page" style="min-width:150px" required></label>
<label style="display:flex;flex-direction:column;gap:2px;font-size:12px;color:var(--muted)">Target URL<input name="target" type="url" placeholder="https://example.com/new" style="min-width:230px" required></label>
<label style="display:flex;flex-direction:column;gap:2px;font-size:12px;color:var(--muted)">Type<select name="code"><option value="301">301 Permanent</option><option value="302">302 Temporary</option></select></label>
<button class="btn small">Add redirect</button></form>
<small style="color:var(--muted)">Stored as a rewrite rule (OLS ignores .htaccess) — same engine as imported rules, mixed freely.</small></div>
<div class="card"><h3>Existing redirects (<?=count($redirects)?>)</h3>
<table><tr><th>Domain</th><th>Source</th><th>Target</th><th>Type</th><th></th></tr>
<?php foreach($redirects as $r):$d=null; foreach($domains as $x){ if((int)$x['id']===$r['domain_id']){$d=$x;break;} }?><tr><td><?=Helpers::e($d['domain'])?></td><td><code><?=Helpers::e($r['source'])?></code></td><td><a href="<?=Helpers::e($r['target'])?>" target="_blank" rel="noopener"><?=Helpers::e($r['target'])?> ↗</a></td><td><span class="pill <?= $r['code'] === 301 ? 'ok' : 'warn'?>"><?=$r['code']?></span></td><td><form method="post" action="/redirects/delete" onsubmit="return confirm('Remove this redirect?')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="domain_id" value="<?=$r['domain_id']?>"><input type="hidden" name="line" value="<?=$r['line']?>"><button class="btn small" title="Remove redirect">✕</button></form></td></tr><?php endforeach;?>
<?php if(empty($redirects)):?><tr><td colspan="5" style="color:var(--muted)">No redirects yet.</td></tr><?php endif;?>
</table></div>
