<h2>🌐 Domains</h2>
<div class="tabs">
<a class="tab<?=$tab==='domains'?' active':''?>" href="/domains">Domains<?php if($total > 0):?> <span class="count"><?=$total?></span><?php endif;?></a>
<a class="tab<?=$tab==='subdomains'?' active':''?>" href="/domains?tab=subdomains">Subdomains<?php if(count($subs) > 0):?> <span class="count"><?=count($subs)?></span><?php endif;?></a>
</div>
<?php if($tab === 'subdomains'): ?>
<div class="card"><h3>Create subdomain</h3>
<form class="inline" method="post" action="/subdomains/create">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<label style="display:flex;flex-direction:column;gap:2px;font-size:12px;color:var(--muted)">Subdomain name<input name="subdomain" type="text" placeholder="blog" style="min-width:140px;max-width:220px" pattern="[a-z0-9][a-z0-9-]{0,62}" title="lowercase letters, digits, hyphens" required></label>
<label style="display:flex;flex-direction:column;gap:2px;font-size:12px;color:var(--muted)">Parent domain<select name="parent_domain" required><?php foreach($parents as $p):?><option value="<?=Helpers::e($p['domain'])?>"><?=Helpers::e($p['domain'])?></option><?php endforeach;?></select></label>
<button class="btn small" style="align-self:flex-end">Add subdomain</button></form>
<?php if(empty($parents)):?><small style="color:var(--muted)">Add a domain first — subdomains hang off an existing one.</small><?php endif;?>
</div>
<div class="card"><h3>Existing subdomains (<?=count($subs)?>)</h3>
<table class="list"><tr><th>Subdomain</th><th>Parent</th><th>PHP</th><th>SSL</th><th>Docroot</th><th>Created</th><th></th></tr>
<?php foreach($subs as $s):?><tr><td><a class="domlink" href="<?=!empty($s['ssl_enabled'])?'https://':'http://'?><?=Helpers::e($s['domain'])?>" target="_blank" rel="noopener" title="Open site in new window"><b><?=Helpers::e($s['domain'])?></b> <small>↗</small></a><?php if(!empty($s['has_rules'])):?> <a class="pill <?=!empty($s['rules_stale'])?'warn':''?>" href="/domains?tab=subdomains&amp;rewrite=<?=$s['id']?>" title="<?=!empty($s['rules_stale'])?'The docroot .htaccess changed since import — re-import to pick it up':'Custom rewrite rules active (click to edit)'?>">📝 rewrites<?=!empty($s['rules_stale'])?' — stale':''?></a><?php endif;?></td><td><?=Helpers::e($s['parent_domain'])?></td><td><span class="badge"><?=Helpers::e($s['php_version'])?></span></td><td><?php if(!empty($s['ssl_enabled'])):?><span class="badge" title="Let's Encrypt">🔒 SSL</span><?php else:?><form method="post" action="/ssl/issue" style="display:inline" onsubmit="if(!confirm('Issue Let\'s Encrypt for <?=Helpers::e($s['domain'])?>? Requires DNS pointing here.'))return false;var b=this.querySelector('button');b.disabled=true;b.textContent='⏳ Issuing…';return true"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=$s['id']?>"><input type="hidden" name="back" value="subdomains"><button class="btn small" title="Issue Let's Encrypt (uses domain owner's account email)">🔒 Issue SSL</button></form><?php endif;?></td><td><small class="clip" title="<?=Helpers::e($s['docroot'])?>"><?=Helpers::e($s['docroot'])?></small></td><td><?=Helpers::e($s['created_at'])?></td><td style="white-space:nowrap"><a class="btn small" href="/domains?tab=subdomains&amp;rewrite=<?=$s['id']?>" title="Rewrite rules + .htaccess import (OLS ignores .htaccess)">📝</a><form method="post" action="/subdomains/delete" style="display:inline" onsubmit="return confirm('Delete <?=Helpers::e($s['domain'])?> and its docroot?')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=$s['id']?>"><input type="hidden" name="confirm" value="DELETE"><button class="btn small" title="Remove subdomain">✕</button></form></td></tr><?php endforeach;?>
<?php if(empty($subs)):?><tr><td colspan="7" style="color:var(--muted)">No subdomains yet.</td></tr><?php endif;?>
</table></div>
<?php require __DIR__.'/_rewrite_editor.php'; ?>
<?php else: ?>
<div class="card"><form method="get" action="/domains" class="inline">
<input name="q" value="<?=Helpers::e($q ?? '')?>" placeholder="search domain" style="min-width:200px"> <button class="btn small">Search</button><?php if(($q ?? '')!==''):?> <a class="btn small" href="/domains">Clear (<?=Helpers::e((string)($total ?? 0))?> match)</a><?php endif;?></form></div>
<div class="card"><form class="inline" method="post" action="/domains/create">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<input name="domain" placeholder="example.com" required>
<select name="php_version"><?=PhpVersions::options()?></select>
<button class="btn">Add domain</button></form>
<small style="color:var(--muted)">Subdomains go on the Subdomains tab — typing one here is refused.</small></div>
<div class="card"><table class="list"><tr><th>Domain</th><th>User</th><th>Docroot</th><th>PHP</th><th>SSL</th><th>Expiry</th><th></th></tr>
<?php foreach($domains as $d):?><tr><td><a class="domlink" href="<?=!empty($d['ssl_enabled'])?'https://':'http://'?><?=Helpers::e($d['domain'])?>" target="_blank" rel="noopener" title="Open site in new window"><b><?=Helpers::e($d['domain'])?></b> <small>↗</small></a><?php if(!empty($d['maintenance'])):?> <span class="pill warn">🚧 maintenance</span><?php endif;?><?php if(isset($d['waf']) && empty($d['waf'])):?> <span class="pill err">🛡️ shield off</span><?php endif;?><?php if(($d['mail_handling'] ?? 'local') === 'remote'):?> <span class="pill" title="Inbound mail routes to your external provider by public MX — local mailboxes dormant">📮 remote mail</span><?php endif;?><?php if(!empty($d['has_rules'])):?> <a class="pill <?=!empty($d['rules_stale'])?'warn':''?>" href="/domains?rewrite=<?=$d['id']?>" title="<?=!empty($d['rules_stale'])?'The docroot .htaccess changed since import — re-import to pick it up':'Custom rewrite rules active (click to edit) '?>">📝 rewrites<?=!empty($d['rules_stale'])?' — stale':''?></a><?php endif;?></td><td><?=Helpers::e($d['username']??'')?></td><td><small class="clip" title="<?=Helpers::e($d['docroot'])?>"><?=Helpers::e($d['docroot'])?></small></td><td><span class="badge"><?=Helpers::e($d['php_version'])?></span></td><td><?=!empty($d['ssl_enabled'])?'<span class="badge">SSL Applied</span>':'<span style="color:var(--muted)">—</span>'?></td>
<td><small><?php
$exp = [];
foreach ([['dom_expires','dom'],['ssl_expires','ssl']] as [$col,$lbl]) {
    if (empty($d[$col])) continue;
    $days = (int)floor((strtotime($d[$col]) - time()) / 86400);
    $t = "$lbl {$days}d";
    if ($days <= 7) $t = '<span class="pill mini err" style="min-width:78px;text-align:center">🔴 '.$t.'</span>'; elseif ($days <= 30) $t = '<span class="pill mini warn" style="min-width:78px;text-align:center">🟡 '.$t.'</span>';
    else $t = '<span class="pill mini" style="min-width:78px;text-align:center">'.$t.'</span>';
    $exp[] = $t;
}
echo $exp ? implode('<br>', $exp) : '—';
?></small></td>
<td style="white-space:nowrap"><a class="btn small" href="/domains?rewrite=<?=$d['id']?>" title="Rewrite rules + .htaccess import (OLS ignores .htaccess)">📝</a><form method="post" action="/domains/maintenance" style="display:inline"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=$d['id']?>"><button class="btn small" title="<?=!empty($d['maintenance'])?'Restore site':'Park behind holding page'?>"><?=!empty($d['maintenance'])?'Restore':'🚧 Maintain'?></button></form>
<form method="post" action="/domains/waf" style="display:inline"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=$d['id']?>"><button class="btn small" title="<?=!empty($d['waf'] ?? 1)?'Turn bot shield off (unfiltered)':'Turn bot shield on (blocks scan traffic)'?>"><?=!empty($d['waf'] ?? 1)?'🛡️':'🚫'?></button></form>
<form method="post" action="/domains/mail-routing" style="display:inline" onsubmit="return confirm('<?=((($d['mail_handling'] ?? 'local') === 'remote') ? 'Deliver mail LOCALLY for ' : 'Route mail to your EXTERNAL provider for ')?><?=Helpers::e($d['domain'])?>?\n<?=((($d['mail_handling'] ?? 'local') === 'remote') ? 'Dormant mailboxes reactivate.' : 'Local mailboxes go dormant. The domain MX must point at the provider.')?>')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=$d['id']?>"><button class="btn small" title="<?=((($d['mail_handling'] ?? 'local') === 'remote') ? 'Mail routes externally — click to deliver locally' : 'Mail delivers locally — click to route to external provider')?>"><?=((($d['mail_handling'] ?? 'local') === 'remote') ? '📮 Remote' : '📬 Local')?></button></form>
<?php if(empty($d['ssl_enabled'])):?><form method="post" action="/ssl/issue" style="display:inline" onsubmit="if(!confirm('Issue Let\'s Encrypt for <?=Helpers::e($d['domain'])?>? Requires DNS pointing here.'))return false;var b=this.querySelector('button');b.disabled=true;b.textContent='⏳ Issuing… (up to a minute)';return true"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=$d['id']?>"><button class="btn small" title="Issue Let's Encrypt (uses domain owner's account email)">🔒 Issue SSL</button></form><?php endif;?>
<form method="post" action="/domains/delete" style="display:inline" onsubmit="return confirm('Remove <?=Helpers::e($d['domain'])?>?\nVhost, DNS zone, mailboxes and site files go too.')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=$d['id']?>"><button class="btn small danger">Delete</button></form></td></tr><?php endforeach;?>
<?php if(empty($domains)):?><tr><td colspan="7" style="color:var(--muted)">No domains yet — add one above. On production this also creates the BIND zone + LiteSpeed vhost.</td></tr><?php endif;?></table>
<?php $pagerBase = '/domains?'.(($q ?? '') !== '' ? 'q='.urlencode($q).'&' : ''); $pagerLabel = 'domains'; require __DIR__.'/_pager.php'; ?></div>
<?php require __DIR__.'/_rewrite_editor.php'; ?>
<div class="card"><h3>🅿️ Parked domains <small style="color:var(--muted)">extra names showing the same site — web-only, no mailboxes</small></h3>
<form class="inline" method="post" action="/domains/alias-create">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<select name="domain_id"><?php foreach($domains as $d):?><option value="<?=$d['id']?>">→ <?=Helpers::e($d['domain'])?></option><?php endforeach;?></select>
<input name="alias" placeholder="parked-name.com" required>
<button class="btn">Park domain</button></form>
<table class="list"><tr><th>Alias</th><th>Points to</th><th></th></tr>
<?php foreach($aliases ?? [] as $a):?><tr><td><?=Helpers::e($a['alias'])?></td><td><?=Helpers::e($a['domain'])?></td>
<td><form method="post" action="/domains/alias-delete" style="display:inline" onsubmit="return confirm('Unpark <?=Helpers::e($a['alias'])?>?')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=$a['id']?>"><button class="btn small danger">Unpark</button></form></td></tr><?php endforeach;?>
<?php if(empty($aliases ?? [])):?><tr><td colspan="3" style="color:var(--muted)">No parked domains.</td></tr><?php endif;?></table></div>
<div class="card"><h3>👁️ Preview URLs <small style="color:var(--muted)">unguessable links that show a site before DNS moves — HTTP only, same vhost</small></h3>
<form class="inline" method="post" action="/domains/preview-create">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<select name="domain_id"><?php foreach($domains as $d):?><option value="<?=$d['id']?>">→ <?=Helpers::e($d['domain'])?></option><?php endforeach;?></select>
<button class="btn">Enable preview</button></form>
<table class="list"><tr><th>URL</th><th>Domain</th><th></th></tr>
<?php foreach($previews ?? [] as $v):?><tr><td><a href="http://preview-<?=Helpers::e($v['token'])?>.<?=Helpers::e($v['domain'])?>/" target="_blank" rel="noopener">preview-<?=Helpers::e(substr($v['token'],0,8))?>…</a></td><td><?=Helpers::e($v['domain'])?></td>
<td><form method="post" action="/domains/preview-delete" style="display:inline" onsubmit="return confirm('Revoke this preview URL?')"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=$v['id']?>"><button class="btn small danger">Revoke</button></form></td></tr><?php endforeach;?>
<?php if(empty($previews ?? [])):?><tr><td colspan="3" style="color:var(--muted)">No preview URLs. Needs the zone hosted here (wildcard added automatically).</td></tr><?php endif;?></table></div>
<?php endif; ?>
