<h2>🧰 Software <small style="color:var(--muted)">one-click installs</small></h2>
<div class="grid">
<?php foreach($apps as $key=>$a):?>
<div class="tool"><div class="ic" style="font-size:40px"><?=Helpers::e($a['icon'] ?? '📦')?></div><h3><?=Helpers::e($a['name'])?></h3><p style="margin-bottom:16px"><?=Helpers::e($a['desc'])?></p>
<?php if(empty($a['unavailable'])):?><button class="btn small" style="margin-top:4px" onclick="document.getElementById('sw-app').value='<?=Helpers::e($key)?>';document.getElementById('sw-form').scrollIntoView({behavior:'smooth'})">Install →</button><?php endif;?></div>
<?php endforeach;?>
</div>
<div class="card" id="sw-form" style="margin-top:14px"><h3>Install <small style="color:var(--muted)">needs an empty folder + one database (created automatically)</small></h3>
<small style="color:var(--muted)">Vvveb note: the newsletter plugin is no longer in upstream's release zip — fetch it free from plugins.vvveb.com (marketplace account) and enable it in the Vvveb admin.</small>
<form class="inline" method="post" action="/software/install">
<input type="hidden" name="csrf" value="<?=Helpers::csrf()?>">
<select name="app" id="sw-app"><?php foreach($apps as $key=>$a):?><?php if(empty($a['unavailable'])):?><option value="<?=Helpers::e($key)?>"><?=Helpers::e($a['name'])?></option><?php endif;?><?php endforeach;?></select>
<select name="domain_id"><?php foreach($domains as $d):?><option value="<?=$d['id']?>"><?=Helpers::e($d['domain'])?></option><?php endforeach;?></select>
<input name="subdir" placeholder="folder (empty = domain root)" style="max-width:190px">
<input name="title" placeholder="Site title" style="max-width:160px">
<input name="admin_email" type="email" placeholder="admin email (Vvveb only)" autocomplete="off" style="max-width:170px">
<input name="admin_user" placeholder="admin user" autocomplete="off" style="max-width:120px">
<input name="admin_pass" type="password" placeholder="admin pass 10+" autocomplete="new-password" style="max-width:130px">
<button class="btn">Install now</button></form></div>
<div class="card"><h3>Installed</h3><table><tr><th>App</th><th>URL</th><th>Date</th><th>Auto-updates</th></tr>
<?php foreach($installs as $s):?><tr><td><?=Helpers::e($s['app'])?></td><td><a class="domlink" href="<?=Helpers::e($s['url'])?>" target="_blank" rel="noopener" title="Open site in new window"><b><?=Helpers::e($s['url'])?></b> <small>↗</small></a></td><td><?=Helpers::e($s['created_at'])?></td><td><?php if(($s['app'] ?? '') === 'wordpress'):?><?php $st = ($auto ?? [])[$s['id']] ?? 'off'; ?>
<form class="inline" method="post" action="/software/autoupdate" style="display:inline"><input type="hidden" name="csrf" value="<?=Helpers::csrf()?>"><input type="hidden" name="id" value="<?=$s['id']?>"><input type="hidden" name="on" value="<?=$st === 'on' ? '0' : '1'?>"><button class="btn small" title="Minor core releases + plugins update themselves; themes + major versions stay manual. Content, uploads and settings are never touched. Keep scheduled backups on as the safety net."><?=$st === 'on' ? '✅ on' : '❌ off'?></button></form>
<?php else:?><span style="color:var(--muted)">—</span><?php endif;?></td></tr><?php endforeach;?>
<?php if(empty($installs)):?><tr><td colspan="4" style="color:var(--muted)">Nothing installed yet.</td></tr><?php endif;?></table>
<small style="color:var(--muted)">Auto-updates: minor WordPress core + plugins only — themes and major versions stay manual, content/uploads/settings untouched. Scheduled backups remain the safety net.</small>
<?php $pagerBase = '/software?'; $pagerLabel = 'installs'; require __DIR__.'/_pager.php'; ?></div>
